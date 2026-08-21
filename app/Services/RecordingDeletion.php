<?php

namespace App\Services;

use App\Enums\RecordingDeletionOutcome;
use App\Enums\RecordingSessionStatus;
use App\Models\RecordingSession;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RecordingDeletion
{
    public function __construct(
        private readonly OperationalCounters $counters,
        private readonly ObjectMutationLock $locks,
    ) {}

    public function delete(int $recordingSessionId, string $reason, ?User $actor = null): bool
    {
        return $this->run($recordingSessionId, $reason, $actor, false)->completed();
    }

    public function deleteIfDue(int $recordingSessionId): RecordingDeletionOutcome
    {
        return $this->run($recordingSessionId, 'retention_expired', null, true);
    }

    private function run(
        int $recordingSessionId,
        string $reason,
        ?User $actor,
        bool $respectRetention,
    ): RecordingDeletionOutcome {
        $prepared = DB::transaction(function () use (
            $recordingSessionId,
            $reason,
            $actor,
            $respectRetention,
        ): array {
            $locked = RecordingSession::query()->with('application')->lockForUpdate()->find($recordingSessionId);

            if (! $locked instanceof RecordingSession || $locked->status === RecordingSessionStatus::Deleted) {
                return [RecordingDeletionOutcome::AlreadyComplete, null];
            }

            if ($respectRetention && $locked->status !== RecordingSessionStatus::Deleting) {
                if ($locked->protected_at !== null) {
                    $this->recordSkip($locked, 'protected_after_selection');

                    return [RecordingDeletionOutcome::SkippedProtected, null];
                }

                if ($locked->delete_not_before === null || $locked->delete_not_before->isAfter(now())) {
                    $this->recordSkip($locked, 'deadline_moved_after_selection');

                    return [RecordingDeletionOutcome::SkippedDeadline, null];
                }
            }

            if ($locked->status !== RecordingSessionStatus::Deleting) {
                $previous = $locked->status;
                $locked->forceFill([
                    'status' => RecordingSessionStatus::Deleting,
                    'status_changed_at' => now(),
                    'deletion_started_at' => now(),
                    'deletion_actor_id' => $actor?->getKey(),
                    'deletion_reason' => $reason,
                ])->save();
                $locked->transitions()->create([
                    'previous_state' => $previous->value,
                    'new_state' => RecordingSessionStatus::Deleting->value,
                    'reason' => $reason,
                    'attempt' => 1,
                    'transitioned_at' => now(),
                ]);
            }

            $locked->increment('deletion_attempts');
            $locked->forceFill(['deletion_last_error' => null])->save();

            return [null, $locked->fresh(['application'])];
        }, 3);
        [$outcome, $session] = $prepared;

        if ($outcome instanceof RecordingDeletionOutcome) {
            return $outcome;
        }

        assert($session instanceof RecordingSession);
        $disk = Storage::disk((string) config('filesystems.default'));
        $prefix = $this->prefix($session);

        try {
            return $this->locks->synchronized($prefix, function () use (
                $disk,
                $prefix,
                $recordingSessionId,
            ): RecordingDeletionOutcome {
                $this->removePrefix($disk, $prefix);
                $remaining = $disk->allFiles($prefix);

                if ($remaining !== []) {
                    $this->recordIncomplete($recordingSessionId, 'objects_remain', count($remaining));

                    return RecordingDeletionOutcome::Incomplete;
                }

                DB::transaction(function () use ($recordingSessionId): void {
                    $locked = RecordingSession::query()->lockForUpdate()->find($recordingSessionId);

                    if (! $locked instanceof RecordingSession || $locked->status !== RecordingSessionStatus::Deleting) {
                        return;
                    }

                    $locked->chunks()->delete();
                    $locked->epochs()->delete();
                    $locked->markers()->delete();
                    $locked->replayViews()->delete();
                    $locked->forceFill([
                        'status' => RecordingSessionStatus::Deleted,
                        'status_changed_at' => now(),
                        'deletion_completed_at' => now(),
                        'deletion_last_error' => null,
                        'deletion_remaining_objects' => 0,
                        'application_user_id' => null,
                        'initial_path' => null,
                        'latest_path' => null,
                        'release_id' => null,
                        'manifest' => null,
                        'manifest_checksum' => null,
                        'protected_at' => null,
                        'protected_by' => null,
                    ])->save();
                    $locked->transitions()->create([
                        'previous_state' => RecordingSessionStatus::Deleting->value,
                        'new_state' => RecordingSessionStatus::Deleted->value,
                        'reason' => 'prefix_absence_verified',
                        'attempt' => $locked->deletion_attempts,
                        'transitioned_at' => now(),
                    ]);
                }, 3);

                return RecordingDeletionOutcome::Complete;
            });
        } catch (Throwable) {
            $this->recordIncomplete($recordingSessionId, 'object_store_error', 0);

            return RecordingDeletionOutcome::Incomplete;
        }
    }

    public function prefix(RecordingSession $session): string
    {
        return implode('/', [
            trim((string) config('reel_ingest.object_prefix'), '/'),
            $session->application->public_id,
            $session->session_id,
        ]);
    }

    private function removePrefix(FilesystemAdapter $disk, string $prefix): void
    {
        foreach ($disk->allFiles($prefix) as $object) {
            $disk->delete($object);
        }
    }

    private function recordIncomplete(int $recordingSessionId, string $error, int $remaining): void
    {
        RecordingSession::query()
            ->whereKey($recordingSessionId)
            ->where('status', RecordingSessionStatus::Deleting)
            ->update([
                'deletion_last_error' => $error,
                'deletion_remaining_objects' => $remaining,
                'updated_at' => now(),
            ]);
    }

    private function recordSkip(RecordingSession $session, string $reason): void
    {
        $session->forceFill([
            'retention_skipped_at' => now(),
            'retention_skip_reason' => $reason,
        ])->save();
        $this->counters->increment('retention_deletion_skips');
    }
}
