<?php

namespace App\Services;

use App\Enums\CredentialStatus;
use App\Enums\RecordingEpochStatus;
use App\Enums\RecordingSessionStatus;
use App\Exceptions\IngestRejected;
use App\Models\Application;
use App\Models\ApplicationCredential;
use App\Models\RecordingChunk;
use App\Models\RecordingEpoch;
use App\Models\RecordingSession;
use ArtisanBuild\ReelClient\Envelope;
use ArtisanBuild\ReelClient\KeyMaterial;
use ArtisanBuild\ReelClient\SessionGrantContext;
use ArtisanBuild\ReelClient\SessionGrantVerifier;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Lcobucci\JWT\Token\Plain;
use Throwable;

class ChunkIngestor
{
    /** @var list<string> */
    private const array ENVELOPE_KEYS = [
        'envelope_version',
        'recorder_version',
        'rrweb_version',
        'compression',
        'application_id',
        'session_id',
        'epoch_id',
        'sequence',
        'checksum',
        'event_started_at',
        'event_ended_at',
        'payload',
        'grant',
    ];

    public function __construct(
        private readonly SessionGrantVerifier $grantVerifier,
        private readonly ChunkPrivacyValidator $privacyValidator,
        private readonly OperationalCounters $operationalCounters,
        private readonly ObjectMutationLock $objectLocks,
    ) {}

    /** @param array<string, mixed> $envelope */
    public function ingest(array $envelope, string $requestOrigin): ChunkIngestResult
    {
        $this->validateEnvelopeShape($envelope);

        $application = Application::query()
            ->where('public_id', $envelope['application_id'])
            ->first();

        if (! $application instanceof Application || ! $application->ingest_enabled) {
            $this->reject('application_disabled', 403);
        }

        [$credential, $token] = $this->verifyGrant($application, $envelope);
        $claims = $token->claims();
        $origin = $claims->get('origin');

        if (! is_string($origin) || $requestOrigin !== $origin) {
            $this->reject('origin_mismatch', 401);
        }

        $ceilings = $claims->get('ceilings');
        $issuedAt = $claims->get('iat');
        $expiresAt = $claims->get('exp');
        $maxEventTime = $claims->get('max_event_time');
        $grantId = $claims->get('jti');
        $applicationUserId = $claims->get('application_user_id');
        $releaseId = $claims->get('release_id');

        if (! is_array($ceilings)
            || ! $issuedAt instanceof DateTimeInterface
            || ! $expiresAt instanceof DateTimeInterface
            || ! is_int($maxEventTime)
            || ! is_string($grantId)
            || (! is_string($applicationUserId) && $applicationUserId !== null)
            || (! is_string($releaseId) && $releaseId !== null)) {
            $this->reject('invalid_grant_claims', 401);
        }

        $this->recordIngestAttempt($application);

        $compressed = base64_decode((string) $envelope['payload'], true);

        if ($compressed === false || strlen($compressed) > $ceilings['max_chunk_bytes']) {
            $this->reject('compressed_chunk_too_large', 413);
        }

        if (! hash_equals($envelope['checksum'], hash('sha256', $compressed))) {
            $this->reject('checksum_mismatch', 422);
        }

        $this->preflightApplicationLimits($application, $envelope, strlen($compressed));
        [$decompressed, $validatedCompressed] = $this->decompress($compressed);

        try {
            $events = json_decode($decompressed, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->reject('invalid_event_json', 422);
        }

        try {
            $this->privacyValidator->validate($events);
        } catch (IngestRejected $rejection) {
            $this->failEpoch(
                $application,
                $credential,
                $envelope,
                $origin,
                $grantId,
                $ceilings,
                $issuedAt,
                $expiresAt,
                $maxEventTime,
                $applicationUserId,
                $releaseId,
                $rejection->reason,
            );

            throw $rejection;
        }

        $this->assertEventTimes($events, $envelope, $issuedAt, $maxEventTime);

        try {
            $result = $this->persist(
                $application,
                $credential,
                $envelope,
                $validatedCompressed,
                strlen($decompressed),
                $origin,
                $grantId,
                $ceilings,
                $issuedAt,
                $expiresAt,
                $maxEventTime,
                $applicationUserId,
                $releaseId,
                $events,
            );
        } catch (IngestRejected $rejection) {
            if (in_array($rejection->reason, [
                'upload_cutoff_elapsed',
                'session_not_accepting_uploads',
                'closing_not_gap_fill',
            ], true)) {
                $this->operationalCounters->increment('late_upload_rejections');
            }

            throw $rejection;
        }

        if ($result->conflict) {
            $this->reject('conflicting_chunk', 409);
        }

        return $result;
    }

    /** @param array<string, mixed> $envelope */
    private function validateEnvelopeShape(array $envelope): void
    {
        $keys = array_keys($envelope);

        if ($keys !== self::ENVELOPE_KEYS
            || $envelope['envelope_version'] !== Envelope::VERSION
            || $envelope['recorder_version'] !== Envelope::RECORDER_VERSION
            || $envelope['rrweb_version'] !== Envelope::RRWEB_VERSION
            || $envelope['compression'] !== Envelope::COMPRESSION
            || ! is_string($envelope['application_id'])
            || ! is_string($envelope['session_id'])
            || preg_match('/^[a-f0-9]{64}$/', $envelope['session_id']) !== 1
            || ! is_string($envelope['epoch_id'])
            || preg_match('/^[A-Za-z0-9_-]{1,128}$/', $envelope['epoch_id']) !== 1
            || ! is_int($envelope['sequence'])
            || $envelope['sequence'] < 0
            || ! is_string($envelope['checksum'])
            || preg_match('/^[a-f0-9]{64}$/', $envelope['checksum']) !== 1
            || ! is_int($envelope['event_started_at'])
            || ! is_int($envelope['event_ended_at'])
            || $envelope['event_started_at'] < 0
            || $envelope['event_ended_at'] < $envelope['event_started_at']
            || ! is_string($envelope['payload'])
            || ! is_string($envelope['grant'])) {
            $this->reject('invalid_envelope', 422);
        }
    }

    /** @return array{string, string} */
    private function decompress(string $compressed): array
    {
        $limit = (int) config('reel_ingest.maximum_decompressed_chunk_bytes');
        $context = @inflate_init(ZLIB_ENCODING_GZIP);

        if ($context === false) {
            $this->reject('invalid_gzip', 422);
        }

        $decompressed = '';
        $compressedBytes = strlen($compressed);

        for ($offset = 0; $offset < $compressedBytes; $offset += 64) {
            $chunk = substr($compressed, $offset, 64);
            $flush = $offset + strlen($chunk) >= $compressedBytes ? ZLIB_FINISH : ZLIB_SYNC_FLUSH;
            $output = @inflate_add($context, $chunk, $flush);

            if ($output === false) {
                $this->reject('invalid_gzip', 422);
            }

            if (strlen($decompressed) + strlen($output) > $limit) {
                $this->reject('decompressed_chunk_too_large', 413);
            }

            $decompressed .= $output;
        }

        $consumedBytes = inflate_get_read_len($context);

        if (inflate_get_status($context) !== ZLIB_STREAM_END || $consumedBytes !== $compressedBytes) {
            $this->reject('invalid_gzip_framing', 422);
        }

        return [$decompressed, substr($compressed, 0, $consumedBytes)];
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function assertEventTimes(
        mixed $events,
        array $envelope,
        DateTimeInterface $issuedAt,
        int $maxEventTime,
    ): void {
        if (! is_array($events) || $events === []) {
            $this->reject('invalid_event_batch', 422);
        }

        $timestamps = [];

        foreach ($events as $event) {
            $timestamp = is_array($event) ? ($event['timestamp'] ?? null) : null;

            if (! is_int($timestamp)
                || $timestamp < $issuedAt->getTimestamp() * 1000
                || $timestamp > $maxEventTime * 1000
                || $timestamp < $envelope['event_started_at']
                || $timestamp > $envelope['event_ended_at']) {
                $this->reject('event_time_outside_grant', 422);
            }

            $timestamps[] = $timestamp;
        }

        if (min($timestamps) !== $envelope['event_started_at']
            || max($timestamps) !== $envelope['event_ended_at']) {
            $this->reject('event_bounds_mismatch', 422);
        }
    }

    private function recordIngestAttempt(Application $application): void
    {
        DB::transaction(function () use ($application): void {
            $lockedApplication = Application::query()->lockForUpdate()->find($application->getKey());

            if (! $lockedApplication instanceof Application || ! $lockedApplication->ingest_enabled) {
                $this->reject('application_disabled', 403);
            }

            $window = now()->startOfMinute();
            $counter = DB::table('ingest_rate_counters')
                ->where('application_id', $lockedApplication->getKey())
                ->lockForUpdate()
                ->first();

            if ($counter === null) {
                DB::table('ingest_rate_counters')->insert([
                    'application_id' => $lockedApplication->getKey(),
                    'window_started_at' => $window,
                    'request_count' => 1,
                ]);

                return;
            }

            if ((string) $counter->window_started_at !== $window->toDateTimeString()) {
                DB::table('ingest_rate_counters')
                    ->where('id', $counter->id)
                    ->update([
                        'window_started_at' => $window,
                        'request_count' => 1,
                    ]);

                return;
            }

            if ((int) $counter->request_count >= $lockedApplication->max_ingest_requests_per_minute) {
                $this->reject('ingest_request_limit', 429);
            }

            DB::table('ingest_rate_counters')
                ->where('id', $counter->id)
                ->increment('request_count');
        }, 3);
    }

    /** @param array<string, mixed> $envelope */
    private function preflightApplicationLimits(Application $application, array $envelope, int $compressedBytes): void
    {
        $session = RecordingSession::query()
            ->where('application_id', $application->getKey())
            ->where('session_id', $envelope['session_id'])
            ->first();

        if (! $session instanceof RecordingSession) {
            $this->assertNewSessionAllowed($application);
        }

        $chunkExists = RecordingChunk::query()
            ->where('application_id', $application->getKey())
            ->where('epoch_id', $envelope['epoch_id'])
            ->where('sequence', $envelope['sequence'])
            ->when(
                $session instanceof RecordingSession,
                fn ($query) => $query->where('recording_session_id', $session->getKey()),
                fn ($query) => $query->whereRaw('1 = 0'),
            )
            ->exists();

        if (! $chunkExists) {
            $this->assertApplicationChunkCapacity($application, $compressedBytes);
        }
    }

    private function assertApplicationChunkCapacity(Application $application, int $compressedBytes): void
    {
        $daily = RecordingChunk::query()
            ->where('application_id', $application->getKey())
            ->where('created_at', '>=', today());
        $chunkCount = (clone $daily)->count();
        $byteCount = (int) (clone $daily)->sum('compressed_bytes');

        if ($chunkCount + 1 > $application->max_daily_chunks) {
            $this->reject('application_daily_chunk_limit', 429);
        }

        if ($byteCount + $compressedBytes > $application->max_daily_compressed_bytes) {
            $this->reject('application_daily_byte_limit', 429);
        }
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array{ApplicationCredential, Plain}
     */
    private function verifyGrant(Application $application, array $envelope): array
    {
        $credentials = $application->credentials()
            ->where('status', CredentialStatus::Active->value)
            ->whereNotNull('public_key')
            ->whereNotNull('enrolled_at')
            ->whereNull('revoked_at')
            ->get();

        foreach ($credentials as $credential) {
            if (! $credential->isActive()
                || $credential->algorithm !== ApplicationCredential::ALGORITHM
                || $credential->public_key === null) {
                continue;
            }

            try {
                $token = $this->grantVerifier->verify(
                    $envelope['grant'],
                    $credential->public_key,
                    new SessionGrantContext(
                        applicationId: $application->public_id,
                        credentialId: KeyMaterial::credentialId($credential->public_key),
                        allowedOrigins: $application->allowed_origins,
                        sessionId: $envelope['session_id'],
                        maximumCeilings: [
                            'max_chunks' => $application->max_chunks_per_session,
                            'max_compressed_bytes' => $application->max_compressed_bytes_per_session,
                            'max_chunk_bytes' => $application->max_compressed_chunk_bytes,
                        ],
                        maximumLifetimeSeconds: (int) config('reel_ingest.maximum_grant_lifetime_seconds'),
                    ),
                );

                return [$credential, $token];
            } catch (DomainException) {
                // Rotation may leave multiple active public keys; all candidates must fail before rejection.
            }
        }

        $this->reject('invalid_grant', 401);
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @param  array<string, int>  $ceilings
     * @param  list<array<string, mixed>>  $events
     */
    private function persist(
        Application $application,
        ApplicationCredential $credential,
        array $envelope,
        string $compressed,
        int $decompressedBytes,
        string $origin,
        string $grantId,
        array $ceilings,
        DateTimeInterface $issuedAt,
        DateTimeInterface $expiresAt,
        int $maxEventTime,
        ?string $applicationUserId,
        ?string $releaseId,
        array $events,
    ): ChunkIngestResult {
        return DB::transaction(function () use (
            $application,
            $credential,
            $envelope,
            $compressed,
            $decompressedBytes,
            $origin,
            $grantId,
            $ceilings,
            $issuedAt,
            $expiresAt,
            $maxEventTime,
            $applicationUserId,
            $releaseId,
            $events,
        ): ChunkIngestResult {
            $lockedApplication = Application::query()->lockForUpdate()->find($application->getKey());

            if (! $lockedApplication instanceof Application || ! $lockedApplication->ingest_enabled) {
                $this->reject('application_disabled', 403);
            }

            $lockedCredential = ApplicationCredential::query()
                ->where('application_id', $lockedApplication->getKey())
                ->lockForUpdate()
                ->find($credential->getKey());

            if (! $lockedCredential instanceof ApplicationCredential
                || ! $lockedCredential->isActive()
                || $lockedCredential->algorithm !== ApplicationCredential::ALGORITHM
                || $lockedCredential->public_key !== $credential->public_key) {
                $this->reject('inactive_credential', 401);
            }

            $session = RecordingSession::query()
                ->where('application_id', $lockedApplication->getKey())
                ->where('session_id', $envelope['session_id'])
                ->lockForUpdate()
                ->first();

            if (! $session instanceof RecordingSession) {
                $this->assertNewSessionAllowed($lockedApplication);
                $session = $this->createSession(
                    $lockedApplication,
                    $lockedCredential,
                    $envelope,
                    $origin,
                    $grantId,
                    $ceilings,
                    $issuedAt,
                    $expiresAt,
                    $maxEventTime,
                    $applicationUserId,
                    $releaseId,
                );
            } else {
                $this->assertSessionBinding(
                    $session,
                    $lockedCredential,
                    $origin,
                    $grantId,
                    $ceilings,
                    $maxEventTime,
                    $expiresAt,
                    $applicationUserId,
                    $releaseId,
                );
            }

            $wasRecording = $session->status === RecordingSessionStatus::Recording;
            if ($session->status === RecordingSessionStatus::Recording
                && now()->greaterThanOrEqualTo($session->max_event_time)) {
                $session->transitionTo(RecordingSessionStatus::Closing, 'maximum_event_time_reached');
            }

            if (! in_array($session->status, [RecordingSessionStatus::Recording, RecordingSessionStatus::Closing], true)) {
                $this->reject('session_not_accepting_uploads', 409);
            }

            $cutoff = $session->status === RecordingSessionStatus::Closing
                ? CarbonImmutable::parse($session->closing_cutoff_at)->min($session->upload_cutoff_at)
                : $session->upload_cutoff_at;

            if ($cutoff === null || now()->greaterThanOrEqualTo($cutoff)) {
                $this->reject('upload_cutoff_elapsed', 409);
            }

            $epoch = $session->epochs()
                ->where('epoch_id', $envelope['epoch_id'])
                ->lockForUpdate()
                ->first();

            if (! $epoch instanceof RecordingEpoch) {
                if ($session->status === RecordingSessionStatus::Closing && ! $wasRecording) {
                    $this->reject('closing_not_gap_fill', 409);
                }

                $epoch = $this->findOrCreateEpoch($session, $envelope['epoch_id']);
            }

            if ($epoch->status === RecordingEpochStatus::Failed) {
                $this->reject('epoch_failed', 409);
            }

            $existing = $session->chunks()
                ->where('epoch_id', $envelope['epoch_id'])
                ->where('sequence', $envelope['sequence'])
                ->first();

            if ($existing instanceof RecordingChunk) {
                if (hash_equals($existing->checksum, $envelope['checksum'])) {
                    return new ChunkIngestResult(true, $origin);
                }

                $session->increment('conflicting_retry_count');

                return new ChunkIngestResult(false, $origin, true);
            }

            $maxSequence = $session->chunks()
                ->where('epoch_id', $envelope['epoch_id'])
                ->max('sequence');

            if ($session->status === RecordingSessionStatus::Closing
                && ! $wasRecording
                && ($maxSequence === null || $envelope['sequence'] > (int) $maxSequence)) {
                $this->reject('closing_not_gap_fill', 409);
            }

            $compressedBytes = strlen($compressed);

            if ($session->chunk_count + 1 > $session->max_chunks) {
                $this->reject('session_chunk_limit', 413);
            }

            if ($session->compressed_bytes + $compressedBytes > $session->max_compressed_bytes) {
                $this->reject('session_byte_limit', 413);
            }

            $this->assertApplicationChunkCapacity($lockedApplication, $compressedBytes);

            $highestAllowed = ($maxSequence === null ? -1 : (int) $maxSequence)
                + (int) config('reel_ingest.maximum_epoch_reorder_distance');

            if ($envelope['sequence'] > $highestAllowed) {
                $this->reject('reorder_distance_exceeded', 409);
            }

            $presentSequences = $session->chunks()
                ->where('epoch_id', $envelope['epoch_id'])
                ->pluck('sequence')
                ->map(fn (mixed $sequence): int => (int) $sequence)
                ->all();
            $expectedSequence = 0;

            while (in_array($expectedSequence, $presentSequences, true)) {
                $expectedSequence++;
            }

            $reorderDistance = max(0, $envelope['sequence'] - $expectedSequence);

            $objectKey = $this->objectKey($lockedApplication, $session, $envelope);
            $this->objectLocks->acquireForTransaction($objectKey);
            $disk = Storage::disk((string) config('filesystems.default'));

            if (! $disk->put($objectKey, $compressed)) {
                $this->reject('object_write_failed', 503);
            }

            try {
                $session->chunks()->create([
                    'application_id' => $lockedApplication->getKey(),
                    'epoch_id' => $envelope['epoch_id'],
                    'sequence' => $envelope['sequence'],
                    'checksum' => $envelope['checksum'],
                    'compressed_bytes' => $compressedBytes,
                    'decompressed_bytes' => $decompressedBytes,
                    'event_started_at' => $envelope['event_started_at'],
                    'event_ended_at' => $envelope['event_ended_at'],
                    'object_key' => $objectKey,
                ]);

                $session->incrementEach([
                    'chunk_count' => 1,
                    'compressed_bytes' => $compressedBytes,
                ]);

                if ($reorderDistance > $session->max_reorder_distance) {
                    $session->forceFill(['max_reorder_distance' => $reorderDistance])->save();
                }

                $this->recordPaths($session, $events);
                $this->recordMarkers($session, $events);
            } catch (Throwable $exception) {
                $disk->delete($objectKey);
                throw $exception;
            }

            return new ChunkIngestResult(false, $origin);
        }, 3);
    }

    private function assertNewSessionAllowed(Application $application): void
    {
        $dailySessions = RecordingSession::query()
            ->where('application_id', $application->getKey())
            ->where('created_at', '>=', today())
            ->count();

        if ($dailySessions >= $application->max_new_sessions_per_day) {
            $this->reject('daily_session_limit', 429);
        }

        $concurrentSessions = RecordingSession::query()
            ->where('application_id', $application->getKey())
            ->whereIn('status', [RecordingSessionStatus::Recording->value, RecordingSessionStatus::Closing->value])
            ->where('upload_cutoff_at', '>', now())
            ->count();

        if ($concurrentSessions >= $application->max_concurrent_sessions) {
            $this->reject('concurrent_session_limit', 429);
        }
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @param  array<string, int>  $ceilings
     */
    private function createSession(
        Application $application,
        ApplicationCredential $credential,
        array $envelope,
        string $origin,
        string $grantId,
        array $ceilings,
        DateTimeInterface $issuedAt,
        DateTimeInterface $expiresAt,
        int $maxEventTime,
        ?string $applicationUserId,
        ?string $releaseId,
    ): RecordingSession {
        $session = new RecordingSession;
        $session->fill([
            'application_id' => $application->getKey(),
            'application_credential_id' => $credential->getKey(),
            'session_id' => $envelope['session_id'],
            'grant_id_hash' => hash('sha256', $grantId),
            'origin' => $origin,
            'protocol_version' => Envelope::VERSION,
            'max_chunks' => $ceilings['max_chunks'],
            'max_compressed_bytes' => $ceilings['max_compressed_bytes'],
            'max_chunk_bytes' => $ceilings['max_chunk_bytes'],
            'started_at' => $issuedAt,
            'max_event_time' => date_create_immutable('@'.$maxEventTime),
            'upload_cutoff_at' => $expiresAt,
            'maximum_expires_at' => $issuedAt->getTimestamp()
                + (int) config('reel_ingest.maximum_session_retention_seconds'),
            'expires_at' => $issuedAt->getTimestamp()
                + (int) config('reel_ingest.maximum_session_retention_seconds'),
            'delete_not_before' => $issuedAt->getTimestamp()
                + (int) config('reel_ingest.maximum_session_retention_seconds'),
            'application_user_id' => $applicationUserId,
            'release_id' => $releaseId,
            'status_changed_at' => now(),
        ]);
        $session->status = RecordingSessionStatus::Recording;
        $session->save();
        $session->recordInitialTransition('grant_accepted');

        return $session;
    }

    private function findOrCreateEpoch(RecordingSession $session, string $epochId): RecordingEpoch
    {
        $epoch = $session->epochs()->where('epoch_id', $epochId)->lockForUpdate()->first();

        if ($epoch instanceof RecordingEpoch) {
            return $epoch;
        }

        $epoch = new RecordingEpoch;
        $epoch->fill([
            'recording_session_id' => $session->getKey(),
            'epoch_id' => $epochId,
        ]);
        $epoch->forceFill([
            'status' => RecordingEpochStatus::Active,
            'ordinal' => ((int) $session->epochs()->max('ordinal')) + 1,
        ]);
        $epoch->save();
        $session->increment('epoch_count');
        $epoch->transitions()->create([
            'previous_state' => null,
            'new_state' => RecordingEpochStatus::Active->value,
            'reason' => 'first_chunk_received',
            'attempt' => 1,
            'transitioned_at' => now(),
        ]);

        return $epoch;
    }

    /** @param list<array<string, mixed>> $events */
    private function recordPaths(RecordingSession $session, array $events): void
    {
        $paths = [];

        foreach ($events as $event) {
            $data = $event['data'] ?? null;

            if (($event['type'] ?? null) !== 4
                || ! is_int($event['timestamp'] ?? null)
                || ! is_array($data)
                || ! is_string($data['href'] ?? null)
                || $data['href'] === '') {
                continue;
            }

            $paths[] = [
                'path' => mb_substr($data['href'], 0, 255),
                'timestamp' => $event['timestamp'],
            ];
        }

        if ($paths === []) {
            return;
        }

        usort($paths, fn (array $left, array $right): int => $left['timestamp'] <=> $right['timestamp']);
        $first = $paths[0];
        $last = $paths[array_key_last($paths)];
        $attributes = [];

        if ($session->initial_path_recorded_at === null
            || $first['timestamp'] < $session->initial_path_recorded_at) {
            $attributes['initial_path'] = $first['path'];
            $attributes['initial_path_recorded_at'] = $first['timestamp'];
        }

        if ($session->latest_path_recorded_at === null
            || $last['timestamp'] >= $session->latest_path_recorded_at) {
            $attributes['latest_path'] = $last['path'];
            $attributes['latest_path_recorded_at'] = $last['timestamp'];
        }

        if ($attributes !== []) {
            $session->forceFill($attributes)->save();
        }
    }

    /** @param list<array<string, mixed>> $events */
    private function recordMarkers(RecordingSession $session, array $events): void
    {
        foreach ($events as $event) {
            $data = $event['data'] ?? null;

            if (($event['type'] ?? null) !== 5
                || ! is_int($event['timestamp'] ?? null)
                || ! is_array($data)
                || ! in_array($data['tag'] ?? null, ['reel.error', 'reel.server_error'], true)
                || ! is_array($data['payload'] ?? null)) {
                continue;
            }

            $session->markers()->create([
                'application_id' => $session->application_id,
                'marker_type' => $data['tag'] === 'reel.server_error' ? 'server_error' : 'error',
                'occurred_at' => $event['timestamp'],
                'metadata' => [
                    'method' => $data['payload']['method'],
                    'path' => $data['payload']['path'],
                    'status' => $data['payload']['status'],
                ],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @param  array<string, int>  $ceilings
     */
    private function failEpoch(
        Application $application,
        ApplicationCredential $credential,
        array $envelope,
        string $origin,
        string $grantId,
        array $ceilings,
        DateTimeInterface $issuedAt,
        DateTimeInterface $expiresAt,
        int $maxEventTime,
        ?string $applicationUserId,
        ?string $releaseId,
        string $reason,
    ): void {
        DB::transaction(function () use (
            $application,
            $credential,
            $envelope,
            $origin,
            $grantId,
            $ceilings,
            $issuedAt,
            $expiresAt,
            $maxEventTime,
            $applicationUserId,
            $releaseId,
            $reason,
        ): void {
            $lockedApplication = Application::query()->lockForUpdate()->find($application->getKey());
            $lockedCredential = ApplicationCredential::query()
                ->where('application_id', $application->getKey())
                ->lockForUpdate()
                ->find($credential->getKey());

            if (! $lockedApplication instanceof Application
                || ! $lockedApplication->ingest_enabled
                || ! $lockedCredential instanceof ApplicationCredential
                || ! $lockedCredential->isActive()) {
                $this->reject('inactive_ingest_authority', 401);
            }

            $session = RecordingSession::query()
                ->where('application_id', $lockedApplication->getKey())
                ->where('session_id', $envelope['session_id'])
                ->lockForUpdate()
                ->first();

            if (! $session instanceof RecordingSession) {
                $this->assertNewSessionAllowed($lockedApplication);
                $session = $this->createSession(
                    $lockedApplication,
                    $lockedCredential,
                    $envelope,
                    $origin,
                    $grantId,
                    $ceilings,
                    $issuedAt,
                    $expiresAt,
                    $maxEventTime,
                    $applicationUserId,
                    $releaseId,
                );
            }

            $epoch = $this->findOrCreateEpoch($session, $envelope['epoch_id']);

            if ($epoch->status === RecordingEpochStatus::Active) {
                $epoch->fail('privacy_'.$reason);
            }
        }, 3);
    }

    /** @param array<string, int> $ceilings */
    private function assertSessionBinding(
        RecordingSession $session,
        ApplicationCredential $credential,
        string $origin,
        string $grantId,
        array $ceilings,
        int $maxEventTime,
        DateTimeInterface $expiresAt,
        ?string $applicationUserId,
        ?string $releaseId,
    ): void {
        if ($session->application_credential_id !== $credential->getKey()
            || ! hash_equals($session->grant_id_hash, hash('sha256', $grantId))
            || $session->origin !== $origin
            || $session->max_chunks !== $ceilings['max_chunks']
            || $session->max_compressed_bytes !== $ceilings['max_compressed_bytes']
            || $session->max_chunk_bytes !== $ceilings['max_chunk_bytes']
            || $session->max_event_time->getTimestamp() !== $maxEventTime
            || $session->upload_cutoff_at->getTimestamp() > $expiresAt->getTimestamp()
            || $session->application_user_id !== $applicationUserId
            || $session->release_id !== $releaseId) {
            $this->reject('session_grant_conflict', 409);
        }
    }

    /** @param array<string, mixed> $envelope */
    private function objectKey(Application $application, RecordingSession $session, array $envelope): string
    {
        return implode('/', [
            trim((string) config('reel_ingest.object_prefix'), '/'),
            $application->public_id,
            $session->session_id,
            $envelope['epoch_id'],
            $envelope['sequence'].'-'.$envelope['checksum'].'.json.gz',
        ]);
    }

    private function reject(string $reason, int $status): never
    {
        throw new IngestRejected($reason, $status);
    }
}
