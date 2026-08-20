<?php

namespace App\Services;

use App\Enums\RecordingSessionStatus;
use App\Events\CompactionCandidateVerified;
use App\Events\CompactionCandidateWritten;
use App\Events\CompactionPublished;
use App\Jobs\CleanupCompactionCandidate;
use App\Models\RecordingChunk;
use App\Models\RecordingSession;
use ArtisanBuild\ReelClient\Envelope;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RecordingCompactor
{
    public function __construct(
        private readonly ReplayManifest $manifestReader,
        private readonly OperationalCounters $counters,
    ) {}

    public function compact(int $recordingSessionId): void
    {
        $startedAt = hrtime(true);
        $diskName = (string) config('filesystems.default');
        $candidateKey = null;
        $published = false;

        $session = DB::transaction(function () use ($recordingSessionId): ?RecordingSession {
            $locked = RecordingSession::query()->lockForUpdate()->find($recordingSessionId);

            if (! $locked instanceof RecordingSession) {
                return null;
            }

            if ($locked->status === RecordingSessionStatus::Ready && $locked->manifest !== null) {
                $locked->increment('compaction_noop_count');
                $this->counters->increment('compaction_noop_duplicates');

                return $locked->fresh(['application']);
            }

            if ($locked->status !== RecordingSessionStatus::Compacting) {
                return null;
            }

            $locked->increment('compaction_attempts');

            return $locked->fresh(['application']);
        });

        if (! $session instanceof RecordingSession) {
            return;
        }

        try {
            $disk = Storage::disk($diskName);

            if ($session->status === RecordingSessionStatus::Ready) {
                $this->removeTemporaryChunks($disk, $session);

                return;
            }

            $candidateKey = implode('/', [
                trim((string) config('reel_ingest.object_prefix'), '/'),
                $session->application->public_id,
                $session->session_id,
                'candidates',
                Str::uuid().'.jsonl.gz',
            ]);
            [$candidateChecksum, $candidateBytes] = $this->writeCandidate($disk, $session, $candidateKey);
            CompactionCandidateWritten::dispatch($recordingSessionId, $candidateKey);
            $this->verifyCandidate($disk, $candidateKey, $candidateChecksum, $candidateBytes, $session);

            $eventStartedAt = $session->chunks()->min('event_started_at');
            $eventEndedAt = $session->chunks()->max('event_ended_at');
            $manifest = [
                'manifest_version' => 1,
                'envelope_version' => $session->protocol_version,
                'rrweb_version' => Envelope::RRWEB_VERSION,
                'compression' => Envelope::COMPRESSION,
                'objects' => [[
                    'key' => $candidateKey,
                    'checksum' => $candidateChecksum,
                    'bytes' => $candidateBytes,
                ]],
                'event_started_at' => $eventStartedAt === null ? null : (int) $eventStartedAt,
                'event_ended_at' => $eventEndedAt === null ? null : (int) $eventEndedAt,
                'epoch_count' => $session->epoch_count,
                'chunk_count' => $session->chunk_count,
                'gap_count' => $session->gap_count,
                'incomplete' => ! (bool) $session->is_complete,
                'incomplete_reasons' => $session->incomplete_reasons,
                'compaction_state' => 'ready',
            ];
            $manifestChecksum = $this->manifestReader->checksum($manifest);

            $this->manifestReader->read($manifest, $manifestChecksum, $session);

            CompactionCandidateVerified::dispatch($recordingSessionId, $candidateKey);

            $publication = DB::transaction(function () use (
                $recordingSessionId,
                $manifest,
                $manifestChecksum,
            ): string {
                $locked = RecordingSession::query()->lockForUpdate()->find($recordingSessionId);

                if (! $locked instanceof RecordingSession) {
                    return 'blocked';
                }

                if ($locked->status === RecordingSessionStatus::Ready && $locked->manifest !== null) {
                    $locked->increment('compaction_noop_count');

                    return 'duplicate';
                }

                if ($locked->status !== RecordingSessionStatus::Compacting) {
                    if ($locked->status === RecordingSessionStatus::Deleting) {
                        $this->counters->increment('post_delete_publish_preventions');
                    }

                    return 'blocked';
                }

                $attempt = $locked->compaction_attempts;
                $locked->forceFill([
                    'manifest' => $manifest,
                    'manifest_checksum' => $manifestChecksum,
                    'status' => RecordingSessionStatus::Ready,
                    'status_changed_at' => now(),
                    'compacted_at' => now(),
                ])->save();
                $locked->transitions()->create([
                    'previous_state' => RecordingSessionStatus::Compacting->value,
                    'new_state' => RecordingSessionStatus::Ready->value,
                    'reason' => 'manifest_published',
                    'attempt' => $attempt,
                    'transitioned_at' => now(),
                ]);

                return 'published';
            });

            if ($publication !== 'published') {
                CleanupCompactionCandidate::dispatch($diskName, $candidateKey);

                if ($publication === 'duplicate') {
                    $this->counters->increment('compaction_noop_duplicates');
                }

                return;
            }

            $published = true;
            CompactionPublished::dispatch($recordingSessionId);
            $this->removeTemporaryChunks($disk, $session);
        } catch (Throwable $exception) {
            if ($candidateKey !== null && ! $published) {
                CleanupCompactionCandidate::dispatch($diskName, $candidateKey);
            }

            throw $exception;
        } finally {
            $durationMs = max(1, (int) ((hrtime(true) - $startedAt) / 1_000_000));
            RecordingSession::query()
                ->whereKey($recordingSessionId)
                ->increment('compaction_duration_ms', $durationMs);
        }
    }

    public function markFailed(int $recordingSessionId): void
    {
        DB::transaction(function () use ($recordingSessionId): void {
            $session = RecordingSession::query()->lockForUpdate()->find($recordingSessionId);

            if (! $session instanceof RecordingSession
                || ! in_array($session->status, [
                    RecordingSessionStatus::Recording,
                    RecordingSessionStatus::Closing,
                    RecordingSessionStatus::Compacting,
                ], true)) {
                return;
            }

            $previous = $session->status;
            $attempt = max(1, $session->compaction_attempts);
            $session->forceFill([
                'status' => RecordingSessionStatus::Failed,
                'status_changed_at' => now(),
                'failure_code' => 'compaction_failed',
            ])->save();
            $session->transitions()->create([
                'previous_state' => $previous->value,
                'new_state' => RecordingSessionStatus::Failed->value,
                'reason' => 'compaction_failed',
                'attempt' => $attempt,
                'transitioned_at' => now(),
            ]);
        });
    }

    /** @return array{string, int} */
    private function writeCandidate(
        FilesystemAdapter $disk,
        RecordingSession $session,
        string $candidateKey,
    ): array {
        $candidate = fopen('php://temp/maxmemory:1048576', 'w+b');
        $deflater = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);

        if ($candidate === false || $deflater === false) {
            throw new RuntimeException('Unable to initialize the compaction stream.');
        }

        try {
            foreach ($session->chunks()->orderBy('epoch_id')->orderBy('sequence')->cursor() as $chunk) {
                $this->appendChunk($disk, $chunk, $candidate, $deflater);
            }

            $final = deflate_add($deflater, '', ZLIB_FINISH);

            if ($final === false || fwrite($candidate, $final) !== strlen($final)) {
                throw new RuntimeException('Unable to finish the compaction stream.');
            }

            $stats = fstat($candidate);
            $bytes = is_array($stats) ? (int) $stats['size'] : 0;
            rewind($candidate);
            $hash = hash_init('sha256');
            hash_update_stream($hash, $candidate);
            $checksum = hash_final($hash);
            rewind($candidate);

            if ($bytes < 1 || ! $disk->writeStream($candidateKey, $candidate)) {
                throw new RuntimeException('Unable to write the compaction candidate.');
            }

            return [$checksum, $bytes];
        } finally {
            fclose($candidate);
        }
    }

    /** @param resource $candidate */
    private function appendChunk(
        FilesystemAdapter $disk,
        RecordingChunk $chunk,
        mixed $candidate,
        mixed $deflater,
    ): void {
        $source = $disk->readStream($chunk->object_key);

        if ($source === false) {
            throw new RuntimeException('A temporary recording chunk is missing.');
        }

        $inflater = inflate_init(ZLIB_ENCODING_GZIP);
        $hash = hash_init('sha256');

        if ($inflater === false) {
            fclose($source);
            throw new RuntimeException('Unable to initialize a chunk stream.');
        }

        try {
            while (! feof($source)) {
                $input = fread($source, 64 * 1024);

                if ($input === false) {
                    throw new RuntimeException('Unable to read a temporary recording chunk.');
                }

                if ($input === '') {
                    continue;
                }

                hash_update($hash, $input);
                $flush = feof($source) ? ZLIB_FINISH : ZLIB_NO_FLUSH;
                $this->writeDeflated($candidate, $deflater, inflate_add($inflater, $input, $flush));
            }

            if (inflate_get_status($inflater) !== ZLIB_STREAM_END
                || ! hash_equals($chunk->checksum, hash_final($hash))) {
                throw new RuntimeException('A temporary recording chunk failed verification.');
            }

            $this->writeDeflated($candidate, $deflater, "\n");
        } finally {
            fclose($source);
        }
    }

    /** @param resource $candidate */
    private function writeDeflated(mixed $candidate, mixed $deflater, string|false $input): void
    {
        if ($input === false) {
            throw new RuntimeException('Unable to decode a temporary recording chunk.');
        }

        $output = deflate_add($deflater, $input, ZLIB_NO_FLUSH);

        if ($output === false || fwrite($candidate, $output) !== strlen($output)) {
            throw new RuntimeException('Unable to append to the compaction stream.');
        }
    }

    private function verifyCandidate(
        FilesystemAdapter $disk,
        string $candidateKey,
        string $expectedChecksum,
        int $expectedBytes,
        RecordingSession $session,
    ): void {
        $stream = $disk->readStream($candidateKey);

        if ($stream === false) {
            $this->recordCandidateFailure($session);
            throw new RuntimeException('The compaction candidate cannot be read.');
        }

        try {
            $hash = hash_init('sha256');
            $bytes = hash_update_stream($hash, $stream);
            $checksum = hash_final($hash);
        } finally {
            fclose($stream);
        }

        if ($bytes !== $expectedBytes || ! hash_equals($expectedChecksum, $checksum)) {
            $this->recordCandidateFailure($session);
            throw new RuntimeException('The compaction candidate failed verification.');
        }
    }

    private function recordCandidateFailure(RecordingSession $session): void
    {
        $session->increment('candidate_checksum_failure_count');
        $this->counters->increment('candidate_checksum_failures');
    }

    private function removeTemporaryChunks(FilesystemAdapter $disk, RecordingSession $session): void
    {
        foreach ($session->chunks()->whereNull('purged_at')->cursor() as $chunk) {
            $disk->delete($chunk->object_key);

            if (! $disk->exists($chunk->object_key)) {
                $chunk->forceFill(['purged_at' => now()])->save();
            }
        }
    }
}
