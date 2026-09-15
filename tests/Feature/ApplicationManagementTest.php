<?php

declare(strict_types=1);

use App\Enums\CaptureSeverity;
use App\Livewire\Applications\Create;
use App\Livewire\Applications\Show;
use App\Models\Application;
use App\Services\ReelCredentialScope;
use ArtisanBuild\BuiltForCloud\Actions\CompleteAsymmetricEnrollment;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\Exceptions\AsymmetricEnrollmentUnavailable;
use ArtisanBuild\BuiltForCloud\OnboardingToken;
use ArtisanBuild\BuiltForCloud\Rs256PublicKey;
use ArtisanBuild\BuiltForCloud\SubjectType;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\User;

/** @param list<MessageLogged> $logs */
function expectEnrollmentCodeNotPersisted(string $code, array $logs): void
{
    expect(serialize(session()->all()))->not->toContain($code)
        ->and(serialize(enrollmentCacheBackingState()))->not->toContain($code)
        ->and(serialize($logs))->not->toContain($code);

    foreach (['applications', 'credentials', 'onboarding_tokens', 'credential_protocol_bindings', 'credential_audit_events', 'cache', 'jobs', 'failed_jobs'] as $table) {
        expect(serialize(DB::table($table)->get()->all()))->not->toContain($code);
    }
}

/** @return array<mixed> */
function enrollmentCacheBackingState(): array
{
    $store = Cache::store()->getStore();

    if ($store instanceof ArrayStore) {
        return (new ReflectionProperty($store, 'storage'))->getValue($store);
    }

    if (! $store instanceof RedisStore) {
        throw new RuntimeException('Enrollment persistence assertion does not support the configured cache store.');
    }

    $connection = $store->connection();

    if (! $connection instanceof PhpRedisConnection) {
        throw new RuntimeException('Enrollment persistence assertion does not support the configured Redis connection.');
    }

    $connectionPrefix = (string) $connection->_prefix('');
    $cachePrefix = $connectionPrefix.$store->getPrefix();
    $cursor = version_compare((string) phpversion('redis'), '6.1.0', '>=') ? null : '0';
    $initialCursor = $cursor;
    $keys = [];
    $iterations = 0;

    do {
        if (++$iterations > 1_000) {
            throw new RuntimeException('Enrollment persistence assertion exceeded its bounded Redis scan.');
        }

        $result = $connection->scan($cursor, ['match' => $cachePrefix.'*', 'count' => 1_000]);

        if ($result === false) {
            break;
        }

        if (! is_array($result) || count($result) !== 2 || ! is_array($result[1])) {
            throw new RuntimeException('Enrollment persistence assertion received an invalid Redis scan response.');
        }

        [$cursor, $batch] = $result;
        $keys = array_values(array_unique([...$keys, ...$batch]));

        if (count($keys) > 10_000) {
            throw new RuntimeException('Enrollment persistence assertion exceeded its bounded Redis key set.');
        }
    } while ((string) $cursor !== (string) $initialCursor);

    return array_map(static function (string $key) use ($connection, $connectionPrefix): ?string {
        if (! str_starts_with($key, $connectionPrefix)) {
            throw new RuntimeException('Enrollment persistence assertion received a Redis key outside its connection prefix.');
        }

        $value = $connection->get(substr($key, strlen($connectionPrefix)));

        if (! is_string($value) && $value !== null) {
            throw new RuntimeException('Enrollment persistence assertion could not read a Redis cache value.');
        }

        return $value;
    }, $keys);
}

it('inspects the configured cache backing state without assuming its driver', function (): void {
    Cache::put('enrollment-persistence-probe', 'test-created-cache-backing-value', 60);

    expect(serialize(enrollmentCacheBackingState()))->toContain('test-created-cache-backing-value');
});

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

it('renders test-created applications on the index for Members', function (): void {
    $application = Application::factory()->create([
        'name' => 'Member application '.fake()->uuid(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.applications.index'))
        ->assertOk()
        ->assertSeeText($application->name);
});

it('creates an application and displays its enrollment code exactly once', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $logs = [];
    Log::listen(static function (MessageLogged $event) use (&$logs): void {
        $logs[] = $event;
    });

    $component = Livewire::test(Create::class)
        ->set('form.name', 'Storefront')
        ->set('form.allowedOrigins', "https://store.example.com\nhttp://localhost:8000")
        ->set('form.samplingPercent', 25)
        ->call('save')
        ->assertHasNoErrors();

    $application = Application::query()->sole();
    $credential = Credential::query()->where('subject_ref', 'application:'.$application->public_id)->sole();
    $code = enrollmentCodeFromHtml($component->html());

    expect($code)->toBeString()->not->toBeEmpty()
        ->and($credential->status)->toBe(CredentialStatus::Pending)
        ->and($credential->toArray())->not->toContain($code)
        ->and(serialize($component->snapshot))->not->toContain($code)
        ->and($component->html())->toContain(route('admin.applications.show', $application));
    expectEnrollmentCodeNotPersisted($code, $logs);

    $component->call('$refresh');
    expect($component->html())->not->toContain($code);

    $this->get(route('admin.applications.show', $application))
        ->assertOk()
        ->assertDontSee($code);
});

it('reveals an issued enrollment code only in the immediate response', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $application = Application::factory()->create();
    $logs = [];
    Log::listen(static function (MessageLogged $event) use (&$logs): void {
        $logs[] = $event;
    });

    $component = Livewire::test(Show::class, ['application' => $application])
        ->call('issueCredential')
        ->assertHasNoErrors();
    $code = enrollmentCodeFromHtml($component->html());

    expect(serialize($component->snapshot))->not->toContain($code);
    expectEnrollmentCodeNotPersisted($code, $logs);
    $component->call('$refresh');
    expect($component->html())->not->toContain($code);
    $this->get(route('admin.applications.show', $application))->assertDontSee($code);
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

it('limits custom credential management to exact Reel signing credentials without generic self-service admission', function (): void {
    $member = User::factory()->create();
    $application = Application::factory()->create();
    $signing = activeReelCredential($application);
    $otherPurpose = Credential::query()->create([
        'kind' => CredentialKind::Bearer,
        'purpose' => CredentialPurpose::SystemDeployment,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'application:'.$application->public_id,
        'name' => 'not-reel-signing',
        'secret_hash' => hash('sha256', 'test-created-non-signing-secret'),
    ]);

    $this->actingAs($member);
    Livewire::test(Show::class, ['application' => $application])
        ->assertSee($signing->id)
        ->assertDontSee($otherPurpose->id)
        ->call('rotateCredential', $otherPurpose->id)
        ->assertNotFound();
    Livewire::test(Show::class, ['application' => $application])
        ->call('revokeCredential', $otherPurpose->id)
        ->assertNotFound();

    expect($otherPurpose->fresh()->rotated_at)->toBeNull()
        ->and($otherPurpose->fresh()->revoked_at)->toBeNull();
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
        'reissuePendingCredential' => [],
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

it('does not redisplay an enrollment code after its immediate response', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $component = Livewire::test(Create::class)
        ->set('form.name', 'Delayed setup')
        ->set('form.allowedOrigins', 'https://delayed.example.com')
        ->call('save')
        ->assertHasNoErrors();

    $application = Application::query()->sole();
    $code = enrollmentCodeFromHtml($component->html());

    $this->get(route('admin.applications.show', $application))
        ->assertOk()
        ->assertDontSeeText($code);
});

it('rotates an active credential with one immediate pending delivery', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $application = Application::factory()->create();
    $source = activeReelCredential($application);
    $logs = [];
    Log::listen(static function (MessageLogged $event) use (&$logs): void {
        $logs[] = $event;
    });

    $component = Livewire::test(Show::class, ['application' => $application])
        ->call('rotateCredential', $source->id)
        ->assertHasNoErrors();
    $code = enrollmentCodeFromHtml($component->html());
    $replacement = Credential::query()->whereKeyNot($source->id)->sole();

    expect($source->refresh()->status)->toBe(CredentialStatus::Active)
        ->and($source->rotated_at)->not->toBeNull()
        ->and($replacement->status)->toBe(CredentialStatus::Pending)
        ->and(serialize($component->snapshot))->not->toContain($code)
        ->and(OnboardingToken::query()->where('durable_credential_id', $replacement->id)->value('expires_at')->diffInSeconds(now(), true))->toBeLessThanOrEqual(900);
    expectEnrollmentCodeNotPersisted($code, $logs);
});

it('reissues lost pending delivery from its active predecessor exactly once', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $application = Application::factory()->create();
    $source = activeReelCredential($application);
    $logs = [];
    Log::listen(static function (MessageLogged $event) use (&$logs): void {
        $logs[] = $event;
    });
    $rotation = Livewire::test(Show::class, ['application' => $application])
        ->call('rotateCredential', $source->id);
    $abandonedCode = enrollmentCodeFromHtml($rotation->html());
    $abandoned = Credential::query()->whereKeyNot($source->id)->sole();

    $reissue = Livewire::test(Show::class, ['application' => $application])
        ->assertSee('Reissue pending code')
        ->call('reissuePendingCredential', $source->id)
        ->assertHasNoErrors();
    $replacementCode = enrollmentCodeFromHtml($reissue->html());

    expect($replacementCode)->not->toBe($abandonedCode)
        ->and($abandoned->refresh()->revoked_at)->not->toBeNull()
        ->and(serialize($reissue->snapshot))->not->toContain($replacementCode)
        ->and(Credential::query()->where('status', CredentialStatus::Pending)->whereNull('revoked_at')->count())->toBe(1);
    expectEnrollmentCodeNotPersisted($replacementCode, $logs);
    expect(fn () => resolve(CompleteAsymmetricEnrollment::class)(
        $abandonedCode,
        ReelCredentialScope::for($application),
        new Rs256PublicKey(testRsaKeyPair(fresh: true)['public']),
    ))->toThrow(AsymmetricEnrollmentUnavailable::class);

    Livewire::test(Show::class, ['application' => $application])
        ->call('reissuePendingCredential', $source->id)
        ->assertStatus(409);
    expect(Credential::query()->where('status', CredentialStatus::Pending)->whereNull('revoked_at')->count())->toBe(1);

    $reissue->call('$refresh');
    expect($reissue->html())->not->toContain($replacementCode);
});

it('does not describe or treat an initial pending credential as directly reissuable', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $application = Application::factory()->create();
    $pending = pendingReelCredential($application)['credential'];

    Livewire::test(Show::class, ['application' => $application])
        ->assertSee('This initial pending credential has no predecessor and cannot be reissued.')
        ->assertDontSee('Reissue pending code')
        ->call('reissuePendingCredential', $pending->id)
        ->assertStatus(409);

    expect($pending->refresh()->revoked_at)->toBeNull()
        ->and(Credential::query()->count())->toBe(1);
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
