<?php

namespace App\Services;

use App\Enums\CredentialStatus;
use App\Enums\RecordingSessionStatus;
use App\Exceptions\IngestRejected;
use App\Models\Application;
use App\Models\ApplicationCredential;
use App\Models\RecordingChunk;
use App\Models\RecordingSession;
use ArtisanBuild\ReelClient\Envelope;
use ArtisanBuild\ReelClient\KeyMaterial;
use ArtisanBuild\ReelClient\SessionGrantContext;
use ArtisanBuild\ReelClient\SessionGrantVerifier;
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

        if (! is_array($ceilings)
            || ! $issuedAt instanceof DateTimeInterface
            || ! $expiresAt instanceof DateTimeInterface
            || ! is_int($maxEventTime)
            || ! is_string($grantId)) {
            $this->reject('invalid_grant_claims', 401);
        }

        $compressed = base64_decode((string) $envelope['payload'], true);

        if ($compressed === false || strlen($compressed) > $ceilings['max_chunk_bytes']) {
            $this->reject('compressed_chunk_too_large', 413);
        }

        if (! hash_equals($envelope['checksum'], hash('sha256', $compressed))) {
            $this->reject('checksum_mismatch', 422);
        }

        $decompressed = $this->decompress($compressed);

        try {
            $events = json_decode($decompressed, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->reject('invalid_event_json', 422);
        }

        $this->privacyValidator->validate($events);

        if ($envelope['event_started_at'] < $issuedAt->getTimestamp() * 1000
            || $envelope['event_ended_at'] > $maxEventTime * 1000) {
            $this->reject('event_time_outside_grant', 422);
        }

        $result = $this->persist(
            $application,
            $credential,
            $envelope,
            $compressed,
            strlen($decompressed),
            $origin,
            $grantId,
            $ceilings,
            $issuedAt,
            $expiresAt,
            $maxEventTime,
        );

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

    private function decompress(string $compressed): string
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

        return $decompressed;
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
        ): ChunkIngestResult {
            $lockedApplication = Application::query()->lockForUpdate()->find($application->getKey());

            if (! $lockedApplication instanceof Application || ! $lockedApplication->ingest_enabled) {
                $this->reject('application_disabled', 403);
            }

            $session = RecordingSession::query()
                ->where('application_id', $lockedApplication->getKey())
                ->where('session_id', $envelope['session_id'])
                ->lockForUpdate()
                ->first();

            if (! $session instanceof RecordingSession) {
                $this->assertNewSessionAllowed($lockedApplication);
                $session = RecordingSession::query()->create([
                    'application_id' => $lockedApplication->getKey(),
                    'application_credential_id' => $credential->getKey(),
                    'session_id' => $envelope['session_id'],
                    'grant_id_hash' => hash('sha256', $grantId),
                    'origin' => $origin,
                    'status' => RecordingSessionStatus::Recording,
                    'protocol_version' => Envelope::VERSION,
                    'max_chunks' => $ceilings['max_chunks'],
                    'max_compressed_bytes' => $ceilings['max_compressed_bytes'],
                    'max_chunk_bytes' => $ceilings['max_chunk_bytes'],
                    'started_at' => $issuedAt,
                    'max_event_time' => date_create_immutable('@'.$maxEventTime),
                    'upload_cutoff_at' => $expiresAt,
                ]);
                $this->recordTransition($session, null, RecordingSessionStatus::Recording, 'grant_accepted');
            } else {
                $this->assertSessionBinding($session, $credential, $origin, $grantId, $ceilings, $maxEventTime, $expiresAt);
            }

            if (now()->greaterThanOrEqualTo($session->upload_cutoff_at)) {
                $this->reject('upload_cutoff_elapsed', 409);
            }

            if ($session->status === RecordingSessionStatus::Recording
                && now()->greaterThanOrEqualTo($session->max_event_time)) {
                $this->transitionToClosing($session);
            }

            if (! in_array($session->status, [RecordingSessionStatus::Recording, RecordingSessionStatus::Closing], true)) {
                $this->reject('session_not_accepting_uploads', 409);
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

            $compressedBytes = strlen($compressed);

            if ($session->chunk_count + 1 > $session->max_chunks) {
                $this->reject('session_chunk_limit', 413);
            }

            if ($session->compressed_bytes + $compressedBytes > $session->max_compressed_bytes) {
                $this->reject('session_byte_limit', 413);
            }

            $maxSequence = $session->chunks()
                ->where('epoch_id', $envelope['epoch_id'])
                ->max('sequence');
            $highestAllowed = ($maxSequence === null ? -1 : (int) $maxSequence)
                + (int) config('reel_ingest.maximum_epoch_reorder_distance');

            if ($envelope['sequence'] > $highestAllowed) {
                $this->reject('reorder_distance_exceeded', 409);
            }

            $isNewEpoch = ! $session->chunks()->where('epoch_id', $envelope['epoch_id'])->exists();
            $objectKey = $this->objectKey($lockedApplication, $session, $envelope);
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
            } catch (Throwable $exception) {
                $disk->delete($objectKey);
                throw $exception;
            }

            $session->incrementEach([
                'chunk_count' => 1,
                'compressed_bytes' => $compressedBytes,
                'epoch_count' => $isNewEpoch ? 1 : 0,
            ]);

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

    /** @param array<string, int> $ceilings */
    private function assertSessionBinding(
        RecordingSession $session,
        ApplicationCredential $credential,
        string $origin,
        string $grantId,
        array $ceilings,
        int $maxEventTime,
        DateTimeInterface $expiresAt,
    ): void {
        if ($session->application_credential_id !== $credential->getKey()
            || ! hash_equals($session->grant_id_hash, hash('sha256', $grantId))
            || $session->origin !== $origin
            || $session->max_chunks !== $ceilings['max_chunks']
            || $session->max_compressed_bytes !== $ceilings['max_compressed_bytes']
            || $session->max_chunk_bytes !== $ceilings['max_chunk_bytes']
            || $session->max_event_time->getTimestamp() !== $maxEventTime
            || $session->upload_cutoff_at->getTimestamp() > $expiresAt->getTimestamp()) {
            $this->reject('session_grant_conflict', 409);
        }
    }

    private function transitionToClosing(RecordingSession $session): void
    {
        $previous = $session->status;
        $session->update([
            'status' => RecordingSessionStatus::Closing,
            'closing_at' => now(),
        ]);
        $this->recordTransition($session, $previous, RecordingSessionStatus::Closing, 'maximum_event_time_reached');
    }

    private function recordTransition(
        RecordingSession $session,
        ?RecordingSessionStatus $previous,
        RecordingSessionStatus $next,
        string $reason,
    ): void {
        $session->transitions()->create([
            'previous_state' => $previous?->value,
            'new_state' => $next->value,
            'reason' => $reason,
            'attempt' => 1,
            'transitioned_at' => now(),
        ]);
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
