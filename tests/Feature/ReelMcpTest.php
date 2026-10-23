<?php

declare(strict_types=1);

use App\Mcp\ReelMcpServer;
use App\Mcp\Tools\SessionContentTool;
use App\Mcp\Tools\SessionDeepLinkTool;
use App\Mcp\Tools\SessionsTool;
use App\Models\Application;
use App\Models\RecordingSession;
use App\Models\ReplayView;
use App\Services\ReplayPayloadReader;
use ArtisanBuild\BuiltForCloud\Console\ConsoleKeyring;
use ArtisanBuild\BuiltForCloud\Console\ConsoleRole;
use ArtisanBuild\BuiltForCloud\Console\DelegatedActor;
use ArtisanBuild\BuiltForCloud\Console\RequestAssertion;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\Http\Middleware\AuthenticateMcp;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\RequestEffectCeiling;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use ArtisanBuild\BuiltForCloud\OperatorAbility;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\McpDelegatedAssertionFixture;
use ArtisanBuild\BuiltForCloud\Testing\McpProductAdmission;
use ArtisanBuild\BuiltForCloud\Testing\WithCredentials;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Server\Testing\TestResponse;
use ParagonIE\Paseto\Builder;
use ParagonIE\Paseto\Keys\Version4\AsymmetricSecretKey;
use ParagonIE\Paseto\Protocol\Version4;
use ParagonIE\Paseto\Purpose;
use Tests\Support\User;

uses(WithCredentials::class);

/** @return array<string, mixed> */
function reelMcpPayload(TestResponse $response): array
{
    $method = new ReflectionMethod($response, 'structuredContent');
    $payload = $method->invoke($response);

    expect($payload)->toBeArray();

    return $payload;
}

function maximumMcpRequestId(): string
{
    $id = str_repeat('x', 254);

    expect(strlen(json_encode($id, JSON_THROW_ON_ERROR)))->toBe(256);

    return $id;
}

function reelDelegatedKey(string $keyId): AsymmetricSecretKey
{
    $secret = AsymmetricSecretKey::generate(new Version4);
    $keyring = new ConsoleKeyring;
    $keyring->add($keyId, $secret->getPublicKey()->toHexString());
    $keyring->activate($keyId);

    return $secret;
}

function reelDelegatedAssertion(AsymmetricSecretKey $secret, string $keyId, string $subject): string
{
    $now = CarbonImmutable::now('UTC');

    return (new Builder)
        ->setVersion(new Version4)
        ->setPurpose(Purpose::public())
        ->setKey($secret)
        ->setClaims([
            'iss' => 'https://console.reel.test',
            'sub' => $subject,
            'aud' => 'https://reel.test',
            'iat' => $now->toAtomString(),
            'nbf' => $now->toAtomString(),
            'exp' => $now->addSeconds(90)->toAtomString(),
            'jti' => 'reel_'.bin2hex(random_bytes(8)),
            'display_name' => 'Reel Test Operator',
            'role' => ConsoleRole::Member->value,
            'purpose' => 'mcp',
        ])
        ->setFooterArray(['kid' => $keyId])
        ->toString();
}

beforeEach(function (): void {
    RequestEffectCeiling::publish(resolve('request'), Effect::Read->value);
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
    Auth::setUser($this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'reel-mcp-direct-tool-test',
        'abilities' => [OperatorAbility::McpRead->value],
    ])->credential);
    resolve('request')->setUserResolver(static fn () => Auth::user());
});

it('mounts and advertises exactly one delegated effect-scoped read door', function (): void {
    McpProductAdmission::assert();

    $metadata = $this->getJson('/bfc/meta')->assertOk();
    $metadata->assertJsonPath('endpoints', ['mcp' => '/mcp']);
    expect($metadata->json('capabilities'))->toContain('mcp-serve', 'mcp-delegated', 'mcp-effect-scoped');
    $route = Route::getRoutes()->match(Request::create('/mcp', 'POST'));
    expect(resolve('router')->gatherRouteMiddleware($route))
        ->toContain(AuthenticateMcp::class.':product,read');

    $toolClasses = [SessionsTool::class, SessionContentTool::class, SessionDeepLinkTool::class];
    $tools = RequestEffectCeiling::run(
        resolve('request'),
        Effect::Read,
        fn () => ReelMcpServer::tools(),
    );
    $tools->assertRegistered($toolClasses);

    foreach ($toolClasses as $toolClass) {
        $tool = resolve($toolClass);
        expect(ToolEffect::of($tool)?->value)->toBe(Effect::Read)
            ->and(ToolClassification::of($tool)?->value)->toBe(Classification::Content)
            ->and($tool->toArray()['inputSchema']['additionalProperties'])->toBeFalse();
    }

    $items = (new ReflectionProperty($tools, 'items'))->getValue($tools);
    expect(collect($items)->pluck('name')->sort()->values()->all())
        ->toBe(['session_content', 'session_deep_link', 'sessions']);
});

it('returns exactly the three read tools over HTTP and refuses synthetic writes without side effects', function (): void {
    $credential = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'reel-mcp-test',
        'abilities' => [OperatorAbility::McpRead->value],
    ]);
    $headers = [
        'Authorization' => $credential->bearerHeader(),
        'Accept' => 'application/json, text/event-stream',
    ];

    $listed = $this->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [],
    ], $headers)->assertOk();
    expect(collect($listed->json('result.tools'))->pluck('name')->sort()->values()->all())
        ->toBe(['session_content', 'session_deep_link', 'sessions']);

    $before = [Application::query()->count(), RecordingSession::query()->count()];

    foreach (['delete_session', 'erase_user', 'protect_session', 'unprotect_session'] as $name) {
        $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => $name, 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => []],
        ], $headers)->assertBadRequest()->assertJsonPath('error.code', -32602);
    }

    expect([Application::query()->count(), RecordingSession::query()->count()])->toBe($before);

    $overMaximum = $this->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 'over-maximum-types', 'method' => 'tools/call',
        'params' => ['name' => 'session_content', 'arguments' => [
            'application' => 'app',
            'session_id' => str_repeat('a', 64),
            'types' => ['dom', 'dom', 'click', 'scroll', 'error'],
        ]],
    ], $headers)->assertOk()->assertJsonPath('result.isError', true);
    expect($overMaximum->json('result.content.0.text'))->toContain('types');
});

it('traverses more than one hundred scoped sessions without duplicate or skipped rows', function (): void {
    $application = Application::factory()->create();
    $foreign = Application::factory()->create();
    $credential = activeReelCredential($application);
    $foreignCredential = activeReelCredential($foreign);

    $startedAt = now();

    foreach (range(1, 105) as $index) {
        makeMcpRecordingSession($application, $credential, attributes: [
            'started_at' => $startedAt,
            'session_id' => str_pad(dechex($index), 64, '0', STR_PAD_LEFT),
        ]);
    }
    $foreignSession = makeMcpRecordingSession($foreign, $foreignCredential);

    $first = reelMcpPayload(ReelMcpServer::tool(SessionsTool::class, [
        'application' => $application->public_id,
        'limit' => 100,
    ])->assertOk());
    $second = reelMcpPayload(ReelMcpServer::tool(SessionsTool::class, [
        'application' => $application->public_id,
        'limit' => 100,
        'cursor' => $first['next_cursor'],
    ])->assertOk());
    $ids = collect([...$first['sessions'], ...$second['sessions']])->pluck('session_id');

    expect($first['sessions'])->toHaveCount(100)
        ->and($second['sessions'])->toHaveCount(5)
        ->and($ids)->toHaveCount(105)
        ->and($ids->unique())->toHaveCount(105)
        ->and($ids)->not->toContain($foreignSession->session_id)
        ->and($second['next_cursor'])->toBeNull();
});

it('byte-paginates sessions below the relay cap with a maximum legal request id and exact coverage', function (): void {
    $application = Application::factory()->create();
    $recordingCredential = activeReelCredential($application);
    $expectedIds = [];
    $startedAt = now();
    $markerPath = '/'.str_repeat('x', 2047);

    foreach (range(1, 30) as $index) {
        $session = makeMcpRecordingSession($application, $recordingCredential, attributes: [
            'started_at' => $startedAt,
            'session_id' => str_pad(dechex($index), 64, '0', STR_PAD_LEFT),
            'initial_path' => '/'.str_repeat('i', 254),
            'latest_path' => '/'.str_repeat('l', 254),
            'application_user_id' => str_repeat('u', 255),
            'release_id' => str_repeat('r', 255),
        ]);
        $expectedIds[] = $session->session_id;

        foreach (range(1, 10) as $marker) {
            $session->markers()->create([
                'application_id' => $application->getKey(),
                'marker_type' => 'error',
                'occurred_at' => $marker,
                'metadata' => ['method' => 'GET', 'path' => $markerPath, 'status' => 500],
            ]);
        }
    }

    $credential = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'reel-mcp-session-budget-test',
        'abilities' => [OperatorAbility::McpRead->value],
    ]);
    $headers = [
        'Authorization' => $credential->bearerHeader(),
        'Accept' => 'application/json, text/event-stream',
    ];
    $cursor = null;
    $seenCursors = [];
    $actualIds = [];
    $pageSizes = [];

    do {
        $arguments = ['application' => $application->public_id, 'limit' => 30];

        if ($cursor !== null) {
            $arguments['cursor'] = $cursor;
        }

        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => maximumMcpRequestId(), 'method' => 'tools/call',
            'params' => ['name' => 'sessions', 'arguments' => $arguments],
        ], $headers)->assertOk()->assertJsonPath('result.isError', false);
        expect(strlen((string) $response->getContent()))->toBeLessThan(1_048_576);
        $sessions = $response->json('result.structuredContent.sessions');
        expect($sessions)->toBeArray()->not->toBeEmpty();
        $pageSizes[] = count($sessions);
        array_push($actualIds, ...array_column($sessions, 'session_id'));
        $cursor = $response->json('result.structuredContent.next_cursor');

        if ($cursor !== null) {
            expect($seenCursors)->not->toContain($cursor);
            $seenCursors[] = $cursor;
        }
    } while ($cursor !== null);

    expect($pageSizes[0])->toBeLessThan(30)
        ->and($seenCursors)->not->toBeEmpty()
        ->and($actualIds)->toHaveCount(30)
        ->and($actualIds)->toHaveCount(count(array_unique($actualIds)))
        ->and($actualIds)->toEqualCanonicalizing($expectedIds);
});

it('fails closed with a bounded error when one session row exceeds the relay cap', function (): void {
    $application = Application::factory()->create();
    $session = makeMcpRecordingSession($application, activeReelCredential($application));
    $session->markers()->create([
        'application_id' => $application->getKey(),
        'marker_type' => 'error',
        'occurred_at' => 1,
        'metadata' => ['method' => 'GET', 'path' => str_repeat('x', 600_000), 'status' => 500],
    ]);
    $credential = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'reel-mcp-oversized-session-row-test',
        'abilities' => [OperatorAbility::McpRead->value],
    ]);

    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => maximumMcpRequestId(), 'method' => 'tools/call',
        'params' => ['name' => 'sessions', 'arguments' => [
            'application' => $application->public_id,
            'limit' => 1,
        ]],
    ], [
        'Authorization' => $credential->bearerHeader(),
        'Accept' => 'application/json, text/event-stream',
    ])->assertOk()->assertJsonPath('result.structuredContent.error', 'session_row_exceeds_relay_limit');

    expect(strlen((string) $response->getContent()))->toBeLessThan(1_048_576);
});

it('fragments a privacy-validated oversized event below the relay cap with a maximum legal request id and no data loss', function (): void {
    $application = Application::factory()->create();
    $session = makeMcpRecordingSession($application, activeReelCredential($application), [[
        'type' => 2,
        'timestamp' => 1_000,
        'data' => ['node' => [
            'type' => 2,
            'id' => 1,
            'tagName' => 'div',
            'attributes' => [],
            'childNodes' => [[
                'type' => 3,
                'id' => 2,
                'textContent' => str_repeat('visible replay text ', 60_000),
            ]],
        ]],
    ]]);
    $credential = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'reel-mcp-fragment-test',
        'abilities' => [OperatorAbility::McpRead->value],
    ]);
    $headers = [
        'Authorization' => $credential->bearerHeader(),
        'Accept' => 'application/json, text/event-stream',
    ];
    $cursor = null;
    $decoded = '';
    $seenCursors = [];
    $expectedOffset = 0;
    $fragmentTotal = null;

    do {
        $arguments = [
            'application' => $application->public_id,
            'session_id' => $session->session_id,
            'types' => ['dom'],
            'limit' => 1,
        ];

        if ($cursor !== null) {
            $arguments['cursor'] = $cursor;
        }

        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => maximumMcpRequestId(), 'method' => 'tools/call',
            'params' => ['name' => 'session_content', 'arguments' => $arguments],
        ], $headers)->assertOk()->assertJsonPath('result.isError', false);
        expect(strlen((string) $response->getContent()))->toBeLessThan(1_048_576);
        $entry = $response->json('result.structuredContent.events.0');
        expect($entry)->toBeArray()
            ->and($entry['sequence'])->toBe(0)
            ->and($entry['kind'])->toBe('dom')
            ->and($entry['encoding'])->toBe('base64')
            ->and($entry['fragment_offset'])->toBe($expectedOffset);
        $fragment = base64_decode((string) $entry['fragment'], true);
        expect($fragment)->toBeString();
        $decoded .= $fragment;
        $expectedOffset += strlen($fragment);
        $fragmentTotal ??= $entry['fragment_total'];
        expect($entry['fragment_total'])->toBe($fragmentTotal);

        $cursor = $response->json('result.structuredContent.next_cursor');

        if ($cursor !== null) {
            expect($seenCursors)->not->toContain($cursor);
            $seenCursors[] = $cursor;
        }
    } while ($cursor !== null);

    $event = resolve(ReplayPayloadReader::class)->read($session)->events[0];
    $expected = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    expect($decoded)->toBe($expected)
        ->and(strlen($decoded))->toBe($fragmentTotal)
        ->and($seenCursors)->not->toBeEmpty();
});

it('bounds session markers and applies the existing filter semantics', function (): void {
    $application = Application::factory()->create();
    $credential = activeReelCredential($application);
    $session = makeMcpRecordingSession($application, $credential, attributes: [
        'application_user_id' => 'customer-42',
        'release_id' => 'deploy-9',
        'protected_at' => now(),
    ]);

    foreach (range(1, 11) as $index) {
        $session->markers()->create([
            'application_id' => $application->getKey(),
            'marker_type' => 'error',
            'occurred_at' => $index,
            'metadata' => ['method' => 'GET', 'path' => '/failure', 'status' => 500],
        ]);
    }

    $payload = reelMcpPayload(ReelMcpServer::tool(SessionsTool::class, [
        'application' => $application->public_id,
        'path' => '/finish',
        'user_id' => 'customer-42',
        'release' => 'deploy-9',
        'status' => 'ready',
        'marker' => 'error',
        'protected' => 'yes',
    ])->assertOk());

    expect($payload['sessions'])->toHaveCount(1)
        ->and($payload['sessions'][0]['error_markers'])->toHaveCount(10)
        ->and($payload['sessions'][0]['error_markers_truncated'])->toBeTrue();
});

it('pages privacy-validated replay content and never serializes rejected private values', function (): void {
    $application = Application::factory()->create();
    $credential = activeReelCredential($application);
    $snapshot = [
        'type' => 2, 'timestamp' => 1_000,
        'data' => ['node' => [
            'type' => 2, 'id' => 1, 'tagName' => 'input',
            'attributes' => ['value' => '***'], 'childNodes' => [],
        ]],
    ];
    $events = [$snapshot];

    foreach (range(1, 204) as $index) {
        $events[] = [
            'type' => 3, 'timestamp' => 1_000 + $index,
            'data' => ['source' => 3, 'id' => 1, 'x' => 0, 'y' => $index],
        ];
    }

    $session = makeMcpRecordingSession($application, $credential, $events);
    $first = reelMcpPayload(ReelMcpServer::tool(SessionContentTool::class, [
        'application' => $application->public_id,
        'session_id' => $session->session_id,
        'limit' => 100,
    ])->assertOk());
    $second = reelMcpPayload(ReelMcpServer::tool(SessionContentTool::class, [
        'application' => $application->public_id,
        'session_id' => $session->session_id,
        'limit' => 100,
        'cursor' => $first['next_cursor'],
    ])->assertOk());
    $third = reelMcpPayload(ReelMcpServer::tool(SessionContentTool::class, [
        'application' => $application->public_id,
        'session_id' => $session->session_id,
        'limit' => 100,
        'cursor' => $second['next_cursor'],
    ])->assertOk());
    $sequences = collect([...$first['events'], ...$second['events'], ...$third['events']])->pluck('sequence');

    expect($first['events'])->toHaveCount(100)
        ->and($second['events'])->toHaveCount(100)
        ->and($third['events'])->toHaveCount(5)
        ->and($third['next_cursor'])->toBeNull()
        ->and($sequences)->toHaveCount(205)
        ->and($sequences->unique())->toHaveCount(205);

    $unsafeCases = [
        'PRIVATE_QUERY_SENTINEL' => [[
            'type' => 4, 'timestamp' => 2_000,
            'data' => ['href' => '/account?token=PRIVATE_QUERY_SENTINEL', 'width' => 100, 'height' => 100],
        ]],
        'PRIVATE_DOM_SENTINEL' => [[
            'type' => 2, 'timestamp' => 2_001,
            'data' => ['node' => [
                'type' => 2, 'id' => 1, 'tagName' => 'input',
                'attributes' => ['value' => 'PRIVATE_DOM_SENTINEL'], 'childNodes' => [],
            ]],
        ]],
        'PRIVATE_HEADER_SENTINEL' => [[
            'type' => 5, 'timestamp' => 2_002,
            'data' => [
                'tag' => 'reel.error',
                'payload' => ['method' => 'GET', 'path' => '/failure', 'status' => 500, 'headers' => ['X-Secret' => 'PRIVATE_HEADER_SENTINEL']],
            ],
        ]],
    ];

    foreach ($unsafeCases as $sentinel => $unsafeEvents) {
        $unsafe = makeMcpRecordingSession($application, $credential, $unsafeEvents);
        $unsafeResponse = ReelMcpServer::tool(SessionContentTool::class, [
            'application' => $application->public_id,
            'session_id' => $unsafe->session_id,
        ])->assertOk();
        $unsafePayload = reelMcpPayload($unsafeResponse);

        expect(json_encode($unsafePayload, JSON_THROW_ON_ERROR))->not->toContain($sentinel)
            ->and($unsafePayload['diagnostic'])->toBe('unsafe_payload');
    }
});

it('uses the type-qualified delegated actor id for watched-session filtering', function (): void {
    $application = Application::factory()->create();
    $session = makeMcpRecordingSession($application, activeReelCredential($application));
    $assertion = McpDelegatedAssertionFixture::verified(ConsoleRole::Admin);
    $actor = DelegatedActor::recordHandoff($assertion);
    RequestAssertion::publish(request(), $actor, $assertion);
    ReplayView::query()->create([
        'actor_id' => $actor->getAuthIdentifier(),
        'application_id' => $application->getKey(),
        'recording_session_id' => $session->getKey(),
        'viewed_at' => now(),
    ]);

    $payload = reelMcpPayload(ReelMcpServer::tool(SessionsTool::class, [
        'application' => $application->public_id,
        'watched' => 'yes',
    ])->assertOk());

    expect($actor->getAuthIdentifier())->toStartWith('bfc-console:')
        ->and($payload['sessions'])->toHaveCount(1)
        ->and($payload['sessions'][0]['session_id'])->toBe($session->session_id);
});

it('refuses an inactive delegated subject readably before application work', function (): void {
    config([
        'built-for-cloud.console.issuer' => 'https://console.reel.test',
        'built-for-cloud.console.audience' => 'https://reel.test',
    ]);
    $keyId = 'reel-test-'.bin2hex(random_bytes(4));
    $secret = reelDelegatedKey($keyId);
    $subject = 'inactive-operator';
    $headers = static fn (string $token): array => [
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json, text/event-stream',
    ];

    $this->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 'prime-actor', 'method' => 'tools/list', 'params' => [],
    ], $headers(reelDelegatedAssertion($secret, $keyId, $subject)))->assertOk();

    $actor = DelegatedActor::query()->where('subject', $subject)->sole();
    $actor->forceFill(['deactivated_at' => now()])->save();
    $application = Application::factory()->create();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        if (preg_match('/\b(applications|recording_sessions|replay_views)\b/', strtolower((string) $query->sql)) === 1) {
            $queries[] = $query->sql;
        }
    });

    $this->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 'inactive-actor', 'method' => 'tools/call',
        'params' => ['name' => 'sessions', 'arguments' => [
            'application' => $application->public_id,
        ]],
    ], $headers(reelDelegatedAssertion($secret, $keyId, $subject)))
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);

    expect($queries)->toBe([]);
});

it('never aliases a delegated numeric actor id to a local user or store credential identity', function (): void {
    $application = Application::factory()->create();
    $session = makeMcpRecordingSession($application, activeReelCredential($application));
    $assertion = McpDelegatedAssertionFixture::verified(ConsoleRole::Member);
    $actor = DelegatedActor::recordHandoff($assertion);
    RequestAssertion::publish(request(), $actor, $assertion);
    $localUser = User::factory()->create(['id' => $actor->getKey()]);
    $credential = Credential::query()->firstOrFail();
    ReplayView::query()->create([
        'actor_id' => (string) $actor->getKey(),
        'application_id' => $application->getKey(),
        'recording_session_id' => $session->getKey(),
        'viewed_at' => now(),
    ]);

    $watched = reelMcpPayload(ReelMcpServer::tool(SessionsTool::class, [
        'application' => $application->public_id,
        'watched' => 'yes',
    ])->assertOk());
    $unwatched = reelMcpPayload(ReelMcpServer::tool(SessionsTool::class, [
        'application' => $application->public_id,
        'watched' => 'no',
    ])->assertOk());

    expect($actor->getKey())->toBe($localUser->getKey())
        ->and($actor->getAuthIdentifier())->toBe('bfc-console:'.$actor->getKey())
        ->and($actor->getAuthIdentifier())->not->toBe((string) $localUser->getKey())
        ->and($actor->getAuthIdentifier())->not->toBe((string) $credential->getKey())
        ->and($watched['sessions'])->toBeEmpty()
        ->and($unwatched['sessions'])->toHaveCount(1)
        ->and($unwatched['sessions'][0]['session_id'])->toBe($session->session_id);
});

it('never compares a store credential key to human replay-view actor ids', function (): void {
    $application = Application::factory()->create();
    $session = makeMcpRecordingSession($application, activeReelCredential($application));
    $credential = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'reel-mcp-watched-test',
        'abilities' => [OperatorAbility::McpRead->value],
    ])->credential;
    ReplayView::query()->create([
        'actor_id' => (string) $credential->getKey(),
        'application_id' => $application->getKey(),
        'recording_session_id' => $session->getKey(),
        'viewed_at' => now(),
    ]);
    Auth::setUser($credential);

    $watched = reelMcpPayload(ReelMcpServer::tool(SessionsTool::class, [
        'application' => $application->public_id,
        'watched' => 'yes',
    ])->assertOk());
    $unwatched = reelMcpPayload(ReelMcpServer::tool(SessionsTool::class, [
        'application' => $application->public_id,
        'watched' => 'no',
    ])->assertOk());

    expect($watched['sessions'])->toBeEmpty()
        ->and($unwatched['sessions'])->toHaveCount(1)
        ->and($unwatched['sessions'][0]['session_id'])->toBe($session->session_id);
});

it('refuses foreign application session ids indistinguishably for content and links', function (): void {
    $local = Application::factory()->create();
    $foreign = Application::factory()->create();
    $foreignSession = makeMcpRecordingSession($foreign, activeReelCredential($foreign));
    $missing = str_repeat('f', 64);

    foreach ([SessionContentTool::class, SessionDeepLinkTool::class] as $tool) {
        $foreignResponse = reelMcpPayload(ReelMcpServer::tool($tool, [
            'application' => $local->public_id,
            'session_id' => $foreignSession->session_id,
        ])->assertOk());
        $missingResponse = reelMcpPayload(ReelMcpServer::tool($tool, [
            'application' => $local->public_id,
            'session_id' => $missing,
        ])->assertOk());

        expect($foreignResponse)->toBe($missingResponse)->toBe(['error' => 'session_not_found']);
    }
});

it('returns the existing replay link below the relay cap with a maximum legal request id', function (): void {
    $this->freezeTime();
    $application = Application::factory()->create();
    $session = makeMcpRecordingSession($application, activeReelCredential($application));
    $credential = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'reel-mcp-deep-link-budget-test',
        'abilities' => [OperatorAbility::McpRead->value],
    ]);
    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => maximumMcpRequestId(), 'method' => 'tools/call',
        'params' => ['name' => 'session_deep_link', 'arguments' => [
            'application' => $application->public_id,
            'session_id' => $session->session_id,
            'start' => 500,
        ]],
    ], [
        'Authorization' => $credential->bearerHeader(),
        'Accept' => 'application/json, text/event-stream',
    ])->assertOk()->assertJsonPath('result.isError', false);
    expect(strlen((string) $response->getContent()))->toBeLessThan(1_048_576);
    $payload = $response->json('result.structuredContent');
    expect($payload)->toBeArray();
    parse_str((string) parse_url((string) $payload['url'], PHP_URL_QUERY), $query);

    expect((int) $query['expires'] - now()->getTimestamp())->toBe(300)
        ->and($query['start'])->toBe('500')
        ->and($payload['authentication_required'])->toBeTrue()
        ->and($payload['cache_control'])->toBe('no-store, private')
        ->and($payload['url'])->not->toContain('bearer')
        ->and($payload['url'])->not->toContain('grant')
        ->and($payload['url'])->not->toContain('private_key');

    Auth::forgetGuards();
    $this->get($payload['url'])->assertRedirect(route('bfc.login'));
    $this->actingAs(User::factory()->create())
        ->get($payload['url'])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('invalidates a content cursor when the immutable manifest identity changes', function (): void {
    $application = Application::factory()->create();
    $session = makeMcpRecordingSession($application, activeReelCredential($application), [
        ['type' => 3, 'timestamp' => 1_000, 'data' => ['source' => 3, 'id' => 1, 'x' => 0, 'y' => 1]],
        ['type' => 3, 'timestamp' => 1_001, 'data' => ['source' => 3, 'id' => 1, 'x' => 0, 'y' => 2]],
    ]);
    $first = reelMcpPayload(ReelMcpServer::tool(SessionContentTool::class, [
        'application' => $application->public_id,
        'session_id' => $session->session_id,
        'limit' => 1,
    ])->assertOk());
    $session->forceFill(['manifest_checksum' => str_repeat('f', 64)])->save();

    ReelMcpServer::tool(SessionContentTool::class, [
        'application' => $application->public_id,
        'session_id' => $session->session_id,
        'limit' => 1,
        'cursor' => $first['next_cursor'],
    ])->assertHasErrors();
});

it('rejects malformed schemas and runtime arguments', function (string $tool, array $arguments): void {
    ReelMcpServer::tool($tool, $arguments)->assertHasErrors();
})->with([
    'unknown top-level key' => [SessionsTool::class, ['application' => 'app', 'extra' => true]],
    'numeric top-level key' => [SessionsTool::class, ['application' => 'app', 0 => 'bypass']],
    'unknown enum' => [SessionsTool::class, ['application' => 'app', 'status' => 'unknown']],
    'invalid cursor' => [SessionsTool::class, ['application' => 'app', 'cursor' => 'invalid']],
    'limit below minimum' => [SessionsTool::class, ['application' => 'app', 'limit' => 0]],
    'limit above maximum' => [SessionsTool::class, ['application' => 'app', 'limit' => 101]],
    'flat object where list expected' => [SessionContentTool::class, [
        'application' => 'app', 'session_id' => str_repeat('a', 64), 'types' => ['kind' => 'dom'],
    ]],
    'unknown list enum' => [SessionContentTool::class, [
        'application' => 'app', 'session_id' => str_repeat('a', 64), 'types' => ['raw_network_body'],
    ]],
    'too many raw list items before deduplication' => [SessionContentTool::class, [
        'application' => 'app', 'session_id' => str_repeat('a', 64),
        'types' => ['dom', 'dom', 'click', 'scroll', 'error'],
    ]],
]);
