<?php

declare(strict_types=1);

use App\Enums\RecordingSessionStatus;
use App\Mcp\ReelMcpServer;
use App\Mcp\Tools\SessionContentTool;
use App\Mcp\Tools\SessionDeepLinkTool;
use App\Mcp\Tools\SessionsTool;
use App\Models\Application;
use App\Models\RecordingSession;
use App\Models\ReplayView;
use App\Services\ReplayManifest;
use App\Services\ReplayPayloadReader;
use ArtisanBuild\BuiltForCloud\Console\ConsoleRole;
use ArtisanBuild\BuiltForCloud\Console\DelegatedActor;
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
use ArtisanBuild\BuiltForCloud\Testing\McpDelegatedTools;
use ArtisanBuild\BuiltForCloud\Testing\McpProductAdmission;
use ArtisanBuild\BuiltForCloud\Testing\WithCredentials;
use ArtisanBuild\ReelClient\Envelope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\Support\User;

uses(WithCredentials::class);

/** @param list<array<string, mixed>> $events @param array<string, mixed> $attributes */
function makeMcpRecordingSession(Application $application, Credential $credential, array $events = [], array $attributes = []): RecordingSession
{
    $sessionId = $attributes['session_id'] ?? bin2hex(random_bytes(32));
    $manifest = null;
    $manifestChecksum = null;
    $compressedBytes = 0;

    if ($events !== []) {
        $encoded = json_encode($events, JSON_THROW_ON_ERROR)."\n";
        $compressed = gzencode($encoded);
        expect($compressed)->toBeString();
        $key = "reel/chunks/{$application->public_id}/{$sessionId}/replay.jsonl.gz";
        Storage::disk('local')->put($key, $compressed);
        $manifest = [
            'manifest_version' => 1,
            'envelope_version' => Envelope::VERSION,
            'rrweb_version' => Envelope::RRWEB_VERSION,
            'compression' => Envelope::COMPRESSION,
            'objects' => [[
                'key' => $key,
                'checksum' => hash('sha256', $compressed),
                'bytes' => strlen($compressed),
            ]],
            'event_started_at' => 1_000,
            'event_ended_at' => 1_000 + count($events),
            'epoch_count' => 1,
            'chunk_count' => 1,
            'gap_count' => 0,
            'incomplete' => false,
            'incomplete_reasons' => [],
            'compaction_state' => 'ready',
        ];
        $manifestChecksum = resolve(ReplayManifest::class)->checksum($manifest);
        $compressedBytes = strlen($compressed);
    }

    $session = new RecordingSession;
    $session->fill(array_merge([
        'application_id' => $application->getKey(),
        'application_credential_id' => $credential->getKey(),
        'session_id' => $sessionId,
        'grant_id_hash' => hash('sha256', $sessionId),
        'origin' => 'https://monitored.example',
        'protocol_version' => Envelope::VERSION,
        'max_chunks' => 500,
        'max_compressed_bytes' => 10_000_000,
        'max_chunk_bytes' => 1_000_000,
        'chunk_count' => $events === [] ? 0 : 1,
        'compressed_bytes' => $compressedBytes,
        'epoch_count' => 1,
        'started_at' => now(),
        'max_event_time' => now()->addHour(),
        'upload_cutoff_at' => now()->addHour(),
        'ended_at' => now()->addMinute(),
        'maximum_expires_at' => now()->addDays(30),
        'status_changed_at' => now(),
        'is_complete' => true,
        'incomplete_reasons' => [],
        'gap_count' => 0,
        'duration_seconds' => 60,
        'initial_path' => '/start',
        'latest_path' => '/finish',
    ], $attributes));
    $session->forceFill([
        'status' => RecordingSessionStatus::Ready,
        'manifest' => $manifest,
        'manifest_checksum' => $manifestChecksum,
        'compacted_at' => $manifest === null ? null : now(),
    ])->save();

    return $session->fresh(['application']);
}

/** @return array<string, mixed> */
function reelMcpPayload(TestResponse $response): array
{
    $method = new ReflectionMethod($response, 'structuredContent');
    $payload = $method->invoke($response);

    expect($payload)->toBeArray();

    return $payload;
}

beforeEach(function (): void {
    RequestEffectCeiling::publish(resolve('request'), Effect::Read->value);
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
});

it('mounts and advertises exactly one delegated effect-scoped read door', function (): void {
    McpDelegatedTools::assertConforms(ReelMcpServer::class);
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

it('fragments a privacy-validated oversized event below the relay body cap without data loss', function (): void {
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
            'jsonrpc' => '2.0', 'id' => 99, 'method' => 'tools/call',
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
    $actor = DelegatedActor::query()->create([
        'identity_hash' => DelegatedActor::identityHash('https://scalpels.test', 'operator-7'),
        'issuer' => 'https://scalpels.test',
        'subject' => 'operator-7',
        'last_handoff_display_name' => 'Operator Seven',
        'last_handoff_role' => ConsoleRole::Admin,
    ]);
    ReplayView::query()->create([
        'actor_id' => $actor->getAuthIdentifier(),
        'application_id' => $application->getKey(),
        'recording_session_id' => $session->getKey(),
        'viewed_at' => now(),
    ]);
    Auth::setUser($actor);

    $payload = reelMcpPayload(ReelMcpServer::tool(SessionsTool::class, [
        'application' => $application->public_id,
        'watched' => 'yes',
    ])->assertOk());

    expect($actor->getAuthIdentifier())->toStartWith('bfc-console:')
        ->and($payload['sessions'])->toHaveCount(1)
        ->and($payload['sessions'][0]['session_id'])->toBe($session->session_id);
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

it('returns the existing five minute signed authenticated no-store replay link without credentials', function (): void {
    $this->freezeTime();
    $application = Application::factory()->create();
    $session = makeMcpRecordingSession($application, activeReelCredential($application));
    $payload = reelMcpPayload(ReelMcpServer::tool(SessionDeepLinkTool::class, [
        'application' => $application->public_id,
        'session_id' => $session->session_id,
        'start' => 500,
    ])->assertOk());
    parse_str((string) parse_url((string) $payload['url'], PHP_URL_QUERY), $query);

    expect((int) $query['expires'] - now()->getTimestamp())->toBe(300)
        ->and($query['start'])->toBe('500')
        ->and($payload['authentication_required'])->toBeTrue()
        ->and($payload['cache_control'])->toBe('no-store, private')
        ->and($payload['url'])->not->toContain('bearer')
        ->and($payload['url'])->not->toContain('grant')
        ->and($payload['url'])->not->toContain('private_key');

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
