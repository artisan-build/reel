<?php

declare(strict_types=1);

use App\Enums\CaptureSeverity;
use App\Enums\CredentialStatus;
use App\Livewire\Applications\Create;
use App\Livewire\Applications\Show;
use App\Models\Application;
use App\Models\ApplicationCredential;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

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

it('guards every named admin route from guests and non administrators', function (): void {
    $application = Application::factory()->create();
    $adminRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), 'admin.'))
        ->values();

    expect($adminRoutes->pluck('action.as')->all())->toEqualCanonicalizing([
        'admin.applications.index',
        'admin.applications.create',
        'admin.applications.show',
    ]);

    foreach ($adminRoutes as $route) {
        $parameters = in_array('application', $route->parameterNames(), true)
            ? ['application' => $application]
            : [];

        $this->get(route($route->getName(), $parameters))->assertRedirect(route('login'));
    }

    $this->actingAs(User::factory()->create());

    foreach ($adminRoutes as $route) {
        $parameters = in_array('application', $route->parameterNames(), true)
            ? ['application' => $application]
            : [];

        $this->get(route($route->getName(), $parameters))->assertForbidden();
    }
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
    $credential = $application->credentials()->sole();
    $code = session('enrollment.code');

    expect($code)->toBeString()->not->toBeEmpty()
        ->and($credential->enrollment_code_hash)->not->toBe($code)
        ->and(Hash::check($code, $credential->enrollment_code_hash))->toBeTrue()
        ->and($credential->toArray())->not->toHaveKey('enrollment_code_hash')
        ->and($credential->getAttribute('enrollment_code'))->toBeNull();

    $this->get(route('admin.applications.show', $application))
        ->assertOk()
        ->assertSee($code);

    $this->get(route('admin.applications.show', $application))
        ->assertOk()
        ->assertDontSee($code);
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
    $credentialB = ApplicationCredential::factory()->for($applicationB)->create();

    $this->actingAs($admin);

    expect(fn () => Livewire::test(Show::class, ['application' => $applicationA])
        ->call('revokeCredential', $credentialB->id))
        ->toThrow(ModelNotFoundException::class);

    expect($credentialB->refresh()->status)->toBeNull();
});

it('does not expose admin Livewire actions when instantiated by a non administrator', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test(Create::class)->assertForbidden();
});

it('stores no private or secret key column on application credentials', function (): void {
    expect(Schema::getColumnListing('application_credentials'))
        ->each(fn ($column) => $column->not->toMatch('/private|secret_key/i'));
});

it('allows overlapping credentials and revokes only the selected credential', function (): void {
    $admin = User::factory()->admin()->create();
    $application = Application::factory()->create();
    $first = ApplicationCredential::factory()->for($application)->create([
        'public_key' => testRsaKeyPair()['public'],
        'status' => CredentialStatus::Active,
        'enrollment_code_hash' => null,
        'enrolled_at' => now(),
    ]);
    $second = ApplicationCredential::factory()->for($application)->create([
        'public_key' => testRsaKeyPair()['public'],
        'status' => CredentialStatus::Active,
        'enrollment_code_hash' => null,
        'enrolled_at' => now(),
    ]);

    $this->actingAs($admin);

    Livewire::test(Show::class, ['application' => $application])
        ->call('revokeCredential', $first->id)
        ->assertHasNoErrors();

    expect($first->refresh()->status)->toBe(CredentialStatus::Revoked)
        ->and($first->revoked_at)->not->toBeNull()
        ->and($second->refresh()->isActive())->toBeTrue()
        ->and(ApplicationCredential::query()->count())->toBe(2)
        ->and(Application::query()->count())->toBe(1);
});

/**
 * @return array{public: string, private: string}
 */
function testRsaKeyPair(): array
{
    static $pair;

    if (is_array($pair)) {
        return $pair;
    }

    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    if ($key === false || ! openssl_pkey_export($key, $private)) {
        throw new RuntimeException('Unable to generate a test RSA key pair.');
    }

    $details = openssl_pkey_get_details($key);

    if ($details === false) {
        throw new RuntimeException('Unable to inspect the test RSA key pair.');
    }

    return $pair = ['public' => $details['key'], 'private' => $private];
}
