<?php

declare(strict_types=1);

use App\Enums\CredentialStatus;
use App\Enums\RecordingSessionStatus;
use App\Models\Application;
use App\Models\ApplicationCredential;
use App\Models\RecordingChunk;
use App\Models\RecordingSession;
use ArtisanBuild\ReelClient\Envelope;
use ArtisanBuild\ReelClient\KeyMaterial;
use ArtisanBuild\ReelClient\SessionGrant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256 as HmacSha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;

/**
 * @param  array<string, mixed>  $applicationOverrides
 * @return array{application: Application, credential: ApplicationCredential, key: array{public: string, private: string}, session_id: string, origin: string}
 */
function ingestContext(array $applicationOverrides = []): array
{
    $origin = 'https://monitored.example';
    $application = Application::factory()->create([
        'allowed_origins' => [$origin],
        ...$applicationOverrides,
    ]);
    $key = testRsaKeyPair();
    $credential = ApplicationCredential::factory()->for($application)->create([
        'public_key' => $key['public'],
        'status' => CredentialStatus::Active,
        'enrollment_code_hash' => null,
        'enrolled_at' => now(),
    ]);

    return [
        'application' => $application,
        'credential' => $credential,
        'key' => $key,
        'session_id' => bin2hex(random_bytes(32)),
        'origin' => $origin,
    ];
}

/**
 * @param  array{application: Application, credential: ApplicationCredential, key: array{public: string, private: string}, session_id: string, origin: string}  $context
 * @param  array<string, mixed>  $overrides
 */
function ingestGrant(array $context, array $overrides = []): string
{
    $application = $context['application'];
    $issuedAt = $overrides['issued_at'] ?? now()->subSeconds(5)->toDateTimeImmutable();
    $maxEventTime = $overrides['max_event_time'] ?? now()->addMinutes(29)->toDateTimeImmutable();
    $expiresAt = $overrides['expires_at'] ?? now()->addMinutes(30)->toDateTimeImmutable();

    return SessionGrant::mint(
        $overrides['private_key'] ?? $context['key']['private'],
        $overrides['application_id'] ?? $application->public_id,
        $overrides['session_id'] ?? $context['session_id'],
        $overrides['origin'] ?? $context['origin'],
        $issuedAt,
        $expiresAt,
        $maxEventTime,
        $overrides['ceilings'] ?? [
            'max_chunks' => $application->max_chunks_per_session,
            'max_compressed_bytes' => $application->max_compressed_bytes_per_session,
            'max_chunk_bytes' => $application->max_compressed_chunk_bytes,
        ],
        $overrides['grant_id'] ?? 'ingest-grant-id',
    );
}

/**
 * @param  array{application: Application, credential: ApplicationCredential, key: array{public: string, private: string}, session_id: string, origin: string}  $context
 * @param  array<string, mixed>  $overrides
 */
function customIngestGrant(array $context, array $overrides = []): string
{
    $now = $overrides['issued_at'] ?? now()->subSeconds(5)->toDateTimeImmutable();
    $expires = $overrides['expires_at'] ?? now()->addMinutes(30)->toDateTimeImmutable();
    $maxEventTime = $overrides['max_event_time'] ?? now()->addMinutes(29)->getTimestamp();
    $configuration = Configuration::forAsymmetricSigner(
        new Sha256,
        InMemory::plainText($overrides['private_key'] ?? $context['key']['private']),
        InMemory::plainText($context['key']['public']),
    );
    $application = $context['application'];

    return $configuration->builder()
        ->withHeader('typ', $overrides['typ'] ?? SessionGrant::TYPE)
        ->issuedBy($overrides['issuer'] ?? SessionGrant::ISSUER)
        ->permittedFor($overrides['audience'] ?? SessionGrant::AUDIENCE)
        ->identifiedBy($overrides['grant_id'] ?? 'ingest-grant-id')
        ->issuedAt($now)
        ->canOnlyBeUsedAfter($overrides['not_before'] ?? $now)
        ->expiresAt($expires)
        ->withClaim('application_id', $overrides['application_id'] ?? $application->public_id)
        ->withClaim('credential_id', $overrides['credential_id'] ?? KeyMaterial::credentialId($context['key']['public']))
        ->withClaim('session_id', $overrides['session_id'] ?? $context['session_id'])
        ->withClaim('origin', $overrides['origin'] ?? $context['origin'])
        ->withClaim('protocol_version', $overrides['protocol_version'] ?? Envelope::VERSION)
        ->withClaim('max_event_time', $maxEventTime)
        ->withClaim('ceilings', $overrides['ceilings'] ?? [
            'max_chunks' => $application->max_chunks_per_session,
            'max_compressed_bytes' => $application->max_compressed_bytes_per_session,
            'max_chunk_bytes' => $application->max_compressed_chunk_bytes,
        ])
        ->getToken($configuration->signer(), $configuration->signingKey())
        ->toString();
}

/**
 * @param  array{application: Application, credential: ApplicationCredential, key: array{public: string, private: string}, session_id: string, origin: string}  $context
 */
function symmetricIngestGrant(array $context): string
{
    $configuration = Configuration::forSymmetricSigner(
        new HmacSha256,
        InMemory::plainText(str_repeat('symmetric-secret-', 4)),
    );

    $application = $context['application'];

    return $configuration->builder()
        ->withHeader('typ', SessionGrant::TYPE)
        ->issuedBy(SessionGrant::ISSUER)
        ->permittedFor(SessionGrant::AUDIENCE)
        ->identifiedBy('symmetric-grant-id')
        ->issuedAt(now()->subSecond()->toDateTimeImmutable())
        ->canOnlyBeUsedAfter(now()->subSecond()->toDateTimeImmutable())
        ->expiresAt(now()->addMinute()->toDateTimeImmutable())
        ->withClaim('application_id', $application->public_id)
        ->withClaim('credential_id', KeyMaterial::credentialId($context['key']['public']))
        ->withClaim('session_id', $context['session_id'])
        ->withClaim('origin', $context['origin'])
        ->withClaim('protocol_version', Envelope::VERSION)
        ->withClaim('max_event_time', now()->addSeconds(30)->getTimestamp())
        ->withClaim('ceilings', [
            'max_chunks' => $application->max_chunks_per_session,
            'max_compressed_bytes' => $application->max_compressed_bytes_per_session,
            'max_chunk_bytes' => $application->max_compressed_chunk_bytes,
        ])
        ->getToken($configuration->signer(), $configuration->signingKey())
        ->toString();
}

/** @return list<array<string, mixed>> */
function safeIngestEvents(?int $timestamp = null): array
{
    return [[
        'type' => 2,
        'timestamp' => $timestamp ?? now()->getTimestampMs(),
        'data' => [
            'node' => [
                'type' => 2,
                'id' => 1,
                'tagName' => 'div',
                'attributes' => ['class' => 'content'],
                'childNodes' => [[
                    'type' => 3,
                    'id' => 2,
                    'textContent' => 'Safe visible text',
                ]],
            ],
        ],
    ]];
}

/**
 * @param  array{application: Application, credential: ApplicationCredential, key: array{public: string, private: string}, session_id: string, origin: string}  $context
 * @param  list<array<string, mixed>>|null  $events
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ingestEnvelope(array $context, ?array $events = null, array $overrides = []): array
{
    $events ??= safeIngestEvents();
    $encoded = json_encode($events, JSON_THROW_ON_ERROR);
    $compressed = gzencode($encoded, 6);

    if ($compressed === false) {
        throw new RuntimeException('Unable to gzip the test fixture.');
    }

    return [
        'envelope_version' => Envelope::VERSION,
        'recorder_version' => Envelope::RECORDER_VERSION,
        'rrweb_version' => Envelope::RRWEB_VERSION,
        'compression' => Envelope::COMPRESSION,
        'application_id' => $context['application']->public_id,
        'session_id' => $context['session_id'],
        'epoch_id' => $overrides['epoch_id'] ?? 'epoch-1',
        'sequence' => $overrides['sequence'] ?? 0,
        'checksum' => $overrides['checksum'] ?? hash('sha256', $compressed),
        'event_started_at' => $overrides['event_started_at'] ?? $events[0]['timestamp'],
        'event_ended_at' => $overrides['event_ended_at'] ?? $events[array_key_last($events)]['timestamp'],
        'payload' => $overrides['payload'] ?? base64_encode($compressed),
        'grant' => $overrides['grant'] ?? ingestGrant($context),
    ];
}

/** @param array<string, mixed> $envelope */
function postIngestEnvelope(array $envelope, string $origin = 'https://monitored.example'): TestResponse
{
    return test()->call(
        'POST',
        route('recording-chunks.store'),
        server: ['CONTENT_TYPE' => 'text/plain', 'HTTP_ORIGIN' => $origin],
        content: json_encode($envelope, JSON_THROW_ON_ERROR),
    );
}

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
});

it('accepts a verified chunk and derives session authority only from its grant', function (): void {
    $context = ingestContext();

    postIngestEnvelope(ingestEnvelope($context))
        ->assertAccepted()
        ->assertHeader('Access-Control-Allow-Origin', '*')
        ->assertJson(['accepted' => true, 'duplicate' => false]);

    $session = RecordingSession::query()->sole();
    $chunk = RecordingChunk::query()->sole();

    expect($session->application_id)->toBe($context['application']->getKey())
        ->and($session->application_credential_id)->toBe($context['credential']->getKey())
        ->and($session->origin)->toBe($context['origin'])
        ->and($session->max_chunks)->toBe($context['application']->max_chunks_per_session)
        ->and($session->status)->toBe(RecordingSessionStatus::Recording)
        ->and($chunk->object_key)->toStartWith('reel/chunks/'.$context['application']->public_id.'/'.$context['session_id']);
    Storage::disk('local')->assertExists($chunk->object_key);
});

it('rejects forged signatures before inserts queue dispatches or object writes', function (): void {
    Queue::fake();
    $context = ingestContext();
    $insertQueries = [];

    DB::listen(function (QueryExecuted $query) use (&$insertQueries): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'insert')) {
            $insertQueries[] = $query->sql;
        }
    });

    $envelope = ingestEnvelope($context, overrides: [
        'grant' => customIngestGrant($context, ['private_key' => KeyMaterial::generate()['private']]),
    ]);

    postIngestEnvelope($envelope)->assertUnauthorized()->assertJsonPath('reason', 'invalid_grant');

    expect($insertQueries)->toBeEmpty()
        ->and(RecordingSession::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
    Queue::assertNothingPushed();
});

it('rejects each invalid grant binding before storage', function (Closure $mutate): void {
    $context = ingestContext();
    $envelope = ingestEnvelope($context);
    $mutate($context, $envelope);

    postIngestEnvelope($envelope)
        ->assertUnauthorized()
        ->assertJsonPath('reason', 'invalid_grant');

    expect(RecordingSession::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
})->with([
    'explicit type' => fn (array $context, array &$envelope) => $envelope['grant'] = customIngestGrant($context, ['typ' => 'JWT']),
    'explicit algorithm' => fn (array $context, array &$envelope) => $envelope['grant'] = symmetricIngestGrant($context),
    'issuer' => fn (array $context, array &$envelope) => $envelope['grant'] = customIngestGrant($context, ['issuer' => 'attacker']),
    'audience' => fn (array $context, array &$envelope) => $envelope['grant'] = customIngestGrant($context, ['audience' => 'attacker']),
    'expiry' => fn (array $context, array &$envelope) => $envelope['grant'] = customIngestGrant($context, [
        'issued_at' => now()->subMinutes(2)->toDateTimeImmutable(),
        'expires_at' => now()->subMinute()->toDateTimeImmutable(),
        'max_event_time' => now()->subSeconds(70)->getTimestamp(),
    ]),
    'maximum age' => fn (array $context, array &$envelope) => $envelope['grant'] = customIngestGrant($context, [
        'issued_at' => now()->subHour()->toDateTimeImmutable(),
    ]),
    'application' => fn (array $context, array &$envelope) => $envelope['grant'] = customIngestGrant($context, ['application_id' => 'other-app']),
    'credential' => fn (array $context, array &$envelope) => $envelope['grant'] = customIngestGrant($context, ['credential_id' => 'sha256:other']),
    'session' => fn (array $context, array &$envelope) => $envelope['grant'] = customIngestGrant($context, ['session_id' => str_repeat('b', 64)]),
    'protocol' => fn (array $context, array &$envelope) => $envelope['grant'] = customIngestGrant($context, ['protocol_version' => 2]),
    'signed ceilings' => fn (array $context, array &$envelope) => $envelope['grant'] = customIngestGrant($context, ['ceilings' => [
        'max_chunks' => $context['application']->max_chunks_per_session + 1,
        'max_compressed_bytes' => $context['application']->max_compressed_bytes_per_session,
        'max_chunk_bytes' => $context['application']->max_compressed_chunk_bytes,
    ]]),
]);

it('rejects a signed origin outside the application allowlist', function (): void {
    $context = ingestContext();
    $envelope = ingestEnvelope($context, overrides: [
        'grant' => customIngestGrant($context, ['origin' => 'https://attacker.example']),
    ]);

    postIngestEnvelope($envelope, 'https://attacker.example')->assertUnauthorized();

    expect(RecordingSession::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('rejects a request origin that conflicts with the signed origin', function (): void {
    $context = ingestContext();

    postIngestEnvelope(ingestEnvelope($context), 'https://attacker.example')
        ->assertUnauthorized()
        ->assertJsonPath('reason', 'origin_mismatch');

    expect(RecordingSession::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('rejects inactive application and credential state', function (string $state, int $status, string $reason): void {
    $context = ingestContext();
    $envelope = ingestEnvelope($context);

    if ($state === 'application') {
        $context['application']->update(['ingest_enabled' => false]);
    } else {
        $context['credential']->update(['status' => CredentialStatus::Revoked, 'revoked_at' => now()]);
    }

    postIngestEnvelope($envelope)->assertStatus($status)->assertJsonPath('reason', $reason);

    expect(RecordingSession::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
})->with([
    'application' => ['application', 403, 'application_disabled'],
    'credential' => ['credential', 401, 'invalid_grant'],
]);

it('rejects conflicting body authority instead of merging it', function (string $field, mixed $value): void {
    $context = ingestContext();
    $envelope = ingestEnvelope($context);
    $envelope[$field] = $value;

    postIngestEnvelope($envelope)->assertUnprocessable()->assertJsonPath('reason', 'invalid_envelope');

    expect(RecordingSession::query()->count())->toBe(0);
})->with([
    'origin' => ['origin', 'https://attacker.example'],
    'timing' => ['max_event_time', 9999999999],
    'limit' => ['max_chunks', 999999],
]);

it('enforces request and compressed chunk byte limits independently', function (string $limit): void {
    $context = ingestContext(['max_compressed_chunk_bytes' => 32]);
    $envelope = ingestEnvelope($context);

    if ($limit === 'request') {
        config()->set('reel_ingest.maximum_request_bytes', 100);
        postIngestEnvelope($envelope)->assertStatus(413)->assertJsonPath('reason', 'request_too_large');
    } else {
        postIngestEnvelope($envelope)->assertStatus(413)->assertJsonPath('reason', 'compressed_chunk_too_large');
    }

    expect(RecordingSession::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
})->with(['request', 'compressed chunk']);

it('stops a decompression bomb at the decompressed ceiling before persistence', function (): void {
    config()->set('reel_ingest.maximum_decompressed_chunk_bytes', 1024);
    $context = ingestContext();
    $events = safeIngestEvents();
    $events[0]['data']['node']['childNodes'][0]['textContent'] = str_repeat('a', 16 * 1024 * 1024);
    $envelope = ingestEnvelope($context, $events);
    unset($events);
    gc_collect_cycles();
    memory_reset_peak_usage();
    $memoryBefore = memory_get_usage(true);

    postIngestEnvelope($envelope)
        ->assertStatus(413)
        ->assertJsonPath('reason', 'decompressed_chunk_too_large');

    expect(RecordingSession::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty()
        ->and(memory_get_peak_usage(true) - $memoryBefore)->toBeLessThan(8 * 1024 * 1024);
});

it('enforces per-session chunk and aggregate byte ceilings', function (string $limit): void {
    $applicationOverrides = $limit === 'chunks'
        ? ['max_chunks_per_session' => 1]
        : ['max_compressed_bytes_per_session' => 1_000_000];
    $context = ingestContext($applicationOverrides);
    $first = ingestEnvelope($context);
    $firstCompressedBytes = strlen(base64_decode((string) $first['payload'], true));

    if ($limit === 'bytes') {
        $context['application']->update(['max_compressed_bytes_per_session' => $firstCompressedBytes]);
        $first['grant'] = ingestGrant($context);
    }

    postIngestEnvelope($first)->assertAccepted();
    $second = ingestEnvelope($context, overrides: ['sequence' => 1, 'grant' => $first['grant']]);
    postIngestEnvelope($second)->assertStatus(413);

    expect(RecordingChunk::query()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
})->with(['chunks', 'bytes']);

it('enforces daily new-session and concurrent-session application caps', function (string $limit): void {
    $context = ingestContext([
        'max_new_sessions_per_day' => $limit === 'daily' ? 1 : 10,
        'max_concurrent_sessions' => 1,
    ]);
    postIngestEnvelope(ingestEnvelope($context))->assertAccepted();

    $secondContext = $context;
    $secondContext['session_id'] = bin2hex(random_bytes(32));
    $secondEnvelope = ingestEnvelope($secondContext, overrides: ['grant' => ingestGrant($secondContext, ['grant_id' => 'second-grant'])]);

    postIngestEnvelope($secondEnvelope)
        ->assertTooManyRequests()
        ->assertJsonPath('reason', $limit === 'daily' ? 'daily_session_limit' : 'concurrent_session_limit');

    expect(RecordingSession::query()->count())->toBe(1);
})->with(['daily', 'concurrent']);

it('makes exact retries no-op and retains first bytes on conflicting retries', function (): void {
    $context = ingestContext();
    $first = ingestEnvelope($context);
    postIngestEnvelope($first)->assertAccepted();
    $chunk = RecordingChunk::query()->sole();
    $firstBytes = Storage::disk('local')->get($chunk->object_key);

    postIngestEnvelope($first)->assertOk()->assertJson(['duplicate' => true]);

    $differentEvents = safeIngestEvents();
    $differentEvents[0]['data']['node']['childNodes'][0]['textContent'] = 'Different but safe';
    $conflict = ingestEnvelope($context, $differentEvents, ['grant' => $first['grant']]);
    postIngestEnvelope($conflict)->assertConflict()->assertJsonPath('reason', 'conflicting_chunk');

    $chunk->refresh();
    expect(RecordingChunk::query()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1)
        ->and(Storage::disk('local')->get($chunk->object_key))->toBe($firstBytes)
        ->and($chunk->checksum)->toBe($first['checksum'])
        ->and($chunk->recordingSession->conflicting_retry_count)->toBe(1);
});

it('rejects a compressed payload whose declared checksum does not match', function (): void {
    $context = ingestContext();
    $envelope = ingestEnvelope($context);
    $envelope['checksum'] = str_repeat('0', 64);

    postIngestEnvelope($envelope)
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'checksum_mismatch');

    expect(RecordingSession::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('accepts bounded out-of-order chunks within an epoch', function (): void {
    $context = ingestContext();
    $grant = ingestGrant($context);

    postIngestEnvelope(ingestEnvelope($context, overrides: ['sequence' => 2, 'grant' => $grant]))->assertAccepted();
    postIngestEnvelope(ingestEnvelope($context, overrides: ['sequence' => 0, 'grant' => $grant]))->assertAccepted();
    postIngestEnvelope(ingestEnvelope($context, overrides: ['sequence' => 1, 'grant' => $grant]))->assertAccepted();

    expect(RecordingChunk::query()->oldest()->pluck('sequence')->all())->toBe([2, 0, 1]);
});

it('records recording to closing transitions and rejects uploads past the cutoff', function (): void {
    $context = ingestContext();
    $issuedAt = now()->subMinutes(2)->toDateTimeImmutable();
    $maxEventTime = now()->subMinute();
    $grant = ingestGrant($context, [
        'issued_at' => $issuedAt,
        'max_event_time' => $maxEventTime->toDateTimeImmutable(),
        'expires_at' => now()->addMinute()->toDateTimeImmutable(),
    ]);
    $events = safeIngestEvents($maxEventTime->subSecond()->getTimestampMs());

    postIngestEnvelope(ingestEnvelope($context, $events, ['grant' => $grant]))->assertAccepted();

    $session = RecordingSession::query()->sole();
    expect($session->status)->toBe(RecordingSessionStatus::Closing)
        ->and($session->transitions()->orderBy('id')->get()->map->only([
            'previous_state', 'new_state', 'reason', 'attempt',
        ])->all())->toMatchArray([
            [
                'previous_state' => null,
                'new_state' => 'recording',
                'reason' => 'grant_accepted',
                'attempt' => 1,
            ],
            [
                'previous_state' => 'recording',
                'new_state' => 'closing',
                'reason' => 'maximum_event_time_reached',
                'attempt' => 1,
            ],
        ])
        ->and($session->transitions()->whereNull('transitioned_at')->count())->toBe(0);

    $session->update(['upload_cutoff_at' => now()->subSecond()]);
    postIngestEnvelope(ingestEnvelope($context, $events, ['sequence' => 1, 'grant' => $grant]))
        ->assertConflict()
        ->assertJsonPath('reason', 'upload_cutoff_elapsed');
    expect(RecordingChunk::query()->count())->toBe(1);
});

it('keeps existing data when the application kill switch stops later ingest', function (): void {
    $context = ingestContext();
    $grant = ingestGrant($context);
    postIngestEnvelope(ingestEnvelope($context, overrides: ['grant' => $grant]))->assertAccepted();
    $storedKey = RecordingChunk::query()->sole()->object_key;

    $context['application']->update(['ingest_enabled' => false]);
    postIngestEnvelope(ingestEnvelope($context, overrides: ['sequence' => 1, 'grant' => $grant]))
        ->assertForbidden();

    expect(RecordingChunk::query()->count())->toBe(1);
    Storage::disk('local')->assertExists($storedKey);
});

it('rejects every authoritative forbidden privacy class before temporary storage', function (Closure $fixture): void {
    $context = ingestContext();
    $events = $fixture(safeIngestEvents());

    postIngestEnvelope(ingestEnvelope($context, $events))->assertUnprocessable();

    expect(RecordingChunk::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
})->with([
    'unmasked input/select/textarea including hidden' => function (array $events): array {
        $events[0]['data']['node']['childNodes'] = [[
            'type' => 2,
            'tagName' => 'input',
            'attributes' => ['type' => 'hidden', 'value' => 'input-secret'],
            'childNodes' => [],
        ]];

        return $events;
    },
    'contenteditable text' => function (array $events): array {
        $events[0]['data']['node']['childNodes'] = [[
            'type' => 2,
            'tagName' => 'div',
            'attributes' => ['contenteditable' => 'true'],
            'childNodes' => [['type' => 3, 'textContent' => 'editable-secret']],
        ]];

        return $events;
    },
    'incremental unmasked form value' => fn (array $events): array => [[
        'type' => 3,
        'timestamp' => $events[0]['timestamp'],
        'data' => [
            'source' => 0,
            'attributes' => [['id' => 20, 'attributes' => ['value' => 'dynamic-input-secret']]],
        ],
    ]],
    'incremental contenteditable value' => fn (array $events): array => [[
        'type' => 3,
        'timestamp' => $events[0]['timestamp'],
        'data' => ['source' => 5, 'id' => 21, 'text' => 'dynamic-editable-secret'],
    ]],
    'Livewire snapshot and initial data plus Inertia data page' => function (array $events): array {
        $events[0]['data']['node']['attributes']['wire:snapshot'] = 'livewire-secret';
        $events[0]['data']['node']['attributes']['wire:initial-data'] = 'legacy-secret';
        $events[0]['data']['node']['attributes']['data-page'] = 'inertia-secret';

        return $events;
    },
    'incremental framework hydration attribute' => fn (array $events): array => [[
        'type' => 3,
        'timestamp' => $events[0]['timestamp'],
        'data' => [
            'source' => 0,
            'attributes' => [['id' => 22, 'attributes' => ['wire:snapshot' => 'dynamic-livewire-secret']]],
        ],
    ]],
    'page and navigation query strings and fragments' => fn (array $events): array => [[
        'type' => 4,
        'timestamp' => $events[0]['timestamp'],
        'data' => ['href' => '/account?token=secret#private'],
    ]],
    'navigation resource and CSS URL references' => function (array $events): array {
        $events[0]['data']['node']['attributes']['href'] = 'https://private.example/account';
        $events[0]['data']['node']['attributes']['style'] = 'background: url(https://private.example/a.png)';

        return $events;
    },
    'incremental resource URL' => fn (array $events): array => [[
        'type' => 3,
        'timestamp' => $events[0]['timestamp'],
        'data' => ['source' => 0, 'url' => 'https://private.example/resource'],
    ]],
    'data URL and base64 media' => function (array $events): array {
        $events[0]['data']['media'] = 'data:image/png;base64,c2VjcmV0';

        return $events;
    },
    'blocked media element' => function (array $events): array {
        $events[0]['data']['node']['childNodes'] = [[
            'type' => 2,
            'tagName' => 'canvas',
            'attributes' => [],
            'childNodes' => [],
        ]];

        return $events;
    },
    'cookies storage and authorization headers' => function (array $events): array {
        $events[0]['data']['headers'] = ['Authorization' => 'Bearer secret'];
        $events[0]['data']['cookies'] = 'session=secret';
        $events[0]['data']['localStorage'] = ['token' => 'secret'];

        return $events;
    },
    'request and response bodies' => function (array $events): array {
        $events[0]['data']['requestBody'] = 'password=secret';
        $events[0]['data']['responseBody'] = '{"token":"secret"}';

        return $events;
    },
    'console arguments' => fn (array $events): array => [[
        'type' => 6,
        'timestamp' => $events[0]['timestamp'],
        'data' => ['plugin' => 'rrweb/console@1', 'args' => ['secret']],
    ]],
]);

it('leaves the monitored application response unchanged when ingest fails', function (): void {
    Route::middleware('web')->get('/ingest-isolation-fixture', fn (): string => 'byte-identical-host-response');
    $before = $this->get('/ingest-isolation-fixture')->assertOk()->getContent();
    $context = ingestContext();
    $envelope = ingestEnvelope($context, overrides: ['grant' => 'not-a-token']);

    postIngestEnvelope($envelope)->assertUnauthorized();

    $this->get('/ingest-isolation-fixture')->assertOk()->assertContent($before);
});
