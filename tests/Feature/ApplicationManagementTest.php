<?php

declare(strict_types=1);

use App\Enums\CaptureSeverity;
use App\Livewire\Applications\Create;
use App\Livewire\Applications\Show;
use App\Models\Application;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use Illuminate\Database\QueryException;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\User;

it('stores the complete application policy behind an opaque public route key', function (): void {
    $application = Application::factory()->create([
        'allowed_origins' => ['https://app.example.com', 'http://localhost:8000'],
        'severity' => CaptureSeverity::AllText,
        'mask_selectors' => ['.account-number'],
        'block_selectors' => ['.payment-card'],
        'excluded_paths' => ['/billing/*'],
        'sampling_percent' => 35,
        'ingest_enabled' => false,
    ]);

    expect($application->public_id)
        ->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->not->toBe((string) $application->id)
        ->and($application->getRouteKeyName())->toBe('public_id')
        ->and($application->allowed_origins)->toBe(['https://app.example.com', 'http://localhost:8000'])
        ->and($application->severity)->toBe(CaptureSeverity::AllText)
        ->and($application->mask_selectors)->toBe(['.account-number'])
        ->and($application->block_selectors)->toBe(['.payment-card'])
        ->and($application->excluded_paths)->toBe(['/billing/*'])
        ->and($application->sampling_percent)->toBe(35)
        ->and($application->ingest_enabled)->toBeFalse();

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/applications/'.$application->id)
        ->assertNotFound();

    $this->get(route('admin.applications.show', $application))->assertOk();
});

it('guards application management routes with package authentication', function (): void {
    $application = Application::factory()->create();
    $adminRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), 'admin.applications.'))
        ->values();

    expect($adminRoutes->pluck('action.as')->all())->toEqualCanonicalizing([
        'admin.applications.index',
        'admin.applications.create',
        'admin.applications.show',
    ]);

    foreach ($adminRoutes as $route) {
        expect($route->gatherMiddleware())->toContain('bfc.auth')->not->toContain('admin');
    }

    $this->get(route('admin.applications.show', $application))->assertRedirect(route('bfc.login'));
});

it('creates an application and displays its enrollment code exactly once', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(Create::class)
        ->set('form.name', 'Storefront')
        ->set('form.allowedOrigins', "https://store.example.com\nhttp://localhost:8000")
        ->set('form.samplingPercent', 25)
        ->call('save')
        ->assertHasNoErrors();

    $application = Application::query()->sole();
    $credential = Credential::query()->where('subject_ref', 'application:'.$application->public_id)->sole();
    $code = session('enrollment.code');

    expect($code)->toBeString()->not->toBeEmpty()
        ->and($credential->status)->toBe(CredentialStatus::Pending)
        ->and($credential->toArray())->not->toContain($code);

    $firstDisplay = $this->get(route('admin.applications.show', $application))->assertOk();

    expect($firstDisplay->getContent())->toContain($code);

    $secondDisplay = $this->get(route('admin.applications.show', $application))->assertOk();

    expect($secondDisplay->getContent())->not->toContain($code);
});

it('rejects policy changes below the immutable inputs baseline', function (): void {
    $admin = User::factory()->admin()->create();
    $application = Application::factory()->create([
        'severity' => CaptureSeverity::Inputs,
    ]);

    $this->actingAs($admin);

    Livewire::test(Show::class, ['application' => $application])
        ->set('form.severity', 'none')
        ->call('updateApplication')
        ->assertHasErrors(['form.severity']);

    expect($application->refresh()->severity)->toBe(CaptureSeverity::Inputs);

    $this->get(route('admin.applications.show', $application))
        ->assertSee('always masked')
        ->assertDontSee('Disable masking');
});

it('prevents raw SQL from weakening capture severity below the inputs baseline', function (): void {
    $application = Application::factory()->create();

    expect(fn () => DB::update(
        "UPDATE applications SET severity = 'off' WHERE id = ?",
        [$application->id],
    ))->toThrow(QueryException::class);
});

it('constrains sampling percent at the database boundary', function (): void {
    $application = Application::factory()->create();

    expect(fn () => DB::update(
        'UPDATE applications SET sampling_percent = 101 WHERE id = ?',
        [$application->id],
    ))->toThrow(QueryException::class);
});

it('validates allowed origins as origins rather than arbitrary URLs', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(Create::class)
        ->set('form.name', 'Invalid origin')
        ->set('form.allowedOrigins', 'https://example.com/private?token=value')
        ->call('save')
        ->assertHasErrors(['allowed_origins.0']);

    expect(Application::query()->count())->toBe(0);
});

it('scopes credential mutations through their owning application', function (): void {
    $admin = User::factory()->admin()->create();
    $applicationA = Application::factory()->create();
    $applicationB = Application::factory()->create();
    $credentialB = activeReelCredential($applicationB);

    $this->actingAs($admin);

    Livewire::test(Show::class, ['application' => $applicationA])
        ->call('revokeCredential', $credentialB->id)
        ->assertNotFound();

    expect($credentialB->refresh()->status)->toBe(CredentialStatus::Active);
});

it('exposes application Livewire actions to Members', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test(Create::class)->assertOk();
});

it('allows Members to run every application management action', function (): void {
    $member = User::factory()->create();
    $application = Application::factory()->create();
    $credential = activeReelCredential($application);
    $actions = [
        'updateApplication' => [],
        'toggleIngest' => [],
        'issueCredential' => [],
        'rotateCredential' => [],
        'revokeCredential' => [$credential->id],
    ];
    $publicMethods = collect((new ReflectionClass(Show::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === Show::class)
        ->reject(fn (ReflectionMethod $method): bool => in_array($method->getName(), ['mount', 'render', 'application', 'credentials'], true))
        ->map(fn (ReflectionMethod $method): string => $method->getName())
        ->values()
        ->all();

    expect($publicMethods)->toEqualCanonicalizing(array_keys($actions));

    $this->actingAs($member);
    Livewire::test(Show::class, ['application' => $application])
        ->call('toggleIngest')
        ->assertHasNoErrors();
});

it('does not display an enrollment code after it expires', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(Create::class)
        ->set('form.name', 'Delayed setup')
        ->set('form.allowedOrigins', 'https://delayed.example.com')
        ->call('save')
        ->assertHasNoErrors();

    $application = Application::query()->sole();
    $code = session('enrollment.code');

    $this->travel(16)->minutes();

    $this->get(route('admin.applications.show', $application))
        ->assertOk()
        ->assertDontSeeText($code)
        ->assertSee('Enrollment code expired');
});

it('stores no private key column in package credential schema', function (): void {
    expect(Schema::getColumnListing('credentials'))
        ->each(fn ($column) => $column->not->toMatch('/private/i'));
});

it('allows overlapping credentials and revokes only the selected credential', function (): void {
    $admin = User::factory()->admin()->create();
    $application = Application::factory()->create();
    $first = activeReelCredential($application);
    $second = activeReelCredential($application);

    $this->actingAs($admin);

    Livewire::test(Show::class, ['application' => $application])
        ->call('revokeCredential', $first->id)
        ->assertHasNoErrors();

    expect($first->refresh()->status)->toBe(CredentialStatus::Active)
        ->and($first->revoked_at)->not->toBeNull()
        ->and($second->refresh()->status)->toBe(CredentialStatus::Active)
        ->and($second->revoked_at)->toBeNull()
        ->and(Credential::query()->count())->toBe(2)
        ->and(Application::query()->count())->toBe(1);
});
