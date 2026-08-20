<?php

declare(strict_types=1);

use App\Enums\CredentialStatus;
use App\Enums\RecordingSessionStatus;
use App\Models\Application;
use App\Models\ApplicationCredential;
use App\Models\RecordingChunk;
use App\Models\RecordingEpoch;
use App\Models\RecordingSession;
use App\Services\ChunkPrivacyValidator;
use ArtisanBuild\ReelClient\Envelope;
use ArtisanBuild\ReelClient\KeyMaterial;
use ArtisanBuild\ReelClient\SessionGrant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
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

/**
 * @param  array{application: Application, credential: ApplicationCredential, key: array{public: string, private: string}, session_id: string, origin: string}  $context
 */
function insertConcurrentTestSession(array $context): int
{
    $application = $context['application'];

    return DB::table('recording_sessions')->insertGetId([
        'application_id' => $application->getKey(),
        'application_credential_id' => $context['credential']->getKey(),
        'session_id' => str_repeat('f', 64),
        'grant_id_hash' => hash('sha256', 'concurrent-test-grant'),
        'origin' => $context['origin'],
        'status' => RecordingSessionStatus::Recording->value,
        'protocol_version' => Envelope::VERSION,
        'max_chunks' => $application->max_chunks_per_session,
        'max_compressed_bytes' => $application->max_compressed_bytes_per_session,
        'max_chunk_bytes' => $application->max_compressed_chunk_bytes,
        'chunk_count' => 0,
        'compressed_bytes' => 0,
        'conflicting_retry_count' => 0,
        'epoch_count' => 0,
        'started_at' => now(),
        'max_event_time' => now()->addMinutes(29),
        'upload_cutoff_at' => now()->addMinutes(30),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
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
    $invalidCompressed = 'not-a-gzip-stream';
    $secondEnvelope['payload'] = base64_encode($invalidCompressed);
    $secondEnvelope['checksum'] = hash('sha256', $invalidCompressed);

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

it('rejects unknown event-level fields instead of scanning only event data', function (string $field): void {
    $context = ingestContext();
    $events = safeIngestEvents();
    $events[0][$field] = 'SECRET';

    postIngestEnvelope(ingestEnvelope($context, $events))
        ->assertUnprocessable()
        ->assertJsonPath('accepted', false);

    expect(RecordingChunk::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
})->with(['requestBody', 'cookies', 'wire:snapshot', 'unknown']);

it('fails closed on malformed node discriminators and canonicalized names', function (Closure $mutate): void {
    $context = ingestContext();
    $events = safeIngestEvents();
    $mutate($events);

    postIngestEnvelope(ingestEnvelope($context, $events))->assertUnprocessable();

    expect(RecordingChunk::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
})->with([
    'string node discriminator' => function (array &$events): void {
        $events[0]['data']['node']['type'] = '2';
    },
    'string node discriminator with hydration data' => function (array &$events): void {
        $events[0]['data']['node']['type'] = '2';
        $events[0]['data']['node']['attributes']['wire:snapshot'] = 'SECRET';
    },
    'padded input tag' => function (array &$events): void {
        $events[0]['data']['node']['tagName'] = 'input ';
        $events[0]['data']['node']['attributes']['value'] = 'SECRET';
    },
    'entity encoded hydration attribute' => function (array &$events): void {
        $events[0]['data']['node']['attributes']['wire&#58;snapshot'] = 'SECRET';
    },
]);

it('rejects canonicalized CSS network references', function (string $style): void {
    $context = ingestContext();
    $events = safeIngestEvents();
    $events[0]['data']['node']['attributes']['style'] = $style;

    postIngestEnvelope(ingestEnvelope($context, $events))->assertUnprocessable();

    expect(RecordingChunk::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
})->with([
    'escaped url function' => 'background:\75\72\6c(https://private.example/a.png)',
    'comment-separated import' => '@import/**/"https://private.example/a.css"',
]);

it('rejects gzip data with unvalidated trailing bytes', function (): void {
    $context = ingestContext();
    $events = safeIngestEvents();
    $compressed = gzencode(json_encode($events, JSON_THROW_ON_ERROR));

    if ($compressed === false) {
        throw new RuntimeException('Unable to build the gzip framing fixture.');
    }

    $compressed .= 'RAW-DOM-OR-TOKEN';
    $envelope = ingestEnvelope($context, $events, [
        'payload' => base64_encode($compressed),
        'checksum' => hash('sha256', $compressed),
    ]);

    postIngestEnvelope($envelope)
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'invalid_gzip_framing');

    expect(RecordingChunk::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('fails an epoch after privacy rejection and refuses its later chunks', function (): void {
    $context = ingestContext();
    $grant = ingestGrant($context);
    postIngestEnvelope(ingestEnvelope($context, overrides: ['sequence' => 0, 'grant' => $grant]))->assertAccepted();

    $unsafe = safeIngestEvents();
    $unsafe[0]['data']['node']['attributes']['wire:snapshot'] = 'SECRET';
    postIngestEnvelope(ingestEnvelope($context, $unsafe, ['sequence' => 1, 'grant' => $grant]))
        ->assertUnprocessable();

    postIngestEnvelope(ingestEnvelope($context, overrides: ['sequence' => 2, 'grant' => $grant]))
        ->assertConflict()
        ->assertJsonPath('reason', 'epoch_failed');

    $epoch = RecordingEpoch::query()->sole();
    expect(RecordingChunk::query()->pluck('sequence')->all())->toBe([0])
        ->and($epoch->status->value)->toBe('failed')
        ->and($epoch->failure_code)->toStartWith('privacy_')
        ->and($epoch->transitions()->orderBy('id')->pluck('new_state')->all())->toBe(['active', 'failed']);
});

it('validates every decoded event timestamp against signed and declared bounds', function (): void {
    $context = ingestContext();
    $declaredTime = now()->getTimestampMs();
    $events = safeIngestEvents(now()->addYear()->getTimestampMs());

    postIngestEnvelope(ingestEnvelope($context, $events, [
        'event_started_at' => $declaredTime,
        'event_ended_at' => $declaredTime,
    ]))->assertUnprocessable()->assertJsonPath('reason', 'event_time_outside_grant');

    expect(RecordingChunk::query()->count())->toBe(0);
});

it('requires declared envelope bounds to equal decoded event bounds', function (): void {
    $context = ingestContext();
    $events = safeIngestEvents();

    postIngestEnvelope(ingestEnvelope($context, $events, [
        'event_started_at' => $events[0]['timestamp'] - 1,
    ]))->assertUnprocessable()->assertJsonPath('reason', 'event_bounds_mismatch');

    expect(RecordingChunk::query()->count())->toBe(0);
});

it('enforces application-wide daily chunk and byte ceilings', function (string $limit): void {
    $context = ingestContext();
    $grant = ingestGrant($context);
    $first = ingestEnvelope($context, overrides: ['grant' => $grant]);
    $firstBytes = strlen(base64_decode((string) $first['payload'], true));

    $context['application']->update([
        'max_daily_chunks' => $limit === 'chunks' ? 1 : 100,
        'max_daily_compressed_bytes' => $limit === 'bytes' ? $firstBytes : 1_000_000,
    ]);

    postIngestEnvelope($first)->assertAccepted();
    $second = ingestEnvelope($context, overrides: ['sequence' => 1, 'grant' => $grant]);
    $invalidCompressed = 'not-a-gzip-stream';
    $second['payload'] = base64_encode($invalidCompressed);
    $second['checksum'] = hash('sha256', $invalidCompressed);

    postIngestEnvelope($second)
        ->assertTooManyRequests()
        ->assertJsonPath('reason', $limit === 'chunks'
            ? 'application_daily_chunk_limit'
            : 'application_daily_byte_limit');

    expect(RecordingChunk::query()->count())->toBe(1);
})->with(['chunks', 'bytes']);

it('uses a database-backed ingest request throttle before decompression', function (): void {
    $context = ingestContext(['max_ingest_requests_per_minute' => 1]);
    postIngestEnvelope(ingestEnvelope($context))->assertAccepted();
    $invalidCompressed = 'not-a-gzip-stream';
    $secondContext = $context;
    $secondContext['session_id'] = bin2hex(random_bytes(32));
    $envelope = ingestEnvelope($secondContext, overrides: [
        'grant' => ingestGrant($secondContext, ['grant_id' => 'throttled-grant']),
        'payload' => base64_encode($invalidCompressed),
        'checksum' => hash('sha256', $invalidCompressed),
    ]);

    postIngestEnvelope($envelope)
        ->assertTooManyRequests()
        ->assertJsonPath('reason', 'ingest_request_limit');
});

it('rechecks application aggregate chunk capacity under the write lock', function (): void {
    $context = ingestContext(['max_daily_chunks' => 1]);

    $this->app->instance(ChunkPrivacyValidator::class, new class($context) extends ChunkPrivacyValidator
    {
        public function __construct(private readonly array $context) {}

        #[Override]
        public function validate(mixed $events): void
        {
            parent::validate($events);
            $sessionId = insertConcurrentTestSession($this->context);
            DB::table('recording_chunks')->insert([
                'application_id' => $this->context['application']->getKey(),
                'recording_session_id' => $sessionId,
                'epoch_id' => 'concurrent-epoch',
                'sequence' => 0,
                'checksum' => str_repeat('a', 64),
                'compressed_bytes' => 100,
                'decompressed_bytes' => 200,
                'event_started_at' => now()->getTimestampMs(),
                'event_ended_at' => now()->getTimestampMs(),
                'object_key' => 'concurrent-test-object',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    });

    postIngestEnvelope(ingestEnvelope($context))
        ->assertTooManyRequests()
        ->assertJsonPath('reason', 'application_daily_chunk_limit');

    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('rechecks concurrent session capacity under the write lock', function (): void {
    $context = ingestContext(['max_concurrent_sessions' => 1]);

    $this->app->instance(ChunkPrivacyValidator::class, new class($context) extends ChunkPrivacyValidator
    {
        public function __construct(private readonly array $context) {}

        #[Override]
        public function validate(mixed $events): void
        {
            parent::validate($events);
            insertConcurrentTestSession($this->context);
        }
    });

    postIngestEnvelope(ingestEnvelope($context))
        ->assertTooManyRequests()
        ->assertJsonPath('reason', 'concurrent_session_limit');

    expect(RecordingSession::query()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('enforces legal session transitions in the model and database', function (): void {
    $context = ingestContext();
    postIngestEnvelope(ingestEnvelope($context))->assertAccepted();
    $session = RecordingSession::query()->sole();

    expect($session->isFillable('status'))->toBeFalse()
        ->and(fn () => $session->transitionTo(RecordingSessionStatus::Deleted, 'illegal'))
        ->toThrow(DomainException::class);

    $session->transitionTo(RecordingSessionStatus::Failed, 'test_failure');
    $session->transitionTo(RecordingSessionStatus::Deleting, 'test_deletion');

    expect(fn () => $session->transitionTo(RecordingSessionStatus::Ready, 'illegal_revival'))
        ->toThrow(DomainException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('recording_sessions')
            ->where('id', $session->getKey())
            ->update(['status' => RecordingSessionStatus::Ready->value])))
        ->toThrow(QueryException::class)
        ->and($session->transitions()->orderBy('id')->pluck('new_state')->all())
        ->toBe(['recording', 'failed', 'deleting']);
});

it('accepts a legal session transition and records it once', function (): void {
    $context = ingestContext();
    postIngestEnvelope(ingestEnvelope($context))->assertAccepted();
    $session = RecordingSession::query()->sole();

    $session->transitionTo(RecordingSessionStatus::Closing, 'explicit_close', 2);

    expect($session->status)->toBe(RecordingSessionStatus::Closing)
        ->and($session->transitions()->latest('id')->first()->only([
            'previous_state', 'new_state', 'reason', 'attempt',
        ]))->toBe([
            'previous_state' => 'recording',
            'new_state' => 'closing',
            'reason' => 'explicit_close',
            'attempt' => 2,
        ]);
});

it('rechecks and locks credential activity after decoding before persistence', function (): void {
    $context = ingestContext();
    $credential = $context['credential'];
    $credentialQueries = [];

    $this->app->instance(ChunkPrivacyValidator::class, new class($credential) extends ChunkPrivacyValidator
    {
        public function __construct(private readonly ApplicationCredential $credential) {}

        #[Override]
        public function validate(mixed $events): void
        {
            parent::validate($events);
            $this->credential->update([
                'status' => CredentialStatus::Revoked,
                'revoked_at' => now(),
            ]);
        }
    });

    DB::listen(function (QueryExecuted $query) use (&$credentialQueries): void {
        $sql = strtolower($query->sql);

        if (str_contains($sql, 'application_credentials')) {
            $credentialQueries[] = $sql;
        }
    });

    postIngestEnvelope(ingestEnvelope($context))
        ->assertUnauthorized()
        ->assertJsonPath('reason', 'inactive_credential');

    expect(collect($credentialQueries)->contains(fn (string $sql): bool => str_contains($sql, 'for update')))->toBeTrue()
        ->and(RecordingSession::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});
