<?php

declare(strict_types=1);

use App\Enums\RecordingSessionStatus;
use App\Jobs\DeleteUserErasureBatch;
use App\Models\Application;
use App\Models\RecordingSession;
use App\Models\UserErasureAudit;
use ArtisanBuild\BuiltForCloud\AuthorityMode;
use ArtisanBuild\BuiltForCloud\Http\Middleware\EnsureUserIsAuthenticated;
use ArtisanBuild\BuiltForCloud\InstallationAuthority;
use ArtisanBuild\BuiltForCloud\ManagedAuthClient;
use ArtisanBuild\BuiltForCloud\ManagedHandoff;
use ArtisanBuild\BuiltForCloud\StandaloneAccess;
use ArtisanBuild\BuiltForCloud\Tests\Fixtures\ManagedAuthorityFixture;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Drawer\Utils;
use Livewire\LivewireManager;
use Tests\Support\User as UserFactory;

require_once __DIR__.'/../../vendor/artisan-build/built-for-cloud/tests/Fixtures/ManagedAuthorityFixture.php';

function configureReelManagedAuthority(int $generation = 7, ?string $secret = null): ManagedAuthorityFixture
{
    static $activeFixture;

    $secret ??= bin2hex(random_bytes(32));
    DB::table('bfc_authority')->where('key', InstallationAuthority::KEY)->update([
        'mode' => AuthorityMode::Managed->value,
        'generation' => $generation,
        'issuer' => 'https://issuer.example.test',
        'connection_id' => 'reel-connection-fixture',
        'organization_id' => 'reel-organization-fixture',
        'installation_id' => 'reel-installation-fixture',
        'authority_base_url' => 'https://authority.example.test',
    ]);
    config(['built-for-cloud.managed.client_secret' => $secret]);

    $activeFixture = new ManagedAuthorityFixture(
        'https://authority.example.test',
        $secret,
        'https://issuer.example.test',
        'reel-connection-fixture',
        'reel-organization-fixture',
        'reel-installation-fixture',
        $generation,
    );
    if ($generation === 7) {
        Http::fake(static function (ClientRequest $request) use (&$activeFixture): mixed {
            return $activeFixture->respond($request);
        });
    }

    return $activeFixture;
}

function enterReelManagedSession(
    ManagedAuthorityFixture $fixture,
    string $code,
    string $subject = 'subject-fixture',
): User {
    $handoff = beginReelManagedHandoff();
    test()->withSession([ManagedHandoff::SESSION_NONCE_KEY => $handoff['nonce']])
        ->get(route('bfc.managed.callback', [
            'state' => $handoff['state'],
            'code' => $code,
        ], absolute: false))
        ->assertRedirect('/');

    return User::query()->where('scalpels_id', $subject)->sole();
}

/** @return array{state: string, nonce: string, session_id: string} */
function beginReelManagedHandoff(): array
{
    $response = test()->get(route('bfc.managed.login', absolute: false))->assertRedirect();
    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
    $state = $query['state'] ?? null;
    $nonce = session(ManagedHandoff::SESSION_NONCE_KEY);

    expect($state)->toBeString()->toHaveLength(43)
        ->and($nonce)->toBeString()->toHaveLength(43);

    return [
        'state' => $state,
        'nonce' => $nonce,
        'session_id' => session()->getId(),
    ];
}

function reelManagedConfirmationCalls(ManagedAuthorityFixture $fixture): int
{
    return count(array_filter(
        $fixture->calls,
        static fn (array $call): bool => $call['path'] === '/managed-auth/v1/memberships/confirm',
    ));
}

/** @return array<string, mixed> */
function reelLivewireSnapshot(TestResponse $page): array
{
    return Utils::extractAttributeDataFromHtml($page->getContent(), 'wire:snapshot');
}

/** @param array<string, mixed> $snapshot */
function postReelLivewireUpdate(array $snapshot, string $method, array $params = []): TestResponse
{
    Auth::forgetGuards();

    return test()->postJson(resolve(LivewireManager::class)->getUpdateUri(), [
        'components' => [[
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'updates' => [],
            'calls' => [[
                'path' => '',
                'method' => $method,
                'params' => $params,
            ]],
        ]],
    ], ['X-Livewire' => 'true']);
}

beforeEach(function (): void {
    Cache::flush();
    CarbonImmutable::setTestNow('2026-09-15T12:00:00+00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('enters Reel through one browser-bound installation-bound managed handoff and closes every local bypass', function (): void {
    $fixture = configureReelManagedAuthority();

    $wrongInstallation = beginReelManagedHandoff();
    $fixture->exchangeOverrides = ['installation_id' => 'another-installation'];
    $this->withSession([ManagedHandoff::SESSION_NONCE_KEY => $wrongInstallation['nonce']])
        ->get(route('bfc.managed.callback', [
            'state' => $wrongInstallation['state'],
            'code' => 'wrong-installation-code',
        ], absolute: false))
        ->assertNotFound();
    expect(User::query()->count())->toBe(0)
        ->and(auth('web')->check())->toBeFalse();

    $fixture->exchangeOverrides = [];
    $handoff = beginReelManagedHandoff();
    $this->flushSession();
    $this->get(route('bfc.managed.callback', [
        'state' => $handoff['state'],
        'code' => 'wrong-browser-code',
    ], absolute: false))->assertNotFound();
    expect(DB::table('bfc_managed_handoffs')->where('state_hash', hash('sha256', $handoff['state']))->value('consumed_at'))
        ->toBeNull();

    $this->withSession([ManagedHandoff::SESSION_NONCE_KEY => $handoff['nonce']])
        ->get(route('bfc.managed.callback', [
            'state' => $handoff['state'],
            'code' => 'valid-reel-managed-code',
        ], absolute: false))
        ->assertRedirect('/');

    $user = User::query()->where('scalpels_id', 'subject-fixture')->sole();
    expect(session()->getId())->not->toBe($handoff['session_id'])
        ->and(auth('web')->id())->toBe($user->getKey())
        ->and(session(StandaloneAccess::SESSION_VERSION_KEY))->toBe($user->auth_session_version);
    $this->get(route('dashboard'))->assertOk();

    $callsBeforeReplay = count($fixture->calls);
    $this->withSession([ManagedHandoff::SESSION_NONCE_KEY => $handoff['nonce']])
        ->get(route('bfc.managed.callback', [
            'state' => $handoff['state'],
            'code' => 'replayed-reel-managed-code',
        ], absolute: false))
        ->assertNotFound();
    expect($fixture->calls)->toHaveCount($callsBeforeReplay)
        ->and(auth('web')->id())->toBe($user->getKey());

    $localBypasses = [
        ['GET', '/bfc/login'],
        ['POST', '/bfc/login'],
        ['POST', '/bfc/logout'],
        ['GET', '/bfc/forgot-password'],
        ['POST', '/bfc/forgot-password'],
        ['GET', '/bfc/reset-password'],
        ['POST', '/bfc/reset-password'],
        ['GET', '/bfc/reset-password/test-created-token'],
        ['GET', '/bfc/invitations/accept'],
        ['POST', '/bfc/invitations/accept'],
        ['GET', '/bfc/invitations/test-created-token'],
    ];

    foreach ($localBypasses as [$method, $uri]) {
        $this->call($method, $uri)->assertNotFound();
    }

    $setupRoutes = collect(Route::getRoutes())
        ->filter(static fn (LaravelRoute $route): bool => str_starts_with($route->uri(), 'bfc/')
            && str_contains($route->uri(), 'setup'));

    expect($setupRoutes)->toBeEmpty()
        ->and(User::query()->count())->toBe(1)
        ->and(auth('web')->id())->toBe($user->getKey())
        ->and(session(StandaloneAccess::SESSION_VERSION_KEY))->toBe($user->auth_session_version);
});

it('enforces the exact managed refresh and grace boundaries through Reel dashboard ingress', function (): void {
    $fixture = configureReelManagedAuthority();
    $handoff = beginReelManagedHandoff();
    $this->withSession([ManagedHandoff::SESSION_NONCE_KEY => $handoff['nonce']])
        ->get(route('bfc.managed.callback', [
            'state' => $handoff['state'],
            'code' => 'timeline-reel-managed-code',
        ], absolute: false))
        ->assertRedirect('/');
    $user = User::query()->where('scalpels_id', 'subject-fixture')->sole();

    CarbonImmutable::setTestNow('2026-09-15T12:04:59+00:00');
    $this->get(route('dashboard'))->assertOk();
    expect(reelManagedConfirmationCalls($fixture))->toBe(0)
        ->and(auth('web')->id())->toBe($user->getKey());

    CarbonImmutable::setTestNow('2026-09-15T12:05:00+00:00');
    $this->get(route('dashboard'))->assertOk();
    expect(reelManagedConfirmationCalls($fixture))->toBe(1)
        ->and($user->fresh()->membership_confirmed_at?->toAtomString())->toBe(now()->toAtomString())
        ->and(auth('web')->id())->toBe($user->getKey());

    $fixture->confirmationResponder = static fn (): mixed => Http::response([
        'contract_version' => ManagedAuthClient::CONTRACT_VERSION,
        'error' => 'server_error',
    ], 503);

    CarbonImmutable::setTestNow('2026-09-15T12:34:59+00:00');
    $this->get(route('dashboard'))->assertOk();
    expect(reelManagedConfirmationCalls($fixture))->toBe(2)
        ->and(auth('web')->id())->toBe($user->getKey())
        ->and(session(StandaloneAccess::SESSION_VERSION_KEY))->toBe($user->auth_session_version);

    CarbonImmutable::setTestNow('2026-09-15T12:35:00+00:00');
    $this->get(route('dashboard'))->assertRedirect(route('bfc.managed.login', [
        'intended' => route('dashboard', absolute: false),
    ]));
    expect(reelManagedConfirmationCalls($fixture))->toBe(2)
        ->and(auth('web')->check())->toBeFalse()
        ->and(session(StandaloneAccess::SESSION_VERSION_KEY))->toBeNull();
});

it('applies managed role and ordering changes on the next Reel request', function (): void {
    $fixture = configureReelManagedAuthority();
    $fixture->exchangeOverrides = ['role' => 'admin'];
    $user = enterReelManagedSession($fixture, 'managed-ordering-code');

    expect($user->role)->toBe('admin');

    $fixture->confirmationOverrides = ['role' => 'member'];
    CarbonImmutable::setTestNow('2026-09-15T12:05:00+00:00');
    $this->get(route('dashboard'))->assertOk();
    $user->refresh();
    expect($user->role)->toBe('member')
        ->and($user->managed_membership_roster_version)->toBe(9)
        ->and($user->managed_membership_response_sequence)->toBe(14)
        ->and(auth('web')->id())->toBe($user->getKey());

    $fixture->confirmationOverrides = ['role' => 'admin'];
    CarbonImmutable::setTestNow('2026-09-15T12:10:00+00:00');
    $this->get(route('dashboard'))->assertOk();
    $user->refresh();
    expect($user->role)->toBe('admin')
        ->and($user->managed_membership_roster_version)->toBe(10)
        ->and($user->managed_membership_response_sequence)->toBe(15)
        ->and(session(StandaloneAccess::SESSION_VERSION_KEY))->toBe($user->auth_session_version);

    DB::table('bfc_authority')->where('key', InstallationAuthority::KEY)->update([
        'managed_connection_roster_version' => 8,
        'managed_connection_response_sequence' => 13,
    ]);
    $fixture->confirmationOverrides = [
        'role' => 'member',
        'roster_version' => 9,
        'response_sequence' => 14,
    ];
    CarbonImmutable::setTestNow('2026-09-15T12:15:00+00:00');
    $this->get(route('dashboard'))->assertOk();
    $user->refresh();
    $authority = DB::table('bfc_authority')->where('key', InstallationAuthority::KEY)->first();
    expect($user->role)->toBe('admin')
        ->and($user->managed_membership_roster_version)->toBe(10)
        ->and($user->managed_membership_response_sequence)->toBe(15)
        ->and($authority?->managed_connection_roster_version)->toBe(9)
        ->and($authority?->managed_connection_response_sequence)->toBe(14);

    $nextGeneration = configureReelManagedAuthority(
        8,
        (string) config('built-for-cloud.managed.client_secret'),
    );
    $nextGeneration->confirmationOverrides = [
        'role' => 'member',
        'roster_version' => 1,
        'response_sequence' => 1,
    ];
    CarbonImmutable::setTestNow('2026-09-15T12:20:00+00:00');
    $this->get(route('dashboard'))->assertOk();
    $user->refresh();
    $authority = DB::table('bfc_authority')->where('key', InstallationAuthority::KEY)->first();
    expect(reelManagedConfirmationCalls($fixture))->toBe(3)
        ->and(reelManagedConfirmationCalls($nextGeneration))->toBe(1)
        ->and($user->managed_membership_generation)->toBe(8)
        ->and($user->managed_membership_roster_version)->toBe(1)
        ->and($user->managed_membership_response_sequence)->toBe(1)
        ->and($user->role)->toBe('member')
        ->and($authority?->managed_connection_generation)->toBe(8)
        ->and($authority?->managed_connection_response_sequence)->toBe(1)
        ->and(auth('web')->id())->toBe($user->getKey());
});

it('does not let a delayed authority response regress accepted Reel role or ordering state', function (): void {
    $fixture = configureReelManagedAuthority();
    $fixture->exchangeOverrides = ['role' => 'member'];
    $user = enterReelManagedSession($fixture, 'managed-delayed-response-code');

    $fixture->confirmationOverrides = [
        'role' => 'admin',
        'roster_version' => 10,
        'response_sequence' => 15,
    ];
    CarbonImmutable::setTestNow('2026-09-15T12:05:00+00:00');
    $this->get(route('dashboard'))->assertOk();

    $fixture->confirmationOverrides = [
        'role' => 'member',
        'roster_version' => 9,
        'response_sequence' => 14,
        'responded_at' => '2026-09-15T12:04:00+00:00',
    ];
    CarbonImmutable::setTestNow('2026-09-15T12:10:00+00:00');
    $this->get(route('dashboard'))->assertOk();

    $user->refresh();
    $authority = DB::table('bfc_authority')->where('key', InstallationAuthority::KEY)->first();
    expect(reelManagedConfirmationCalls($fixture))->toBe(2)
        ->and($user->role)->toBe('admin')
        ->and($user->managed_membership_generation)->toBe(7)
        ->and($user->managed_membership_roster_version)->toBe(10)
        ->and($user->managed_membership_response_sequence)->toBe(15)
        ->and($user->managed_membership_responded_at)->toBe('2026-09-15T12:05:00.000+00:00')
        ->and($authority?->managed_connection_generation)->toBe(7)
        ->and($authority?->managed_connection_roster_version)->toBe(10)
        ->and($authority?->managed_connection_response_sequence)->toBe(15)
        ->and(auth('web')->id())->toBe($user->getKey())
        ->and(session(StandaloneAccess::SESSION_VERSION_KEY))->toBe($user->auth_session_version);
});

it('keeps subject membership and installation connection ordering independent through Reel ingress', function (
    array $arrivalOrder,
    int $connectionRoster,
    int $connectionSequence,
): void {
    $fixture = configureReelManagedAuthority();
    $users = [];

    foreach (['a', 'b'] as $subjectKey) {
        $subject = 'subject-'.$subjectKey;
        $fixture->exchangeOverrides = [
            'scalpels_id' => $subject,
            'membership_id' => 'membership-'.$subjectKey,
            'display_name' => 'Fixture '.strtoupper($subjectKey),
            'contact_email' => $subject.'@example.test',
            'role' => 'member',
        ];
        $users[$subjectKey] = enterReelManagedSession(
            $fixture,
            'managed-order-'.$subjectKey,
            $subject,
        );
    }

    $fixture->confirmationResponder = static function (array $request, array $payload): mixed {
        $response = $request['scalpels_id'] === 'subject-a'
            ? [
                'role' => 'admin',
                'roster_version' => 20,
                'response_sequence' => 30,
                'responded_at' => '2026-09-15T12:04:30+00:00',
            ]
            : [
                'role' => 'member',
                'roster_version' => 30,
                'response_sequence' => 20,
                'responded_at' => '2026-09-15T12:04:45+00:00',
            ];

        return Http::response(array_merge($payload, $response));
    };

    CarbonImmutable::setTestNow('2026-09-15T12:05:00+00:00');
    foreach ($arrivalOrder as $subjectKey) {
        $user = $users[$subjectKey]->refresh();
        auth('web')->login($user);
        $this->withSession([StandaloneAccess::SESSION_VERSION_KEY => $user->auth_session_version])
            ->get(route('dashboard'))
            ->assertOk();
        expect(auth('web')->id())->toBe($user->getKey())
            ->and(session(StandaloneAccess::SESSION_VERSION_KEY))->toBe($user->auth_session_version);
    }

    $a = $users['a']->fresh();
    $b = $users['b']->fresh();
    $authority = DB::table('bfc_authority')->where('key', InstallationAuthority::KEY)->first();
    expect(reelManagedConfirmationCalls($fixture))->toBe(2)
        ->and($a?->role)->toBe('admin')
        ->and($a?->managed_membership_generation)->toBe(7)
        ->and($a?->managed_membership_roster_version)->toBe(20)
        ->and($a?->managed_membership_response_sequence)->toBe(30)
        ->and($b?->role)->toBe('member')
        ->and($b?->managed_membership_generation)->toBe(7)
        ->and($b?->managed_membership_roster_version)->toBe(30)
        ->and($b?->managed_membership_response_sequence)->toBe(20)
        ->and($authority?->managed_connection_generation)->toBe(7)
        ->and($authority?->managed_connection_roster_version)->toBe($connectionRoster)
        ->and($authority?->managed_connection_response_sequence)->toBe($connectionSequence);

    CarbonImmutable::setTestNow('2026-09-15T12:05:01+00:00');
    foreach (['a' => 'admin', 'b' => 'member'] as $subjectKey => $role) {
        $user = $users[$subjectKey]->fresh();
        expect($user)->toBeInstanceOf(User::class);
        auth('web')->login($user);
        $this->withSession([StandaloneAccess::SESSION_VERSION_KEY => $user->auth_session_version])
            ->get(route('dashboard'))
            ->assertOk();
        expect(auth('web')->id())->toBe($user->getKey())
            ->and(auth('web')->user()?->role)->toBe($role)
            ->and(session(StandaloneAccess::SESSION_VERSION_KEY))->toBe($user->auth_session_version);
    }
    expect(reelManagedConfirmationCalls($fixture))->toBe(2);
})->with([
    'subject A then subject B' => [['a', 'b'], 20, 30],
    'subject B then subject A' => [['b', 'a'], 30, 20],
]);

it('ends a removed managed session before Reel state can mutate', function (): void {
    Storage::fake('local');
    Queue::fake();
    $fixture = configureReelManagedAuthority();
    $user = enterReelManagedSession($fixture, 'managed-removal-code');
    $application = Application::factory()->create(['name' => 'Removal boundary application']);
    $credential = activeReelCredential($application);
    $object = "reel/chunks/{$application->public_id}/removal-session/chunk.gz";
    Storage::disk('local')->put($object, 'test-created-object');
    $recording = new RecordingSession;
    $recording->fill([
        'application_id' => $application->getKey(),
        'application_credential_id' => $credential->getKey(),
        'session_id' => str_repeat('a', 64),
        'grant_id_hash' => str_repeat('b', 64),
        'origin' => 'https://removal.example.test',
        'protocol_version' => 1,
        'max_chunks' => 10,
        'max_compressed_bytes' => 1000,
        'max_chunk_bytes' => 500,
        'started_at' => now(),
        'max_event_time' => now(),
        'upload_cutoff_at' => now()->addMinute(),
    ]);
    $recording->forceFill(['status' => RecordingSessionStatus::Ready])->save();
    $credentialCount = DB::table('credentials')->count();

    $fixture->confirmationOverrides = ['membership_status' => 'removed'];
    CarbonImmutable::setTestNow('2026-09-15T12:05:00+00:00');
    $this->get(route('dashboard'))->assertRedirect(route('bfc.managed.login', [
        'intended' => route('dashboard', absolute: false),
    ]));
    $user->refresh();
    expect(auth('web')->check())->toBeFalse()
        ->and($user->status)->toBe('inactive');

    $this->get(route('admin.applications.create'))->assertRedirect(route('bfc.managed.login', [
        'intended' => route('admin.applications.create', absolute: false),
    ]));
    $this->get(route('admin.applications.show', $application))->assertRedirect(route('bfc.managed.login', [
        'intended' => route('admin.applications.show', $application, absolute: false),
    ]));
    $this->post(route('sessions.protection.store', [$application, $recording]))->assertRedirect(route('bfc.managed.login', [
        'intended' => route('sessions.protection.store', [$application, $recording], absolute: false),
    ]));
    $this->delete(route('admin.sessions.destroy', [$application, $recording]))->assertRedirect(route('bfc.managed.login', [
        'intended' => route('admin.sessions.destroy', [$application, $recording], absolute: false),
    ]));
    $this->post(route('admin.application-users.destroy', $application), [
        'application_user_id' => 'removed-actor-subject',
        'confirmation' => 'removed-actor-subject',
    ])->assertRedirect(route('bfc.managed.login', [
        'intended' => route('admin.application-users.destroy', $application, absolute: false),
    ]));

    $recording->refresh();
    expect(Application::query()->whereKey($application->getKey())->value('name'))->toBe('Removal boundary application')
        ->and(DB::table('credentials')->count())->toBe($credentialCount)
        ->and($credential->fresh()->revoked_at)->toBeNull()
        ->and($recording->status)->toBe(RecordingSessionStatus::Ready)
        ->and($recording->protected_at)->toBeNull()
        ->and($recording->protectionEvents()->count())->toBe(0)
        ->and(UserErasureAudit::query()->count())->toBe(0);
    Storage::disk('local')->assertExists($object);
    Queue::assertNotPushed(DeleteUserErasureBatch::class);
});

it('applies the package human gate to real Livewire updates after standalone session revocation', function (): void {
    $persistentMiddleware = resolve(LivewireManager::class)->getPersistentMiddleware();
    expect($persistentMiddleware)->toContain(EnsureUserIsAuthenticated::class);

    $user = UserFactory::factory()->create();
    $application = Application::factory()->create([
        'name' => 'Ended principal listing marker',
        'ingest_enabled' => true,
    ]);
    $this->post('/bfc/login', [
        'email' => $user->email,
        'password' => 'test-created-password',
    ])->assertRedirect();
    $this->withSession(['session-revocation-proof' => 'present']);
    $mutationSnapshot = reelLivewireSnapshot(
        $this->get(route('admin.applications.show', $application))->assertOk(),
    );
    $listingSnapshot = reelLivewireSnapshot(
        $this->get(route('sessions.index'))->assertOk()->assertSeeText($application->name),
    );

    $user->increment('auth_session_version');

    postReelLivewireUpdate($mutationSnapshot, 'toggleIngest')
        ->assertUnauthorized()
        ->assertSessionMissing('session-revocation-proof');
    expect($application->refresh()->ingest_enabled)->toBeTrue()
        ->and(auth('web')->check())->toBeFalse();

    postReelLivewireUpdate($listingSnapshot, '$refresh')
        ->assertUnauthorized()
        ->assertDontSee($application->name);
});

it('applies managed removal at the five-minute boundary before a real Livewire mutation', function (): void {
    $fixture = configureReelManagedAuthority();
    $user = enterReelManagedSession($fixture, 'managed-livewire-removal-code');
    $application = Application::factory()->create(['ingest_enabled' => true]);
    $snapshot = reelLivewireSnapshot(
        $this->get(route('admin.applications.show', $application))->assertOk(),
    );

    $fixture->confirmationOverrides = ['membership_status' => 'removed'];
    CarbonImmutable::setTestNow('2026-09-15T12:05:00+00:00');

    postReelLivewireUpdate($snapshot, 'toggleIngest')->assertUnauthorized();

    expect(reelManagedConfirmationCalls($fixture))->toBe(1)
        ->and($application->refresh()->ingest_enabled)->toBeTrue()
        ->and($user->refresh()->status)->toBe('inactive')
        ->and(auth('web')->check())->toBeFalse()
        ->and(session(StandaloneAccess::SESSION_VERSION_KEY))->toBeNull();
});

it('invalidates an inactive principal before a real Livewire mutation', function (): void {
    $user = UserFactory::factory()->create();
    $application = Application::factory()->create(['ingest_enabled' => true]);
    $this->post('/bfc/login', [
        'email' => $user->email,
        'password' => 'test-created-password',
    ])->assertRedirect();
    $this->withSession(['inactive-session-proof' => 'present']);
    $snapshot = reelLivewireSnapshot(
        $this->get(route('admin.applications.show', $application))->assertOk(),
    );

    DB::table('users')->where('id', $user->getKey())->update(['status' => 'inactive']);

    postReelLivewireUpdate($snapshot, 'toggleIngest')
        ->assertForbidden()
        ->assertSessionMissing('inactive-session-proof');
    Auth::forgetGuards();
    expect($application->refresh()->ingest_enabled)->toBeTrue()
        ->and(auth('web')->check())->toBeFalse();
});
