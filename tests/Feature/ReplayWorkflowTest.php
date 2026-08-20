<?php

declare(strict_types=1);

use App\Enums\RecordingSessionStatus;
use App\Livewire\Sessions\Index;
use App\Models\Application;
use App\Models\ApplicationCredential;
use App\Models\RecordingSession;
use App\Models\ReplayView;
use App\Models\User;
use App\Services\ReplayManifest;
use ArtisanBuild\ReelClient\Envelope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/** @return list<array<string, mixed>> */
function replayEvents(string $text = 'Safe replay'): array
{
    return [[
        'type' => 2,
        'timestamp' => 1_000,
        'data' => [
            'node' => [
                'type' => 2,
                'id' => 1,
                'tagName' => 'div',
                'attributes' => [],
                'childNodes' => [[
                    'type' => 3,
                    'id' => 2,
                    'textContent' => $text,
                ]],
            ],
        ],
    ]];
}

/**
 * @param  list<array<string, mixed>>  $events
 * @param  array<string, mixed>  $attributes
 */
function makeReplaySession(array $events = [], array $attributes = []): RecordingSession
{
    $events = $events === [] ? replayEvents() : $events;
    $application = $attributes['application'] ?? Application::factory()->create();
    $status = $attributes['status'] ?? RecordingSessionStatus::Ready;
    unset($attributes['application'], $attributes['status']);
    $credential = ApplicationCredential::factory()->for($application)->create();
    $sessionId = $attributes['session_id'] ?? bin2hex(random_bytes(32));
    $objectKey = "reel/chunks/{$application->public_id}/{$sessionId}/replay.jsonl.gz";
    $encoded = json_encode($events, JSON_THROW_ON_ERROR)."\n";
    $compressed = gzencode($encoded);

    if ($compressed === false) {
        throw new RuntimeException('Unable to create replay fixture.');
    }

    Storage::disk('local')->put($objectKey, $compressed);
    $reader = resolve(ReplayManifest::class);
    $manifest = [
        'manifest_version' => 1,
        'envelope_version' => Envelope::VERSION,
        'rrweb_version' => Envelope::RRWEB_VERSION,
        'compression' => Envelope::COMPRESSION,
        'objects' => [[
            'key' => $objectKey,
            'checksum' => hash('sha256', $compressed),
            'bytes' => strlen($compressed),
        ]],
        'event_started_at' => 1_000,
        'event_ended_at' => 1_000,
        'epoch_count' => 1,
        'chunk_count' => 1,
        'gap_count' => 0,
        'incomplete' => true,
        'incomplete_reasons' => ['missing_terminal_sequence:epoch-1'],
        'compaction_state' => 'ready',
    ];
    $session = new RecordingSession;
    $session->fill(array_merge([
        'application_id' => $application->getKey(),
        'application_credential_id' => $credential->getKey(),
        'session_id' => $sessionId,
        'grant_id_hash' => hash('sha256', $sessionId),
        'origin' => 'https://monitored.example',
        'protocol_version' => Envelope::VERSION,
        'max_chunks' => 10,
        'max_compressed_bytes' => 1_000_000,
        'max_chunk_bytes' => 100_000,
        'chunk_count' => 1,
        'compressed_bytes' => strlen($compressed),
        'epoch_count' => 1,
        'started_at' => now()->subMinutes(2),
        'max_event_time' => now()->addMinutes(28),
        'upload_cutoff_at' => now()->addMinutes(29),
        'ended_at' => now()->subMinute(),
        'maximum_expires_at' => now()->addDays(30),
        'status_changed_at' => now(),
        'is_complete' => false,
        'incomplete_reasons' => ['missing_terminal_sequence:epoch-1'],
        'gap_count' => 0,
        'duration_seconds' => 60,
        'initial_path' => '/start',
        'latest_path' => '/finish',
        'manifest' => $manifest,
        'manifest_checksum' => $reader->checksum($manifest),
        'compacted_at' => now(),
    ], $attributes));
    $session->forceFill([
        'status' => $status,
        'manifest' => $manifest,
        'manifest_checksum' => $reader->checksum($manifest),
        'compacted_at' => now(),
    ]);
    $session->save();

    return $session->fresh(['application']);
}

function signedReplayUrl(RecordingSession $session, ?DateTimeInterface $expires = null): string
{
    return URL::temporarySignedRoute('sessions.player', $expires ?? now()->addMinute(), [
        'application' => $session->application,
        'recordingSession' => $session,
        'channel' => str_repeat('a', 96),
    ]);
}

function rewriteReplayManifest(RecordingSession $session, callable $change): void
{
    $manifest = $session->manifest;
    $change($manifest);
    $session->forceFill([
        'manifest' => $manifest,
        'manifest_checksum' => resolve(ReplayManifest::class)->checksum($manifest),
    ])->save();
}

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
});

it('allows every authenticated viewer to list and inspect sessions while blocking guests', function (): void {
    $session = makeReplaySession();

    $this->get(route('sessions.index'))->assertRedirect(route('login'));
    $this->get(route('sessions.show', [
        'application' => $session->application,
        'recordingSession' => $session,
    ]))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create());
    $response = $this->get(route('sessions.show', [
        'application' => $session->application,
        'recordingSession' => $session,
    ]));
    $response->assertOk()->assertSee($session->session_id);
});

it('composes every session filter from the URL without reading replay objects', function (): void {
    $viewer = User::factory()->create();
    $application = Application::factory()->create();
    $matching = makeReplaySession(attributes: [
        'application' => $application,
        'application_user_id' => 'customer-42',
        'release_id' => 'deploy-9',
        'protected_at' => now(),
        'protected_by' => $viewer->getKey(),
    ]);
    $other = makeReplaySession();
    $matching->markers()->create([
        'application_id' => $application->getKey(),
        'marker_type' => 'error',
        'occurred_at' => 1_000,
        'metadata' => [],
    ]);
    ReplayView::query()->create([
        'user_id' => $viewer->getKey(),
        'application_id' => $application->getKey(),
        'recording_session_id' => $matching->getKey(),
        'viewed_at' => now(),
    ]);
    $query = [
        'startedFrom' => $matching->started_at->subSecond()->format('Y-m-d\TH:i'),
        'startedTo' => $matching->started_at->addSecond()->format('Y-m-d\TH:i:s'),
        'endedFrom' => $matching->ended_at->subSecond()->format('Y-m-d\TH:i'),
        'endedTo' => $matching->ended_at->addSecond()->format('Y-m-d\TH:i:s'),
        'durationMin' => '60',
        'durationMax' => '60',
        'application' => $application->public_id,
        'path' => '/finish',
        'session_id' => $matching->session_id,
        'user_id' => 'customer-42',
        'release' => 'deploy-9',
        'status' => 'ready',
        'marker' => 'error',
        'protected' => 'yes',
        'watched' => 'yes',
    ];

    $this->actingAs($viewer);
    Storage::shouldReceive('disk')->never();

    $component = Livewire::withQueryParams($query)->test(Index::class);
    $component->assertSee($matching->session_id)->assertDontSee($other->session_id);

    foreach ($query as $property => $value) {
        $component->assertSet(match ($property) {
            'application' => 'applicationId',
            'session_id' => 'sessionId',
            'user_id' => 'applicationUserId',
            'release' => 'releaseId',
            'marker' => 'markerType',
            default => $property,
        }, $value);
    }
});

it('discriminates each session list predicate independently', function (string $case): void {
    $viewer = User::factory()->create();
    $application = Application::factory()->create();
    $matchingAttributes = ['application' => $application];
    $otherAttributes = ['application' => $application];
    $query = [];

    if ($case === 'started-from') {
        $matchingAttributes['started_at'] = now();
        $otherAttributes['started_at'] = now()->subDay();
        $query['startedFrom'] = now()->subMinute()->format('Y-m-d\TH:i:s');
    } elseif ($case === 'started-to') {
        $matchingAttributes['started_at'] = now()->subDay();
        $otherAttributes['started_at'] = now();
        $query['startedTo'] = now()->subHours(12)->format('Y-m-d\TH:i:s');
    } elseif ($case === 'ended-from') {
        $matchingAttributes['ended_at'] = now();
        $otherAttributes['ended_at'] = now()->subDay();
        $query['endedFrom'] = now()->subMinute()->format('Y-m-d\TH:i:s');
    } elseif ($case === 'ended-to') {
        $matchingAttributes['ended_at'] = now()->subDay();
        $otherAttributes['ended_at'] = now();
        $query['endedTo'] = now()->subHours(12)->format('Y-m-d\TH:i:s');
    } elseif ($case === 'duration-min') {
        $matchingAttributes['duration_seconds'] = 100;
        $otherAttributes['duration_seconds'] = 50;
        $query['durationMin'] = '100';
    } elseif ($case === 'duration-max') {
        $matchingAttributes['duration_seconds'] = 50;
        $otherAttributes['duration_seconds'] = 100;
        $query['durationMax'] = '50';
    } elseif ($case === 'application') {
        $otherAttributes['application'] = Application::factory()->create();
        $query['application'] = $application->public_id;
    } elseif ($case === 'initial-path') {
        $matchingAttributes['initial_path'] = '/matching-initial';
        $matchingAttributes['latest_path'] = '/other-latest';
        $otherAttributes['initial_path'] = '/other-initial';
        $otherAttributes['latest_path'] = '/other-latest';
        $query['path'] = '/matching-initial';
    } elseif ($case === 'latest-path') {
        $matchingAttributes['initial_path'] = '/other-initial';
        $matchingAttributes['latest_path'] = '/matching-latest';
        $otherAttributes['initial_path'] = '/other-initial';
        $otherAttributes['latest_path'] = '/other-latest';
        $query['path'] = '/matching-latest';
    } elseif ($case === 'user-id') {
        $matchingAttributes['application_user_id'] = 'matching-user';
        $otherAttributes['application_user_id'] = 'other-user';
        $query['user_id'] = 'matching-user';
    } elseif ($case === 'release') {
        $matchingAttributes['release_id'] = 'matching-release';
        $otherAttributes['release_id'] = 'other-release';
        $query['release'] = 'matching-release';
    } elseif ($case === 'status') {
        $matchingAttributes['status'] = RecordingSessionStatus::Ready;
        $otherAttributes['status'] = RecordingSessionStatus::Failed;
        $query['status'] = RecordingSessionStatus::Ready->value;
    } elseif ($case === 'protected-yes') {
        $matchingAttributes['protected_at'] = now();
        $matchingAttributes['protected_by'] = $viewer->getKey();
        $query['protected'] = 'yes';
    } elseif ($case === 'protected-no') {
        $otherAttributes['protected_at'] = now();
        $otherAttributes['protected_by'] = $viewer->getKey();
        $query['protected'] = 'no';
    }

    $matching = makeReplaySession(attributes: $matchingAttributes);
    $other = makeReplaySession(attributes: $otherAttributes);

    if ($case === 'session-id') {
        $query['session_id'] = $matching->session_id;
    } elseif ($case === 'marker') {
        $matching->markers()->create([
            'application_id' => $matching->application_id,
            'marker_type' => 'matching-marker',
            'occurred_at' => 1_000,
            'metadata' => [],
        ]);
        $other->markers()->create([
            'application_id' => $other->application_id,
            'marker_type' => 'other-marker',
            'occurred_at' => 1_000,
            'metadata' => [],
        ]);
        $query['marker'] = 'matching-marker';
    } elseif ($case === 'watched-yes') {
        ReplayView::query()->create([
            'user_id' => $viewer->getKey(),
            'application_id' => $matching->application_id,
            'recording_session_id' => $matching->getKey(),
            'viewed_at' => now(),
        ]);
        $query['watched'] = 'yes';
    } elseif ($case === 'watched-no') {
        ReplayView::query()->create([
            'user_id' => $viewer->getKey(),
            'application_id' => $other->application_id,
            'recording_session_id' => $other->getKey(),
            'viewed_at' => now(),
        ]);
        $query['watched'] = 'no';
    }

    $this->actingAs($viewer);
    Livewire::withQueryParams($query)
        ->test(Index::class)
        ->assertSee($matching->session_id)
        ->assertDontSee($other->session_id);
})->with([
    'started from' => 'started-from',
    'started to' => 'started-to',
    'ended from' => 'ended-from',
    'ended to' => 'ended-to',
    'duration min' => 'duration-min',
    'duration max' => 'duration-max',
    'application' => 'application',
    'initial path' => 'initial-path',
    'latest path' => 'latest-path',
    'session id' => 'session-id',
    'application user id' => 'user-id',
    'release' => 'release',
    'status' => 'status',
    'marker' => 'marker',
    'protected yes' => 'protected-yes',
    'protected no' => 'protected-no',
    'watched yes' => 'watched-yes',
    'watched no' => 'watched-no',
]);

it('backs every session list predicate and ordering field with named indexes', function (): void {
    $indexes = DB::table('pg_indexes')
        ->whereIn('tablename', ['applications', 'recording_sessions', 'recording_markers', 'replay_views'])
        ->pluck('indexname')
        ->all();

    expect($indexes)->toContain(
        'applications_public_id_unique',
        'applications_name_idx',
        'recording_sessions_application_id_idx',
        'recording_sessions_started_at_idx',
        'recording_sessions_ended_at_idx',
        'recording_sessions_duration_seconds_idx',
        'recording_sessions_initial_path_idx',
        'recording_sessions_latest_path_idx',
        'recording_sessions_session_id_idx',
        'recording_sessions_application_user_id_idx',
        'recording_sessions_release_id_idx',
        'recording_sessions_status_idx',
        'recording_sessions_protected_at_idx',
        'recording_markers_session_type_idx',
        'replay_views_user_session_idx',
    );
});

it('keeps every replay object disk private', function (): void {
    expect(config('filesystems.disks.local.visibility'))->toBe('private')
        ->and(config('filesystems.disks.s3.visibility'))->toBe('private')
        ->and(config('filesystems.default'))->not->toBe('public');
});

it('orders the session list newest first', function (): void {
    $viewer = User::factory()->create();
    $older = makeReplaySession(attributes: ['started_at' => now()->subHour()]);
    $newer = makeReplaySession(attributes: ['started_at' => now()]);

    $this->actingAs($viewer)
        ->get(route('sessions.index'))
        ->assertOk()
        ->assertSeeInOrder([$newer->session_id, $older->session_id]);
});

it('shows metadata and timeline before download and distinguishes uncertainty from detected loss', function (): void {
    $viewer = User::factory()->create();
    $session = makeReplaySession(attributes: [
        'incomplete_reasons' => [
            'missing_terminal_sequence:epoch-1',
            'missing_full_snapshot:epoch-2',
            'failed_epoch:epoch-3',
        ],
    ]);
    $session->transitions()->create([
        'previous_state' => 'compacting',
        'new_state' => 'ready',
        'reason' => 'manifest_published',
        'attempt' => 1,
        'transitioned_at' => now(),
    ]);

    $this->actingAs($viewer);
    Storage::shouldReceive('disk')->never();

    $content = $this->get(route('sessions.show', [
        'application' => $session->application,
        'recordingSession' => $session,
    ]))
        ->assertOk()
        ->assertSee('Recording metadata')
        ->assertSee('Timeline')
        ->assertSee('Completeness not confirmed')
        ->assertSee('number of gaps is not determinable')
        ->assertSee('Missing replay data detected')
        ->assertSee('Missing full snapshot: epoch-2')
        ->assertSee('Not determinable')
        ->getContent();
    preg_match('/data-test="completeness-missing-data"[^>]*>(.*?)<\/section>/s', (string) $content, $detectedLoss);

    expect($detectedLoss[1] ?? null)->toBeString()
        ->not->toContain('Missing terminal sequence');
});

it('uses the exact opaque-origin sandbox and no-referrer iframe attributes', function (): void {
    $session = makeReplaySession();
    $content = $this->actingAs(User::factory()->create())
        ->get(route('sessions.show', [
            'application' => $session->application,
            'recordingSession' => $session,
        ]))
        ->assertOk()
        ->getContent();

    preg_match('/<iframe[^>]*\ssandbox="([^"]*)"[^>]*>/s', (string) $content, $sandbox);
    preg_match('/<iframe[^>]*\sreferrerpolicy="([^"]*)"[^>]*>/s', (string) $content, $referrer);

    expect($sandbox[1] ?? null)->toBe('allow-scripts')
        ->and($referrer[1] ?? null)->toBe('no-referrer');
});

it('issues a distinct per-player channel nonce on each detail response', function (): void {
    $viewer = User::factory()->create();
    $session = makeReplaySession();
    $url = route('sessions.show', [
        'application' => $session->application,
        'recordingSession' => $session,
    ]);
    $first = $this->actingAs($viewer)->get($url)->getContent();
    $second = $this->get($url)->getContent();
    preg_match('/([a-f0-9]{96})/', (string) $first, $firstNonce);
    preg_match('/([a-f0-9]{96})/', (string) $second, $secondNonce);

    expect($firstNonce[1] ?? null)->toMatch('/^[a-f0-9]{96}$/')
        ->and($secondNonce[1] ?? null)->toMatch('/^[a-f0-9]{96}$/')
        ->and($secondNonce[1])->not->toBe($firstNonce[1]);
});

it('serves a valid replay under an exact default-deny CSP and records the attributed view', function (): void {
    $viewer = User::factory()->create();
    $session = makeReplaySession(replayEvents('Visible safe content'));
    $url = signedReplayUrl($session);
    $firstResponse = $this->actingAs($viewer)->get($url)->assertOk();
    $secondResponse = $this->get($url)->assertOk();
    $content = $firstResponse->getContent();
    preg_match('/nonce="([a-f0-9]{48})"/', (string) $content, $firstNonce);
    preg_match('/nonce="([a-f0-9]{48})"/', (string) $secondResponse->getContent(), $secondNonce);

    expect($firstNonce[1] ?? null)->toBeString()
        ->and($secondNonce[1] ?? null)->toBeString()
        ->and($secondNonce[1])->not->toBe($firstNonce[1])
        ->and($firstResponse->headers->get('Content-Security-Policy'))->toBe(
            "default-src 'none'; script-src 'nonce-{$firstNonce[1]}'; style-src 'unsafe-inline'",
        )
        ->and($firstResponse->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($content)->toContain('http-equiv="Content-Security-Policy"')
        ->and($content)->toContain("default-src &#039;none&#039;; script-src &#039;nonce-{$firstNonce[1]}&#039;; style-src &#039;unsafe-inline&#039;")
        ->and($content)->toContain('Visible safe content');
    $views = ReplayView::query()
        ->where('user_id', $viewer->getKey())
        ->where('recording_session_id', $session->getKey())
        ->get();
    expect($views)->toHaveCount(2)
        ->and($views->every(fn (ReplayView $view): bool => $view->viewed_at !== null))->toBeTrue();
    expect(DB::getSchemaBuilder()->getColumnListing('replay_views'))
        ->not->toContain('dom', 'events', 'payload', 'manifest');
});

it('mints a fresh signed player URL only when replay loading is requested', function (): void {
    $viewer = User::factory()->create();
    $session = makeReplaySession();
    $detail = $this->actingAs($viewer)->get(route('sessions.show', [
        'application' => $session->application,
        'recordingSession' => $session,
    ]))->assertOk();

    expect($detail->getContent())->not->toContain('signature=');

    $this->travel(6)->minutes();
    $delivery = $this->getJson(route('sessions.player-url', [
        'application' => $session->application,
        'recordingSession' => $session,
        'channel' => str_repeat('a', 96),
        'start' => 0,
    ]))->assertOk()->assertJsonStructure(['url']);
    $url = (string) $delivery->json('url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $signedQuery);
    $ttl = ((int) ($signedQuery['expires'] ?? 0)) - now()->getTimestamp();

    expect($ttl)->toBeGreaterThanOrEqual(299)->toBeLessThanOrEqual(300);
    $this->get($url)->assertOk();
});

it('never serves hostile captured DOM and returns a diagnostic without captured content', function (): void {
    $hostile = [[
        'type' => 2,
        'timestamp' => 1_000,
        'data' => ['node' => [
            'type' => 2,
            'id' => 1,
            'tagName' => 'div',
            'attributes' => ['style' => 'background:url(https://canary.example/css)'],
            'childNodes' => [
                ['type' => 2, 'id' => 2, 'tagName' => 'script', 'attributes' => [], 'childNodes' => [['type' => 3, 'id' => 3, 'textContent' => 'window.hostileRan=true']]],
                ['type' => 2, 'id' => 4, 'tagName' => 'img', 'attributes' => ['onerror' => 'window.hostileRan=true', 'srcset' => 'https://canary.example/image 1x'], 'childNodes' => []],
                ['type' => 2, 'id' => 5, 'tagName' => 'use', 'attributes' => ['href' => 'https://canary.example/symbol'], 'childNodes' => []],
                ['type' => 2, 'id' => 6, 'tagName' => 'form', 'attributes' => ['action' => 'https://canary.example/form'], 'childNodes' => []],
                ['type' => 2, 'id' => 7, 'tagName' => 'img', 'attributes' => ['src' => 'data:text/html,hostile'], 'childNodes' => []],
            ],
        ]],
    ]];
    $session = makeReplaySession($hostile);
    $content = $this->actingAs(User::factory()->create())
        ->get(signedReplayUrl($session))
        ->assertOk()
        ->assertSee('unsafe_payload')
        ->getContent();

    expect($content)
        ->not->toContain('window.hostileRan=true')
        ->not->toContain('onerror')
        ->not->toContain('canary.example')
        ->not->toContain('data:text/html');
});

it('hex encodes valid captured text that could terminate the replay data element', function (): void {
    $breakout = '</script><script>window.replayBreakout=true</script>';
    $session = makeReplaySession(replayEvents($breakout));
    $content = $this->actingAs(User::factory()->create())
        ->get(signedReplayUrl($session))
        ->assertOk()
        ->getContent();

    expect($content)->not->toContain($breakout)
        ->and($content)->toContain('\\u003C');
});

it('shows diagnostics for missing corrupt checksum-mismatched and incompatible objects', function (string $case, string $diagnostic): void {
    $session = makeReplaySession();
    $object = $session->manifest['objects'][0];

    if ($case === 'missing') {
        Storage::disk('local')->delete($object['key']);
    } elseif ($case === 'corrupt') {
        Storage::disk('local')->put($object['key'], 'not-gzip');
        rewriteReplayManifest($session, function (array &$manifest): void {
            $manifest['objects'][0]['bytes'] = 8;
            $manifest['objects'][0]['checksum'] = hash('sha256', 'not-gzip');
        });
    } elseif ($case === 'checksum') {
        $bytes = Storage::disk('local')->get($object['key']);
        Storage::disk('local')->put($object['key'], substr((string) $bytes, 0, -1).chr(ord(substr((string) $bytes, -1)) ^ 1));
    } elseif ($case === 'trailing') {
        $bytes = Storage::disk('local')->get($object['key']).'trailing';
        Storage::disk('local')->put($object['key'], $bytes);
        rewriteReplayManifest($session, function (array &$manifest) use ($bytes): void {
            $manifest['objects'][0]['bytes'] = strlen($bytes);
            $manifest['objects'][0]['checksum'] = hash('sha256', $bytes);
        });
    } else {
        rewriteReplayManifest($session, function (array &$manifest): void {
            $manifest['rrweb_version'] = '999.0.0';
        });
    }

    $this->actingAs(User::factory()->create())
        ->get(signedReplayUrl($session->fresh(['application'])))
        ->assertOk()
        ->assertSee($diagnostic);
})->with([
    'missing object' => ['missing', 'missing_object'],
    'corrupt object' => ['corrupt', 'corrupt_object'],
    'checksum mismatch' => ['checksum', 'checksum_mismatch'],
    'trailing bytes' => ['trailing', 'corrupt_object'],
    'incompatible object' => ['incompatible', 'incompatible_version'],
]);

it('shows replay_not_ready for a session that has not reached ready state', function (): void {
    $session = makeReplaySession(attributes: ['status' => RecordingSessionStatus::Recording]);

    $this->actingAs(User::factory()->create())
        ->get(signedReplayUrl($session))
        ->assertOk()
        ->assertSee('replay_not_ready');

    expect(ReplayView::query()->where('recording_session_id', $session->getKey())->count())->toBe(0);
});

it('does not mark a diagnostic replay as watched', function (): void {
    $viewer = User::factory()->create();
    $session = makeReplaySession();
    Storage::disk('local')->delete($session->manifest['objects'][0]['key']);

    $this->actingAs($viewer)
        ->get(signedReplayUrl($session))
        ->assertOk()
        ->assertSee('missing_object');

    expect(ReplayView::query()->where('recording_session_id', $session->getKey())->count())->toBe(0);

    Livewire::withQueryParams(['watched' => 'no'])
        ->test(Index::class)
        ->assertSee($session->session_id);
});

it('rejects cross-session object keys before reading them', function (): void {
    $session = makeReplaySession();
    rewriteReplayManifest($session, function (array &$manifest): void {
        $manifest['objects'][0]['key'] = 'reel/chunks/another-application/another-session/replay.jsonl.gz';
    });

    $this->actingAs(User::factory()->create())
        ->get(signedReplayUrl($session->fresh(['application'])))
        ->assertOk()
        ->assertSee('invalid_manifest');
});

it('requires slash-delimited application and session object-key boundaries', function (string $segment): void {
    $session = makeReplaySession();
    rewriteReplayManifest($session, function (array &$manifest) use ($segment): void {
        $parts = explode('/', $manifest['objects'][0]['key']);
        $parts[$segment === 'application' ? 2 : 3] .= '0';
        $manifest['objects'][0]['key'] = implode('/', $parts);
    });

    $this->actingAs(User::factory()->create())
        ->get(signedReplayUrl($session->fresh(['application'])))
        ->assertOk()
        ->assertSee('invalid_manifest');
})->with([
    'application app-1 does not match app-10' => 'application',
    'session id prefix does not match a longer id' => 'session',
]);

it('caps compressed and decompressed replay objects', function (string $setting): void {
    config()->set("replay.{$setting}", 1);
    $session = makeReplaySession();

    $this->actingAs(User::factory()->create())
        ->get(signedReplayUrl($session))
        ->assertOk()
        ->assertSee('corrupt_object');
})->with([
    'compressed bytes' => 'maximum_compressed_object_bytes',
    'decompressed bytes' => 'maximum_decompressed_bytes',
]);

it('rejects expired forged and cross-application player delivery URLs', function (): void {
    $viewer = User::factory()->create();
    $session = makeReplaySession();
    $expired = signedReplayUrl($session, now()->subSecond());
    $forged = str_replace('channel='.str_repeat('a', 96), 'channel='.str_repeat('b', 96), signedReplayUrl($session));
    $otherApplication = Application::factory()->create();
    $crossApplication = URL::temporarySignedRoute('sessions.player', now()->addMinute(), [
        'application' => $otherApplication,
        'recordingSession' => $session,
        'channel' => str_repeat('a', 96),
    ]);

    $this->actingAs($viewer)->get($expired)->assertForbidden()->assertSee('delivery_link_invalid');
    $this->get($forged)->assertForbidden()->assertSee('delivery_link_invalid');
    $this->get($crossApplication)->assertNotFound();
});

it('executes the shipped message validator and drops wrong-origin nonce type and schema messages', function (): void {
    $configured = getenv('JSC_BINARY');
    $finder = new ExecutableFinder;
    $binary = null;

    foreach ([$configured, '/System/Library/Frameworks/JavaScriptCore.framework/Versions/Current/Helpers/jsc', $finder->find('jsc')] as $candidate) {
        if (is_string($candidate) && $candidate !== '' && is_executable($candidate)) {
            $binary = $candidate;
            break;
        }
    }

    if ($binary === null) {
        $this->markTestSkipped('JavaScriptCore is unavailable.');
    }

    $process = new Process([
        $binary,
        public_path('build/assets/replay-message-channel.js'),
        base_path('tests/Fixtures/player-message-channel-scenario.js'),
    ]);
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
    $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);

    expect($result)->each->toBeTrue();
});

it('round trips real player and shell messages while rejecting exact channel near misses', function (): void {
    $configured = getenv('JSC_BINARY');
    $finder = new ExecutableFinder;
    $binary = null;

    foreach ([$configured, '/System/Library/Frameworks/JavaScriptCore.framework/Versions/Current/Helpers/jsc', $finder->find('jsc')] as $candidate) {
        if (is_string($candidate) && $candidate !== '' && is_executable($candidate)) {
            $binary = $candidate;
            break;
        }
    }

    if ($binary === null) {
        $this->markTestSkipped('JavaScriptCore is unavailable.');
    }

    $process = new Process([
        $binary,
        public_path('build/assets/replay-message-channel.js'),
        base_path('tests/Fixtures/channel-roundtrip-shell-runtime.js'),
        public_path('build/assets/replay-shell.js'),
        base_path('tests/Fixtures/channel-roundtrip-player-runtime.js'),
        resource_path('js/replay-player.js'),
        base_path('tests/Fixtures/channel-roundtrip-scenario.js'),
    ]);
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
    $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);

    expect($result)->each->toBeTrue();
});

it('executes player controls while ignoring an untrusted command origin', function (): void {
    $configured = getenv('JSC_BINARY');
    $finder = new ExecutableFinder;
    $binary = null;

    foreach ([$configured, '/System/Library/Frameworks/JavaScriptCore.framework/Versions/Current/Helpers/jsc', $finder->find('jsc')] as $candidate) {
        if (is_string($candidate) && $candidate !== '' && is_executable($candidate)) {
            $binary = $candidate;
            break;
        }
    }

    if ($binary === null) {
        $this->markTestSkipped('JavaScriptCore is unavailable.');
    }

    $process = new Process([
        $binary,
        public_path('build/assets/replay-message-channel.js'),
        base_path('tests/Fixtures/player-runtime.js'),
        resource_path('js/replay-player.js'),
        base_path('tests/Fixtures/player-controls-scenario.js'),
    ]);
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
    $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);

    expect($result['calls'])->toBe([
        ['construct', 2, false, false],
        ['goto', 10, false],
        ['play', 10],
        ['pause'],
        ['play', 40],
        ['goto', 50, true],
        ['config', ['speed' => 2]],
        ['config', ['skipInactive' => true]],
        ['goto', 75, true],
    ])->and($result['messages'])->toHaveCount(9)
        ->and($result['messages'][0]['message']['type'])->toBe('ready')
        ->and($result['messages'][2]['message']['time'])->toBe(40)
        ->and($result['messages'][0]['origin'])->toBe('https://reel.example');
});

it('turns player delivery failures and readiness timeouts into shell diagnostics', function (): void {
    $configured = getenv('JSC_BINARY');
    $finder = new ExecutableFinder;
    $binary = null;

    foreach ([$configured, '/System/Library/Frameworks/JavaScriptCore.framework/Versions/Current/Helpers/jsc', $finder->find('jsc')] as $candidate) {
        if (is_string($candidate) && $candidate !== '' && is_executable($candidate)) {
            $binary = $candidate;
            break;
        }
    }

    if ($binary === null) {
        $this->markTestSkipped('JavaScriptCore is unavailable.');
    }

    $process = new Process([
        $binary,
        public_path('build/assets/replay-message-channel.js'),
        base_path('tests/Fixtures/shell-runtime.js'),
        public_path('build/assets/replay-shell.js'),
        base_path('tests/Fixtures/shell-diagnostics-scenario.js'),
    ]);
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
    $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);

    expect($result)->toBe([
        'ready' => ['ready' => true, 'timerCleared' => true],
        'timeout' => 'Replay unavailable: player timeout',
        'unavailable' => 'Replay unavailable: delivery unavailable',
    ]);
});

it('renders diagnostic player state visibly and sends it to the shell', function (): void {
    $configured = getenv('JSC_BINARY');
    $finder = new ExecutableFinder;
    $binary = null;

    foreach ([$configured, '/System/Library/Frameworks/JavaScriptCore.framework/Versions/Current/Helpers/jsc', $finder->find('jsc')] as $candidate) {
        if (is_string($candidate) && $candidate !== '' && is_executable($candidate)) {
            $binary = $candidate;
            break;
        }
    }

    if ($binary === null) {
        $this->markTestSkipped('JavaScriptCore is unavailable.');
    }

    $process = new Process([
        $binary,
        base_path('tests/Fixtures/player-diagnostic-runtime.js'),
        resource_path('js/replay-player.js'),
        base_path('tests/Fixtures/player-diagnostic-scenario.js'),
    ]);
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
    $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);

    expect($result)->each->toBeTrue();
});
