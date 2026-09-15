<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\AuthorityMode;
use ArtisanBuild\BuiltForCloud\InstallationAuthority;
use ArtisanBuild\BuiltForCloud\ManagedAuthClient;
use ArtisanBuild\BuiltForCloud\ManagedHandoff;
use ArtisanBuild\BuiltForCloud\StandaloneAccess;
use ArtisanBuild\BuiltForCloud\Tests\Fixtures\ManagedAuthorityFixture;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/../../vendor/artisan-build/built-for-cloud/tests/Fixtures/ManagedAuthorityFixture.php';

function configureReelManagedAuthority(): ManagedAuthorityFixture
{
    $secret = bin2hex(random_bytes(32));
    DB::table('bfc_authority')->where('key', InstallationAuthority::KEY)->update([
        'mode' => AuthorityMode::Managed->value,
        'generation' => 7,
        'issuer' => 'https://issuer.example.test',
        'connection_id' => 'reel-connection-fixture',
        'organization_id' => 'reel-organization-fixture',
        'installation_id' => 'reel-installation-fixture',
        'authority_base_url' => 'https://authority.example.test',
    ]);
    config(['built-for-cloud.managed.client_secret' => $secret]);

    $fixture = new ManagedAuthorityFixture(
        'https://authority.example.test',
        $secret,
        'https://issuer.example.test',
        'reel-connection-fixture',
        'reel-organization-fixture',
        'reel-installation-fixture',
        7,
    );
    Http::fake(fn (ClientRequest $request): mixed => $fixture->respond($request));

    return $fixture;
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

beforeEach(function (): void {
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
    $this->get(route('dashboard'))->assertRedirect(route('bfc.login'));
    expect(reelManagedConfirmationCalls($fixture))->toBe(2)
        ->and(auth('web')->check())->toBeFalse()
        ->and(session(StandaloneAccess::SESSION_VERSION_KEY))->toBeNull();
});
