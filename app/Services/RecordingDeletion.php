<?php

namespace App\Services;

use App\Enums\RecordingSessionStatus;
use App\Models\RecordingSession;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RecordingDeletion
{
    public function delete(int $recordingSessionId, string $reason, ?User $actor = null): bool
    {
        $session = DB::transaction(function () use ($recordingSessionId, $reason, $actor): ?RecordingSession {
            $locked = RecordingSession::query()->with('application')->lockForUpdate()->find($recordingSessionId);

            if (! $locked instanceof RecordingSession || $locked->status === RecordingSessionStatus::Deleted) {
                return null;
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

            return $locked->fresh(['application']);
        }, 3);

        if (! $session instanceof RecordingSession) {
            return true;
        }

        $disk = Storage::disk((string) config('filesystems.default'));
        $prefix = $this->prefix($session);

        try {
            $this->removePrefix($disk, $prefix);
            $remaining = $disk->allFiles($prefix);
        } catch (Throwable $exception) {
            $this->recordIncomplete($recordingSessionId, 'object_store_error', 0);

            return false;
        }

        if ($remaining !== []) {
            $this->recordIncomplete($recordingSessionId, 'objects_remain', count($remaining));

            return false;
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

        return true;
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
}
