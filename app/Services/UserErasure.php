<?php

namespace App\Services;

use App\Enums\RecordingSessionStatus;
use App\Exceptions\RetentionRejected;
use App\Jobs\DeleteUserErasureBatch;
use App\Models\Application;
use App\Models\RecordingSession;
use App\Models\User;
use App\Models\UserErasureAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class UserErasure
{
    public function __construct(private readonly RecordingDeletion $deletion) {}

    public function erase(Application $application, string $applicationUserId, User $actor, bool $confirmed): UserErasureAudit
    {
        if (! $actor->is_admin) {
            throw new RetentionRejected('administrator_required', 403);
        }

        if (! $confirmed) {
            throw new RetentionRejected('erasure_confirmation_required', 422);
        }

        $audit = DB::transaction(function () use ($application, $applicationUserId, $actor): UserErasureAudit {
            $sessions = $application->recordingSessions()
                ->where('application_user_id', $applicationUserId)
                ->where('status', '!=', RecordingSessionStatus::Deleted)
                ->lockForUpdate()
                ->get(['id', 'erasure_batch_id']);

            if ($sessions->contains(fn (RecordingSession $session): bool => $session->erasure_batch_id !== null)) {
                throw new RetentionRejected('erasure_already_running', 409);
            }

            $batchId = (string) Str::uuid();
            $audit = UserErasureAudit::query()->create([
                'batch_id' => $batchId,
                'actor_user_id' => $actor->getKey(),
                'actor_name' => $actor->name,
                'application_id' => $application->getKey(),
                'requested_at' => now(),
                'matched_count' => $sessions->count(),
                'outcome' => 'running',
            ]);

            RecordingSession::query()
                ->whereKey($sessions->modelKeys())
                ->update(['erasure_batch_id' => $batchId, 'updated_at' => now()]);

            DeleteUserErasureBatch::dispatch($batchId)->afterCommit();

            return $audit;
        }, 3);

        return $audit->fresh();
    }

    public function processBatch(string $batchId): void
    {
        $audit = UserErasureAudit::query()->where('batch_id', $batchId)->firstOrFail();
        $sessionIds = RecordingSession::query()
            ->where('erasure_batch_id', $batchId)
            ->where('status', '!=', RecordingSessionStatus::Deleted)
            ->orderBy('id')
            ->pluck('id');
        $failed = 0;

        foreach ($sessionIds as $sessionId) {
            $failed += (int) ! $this->deletion->delete((int) $sessionId, 'application_user_erasure');
        }

        $deleted = RecordingSession::query()
            ->where('erasure_batch_id', $batchId)
            ->where('status', RecordingSessionStatus::Deleted)
            ->count();
        $remaining = RecordingSession::query()
            ->where('erasure_batch_id', $batchId)
            ->where('status', '!=', RecordingSessionStatus::Deleted)
            ->count();
        $audit->forceFill([
            'deleted_count' => $deleted,
            'failed_count' => $remaining,
            'outcome' => $remaining === 0 ? 'completed' : 'running',
            'completed_at' => $remaining === 0 ? now() : null,
        ])->save();

        if ($failed > 0 || $remaining > 0) {
            throw new RuntimeException('The user erasure batch remains incomplete.');
        }
    }

    public function markBatchFailed(string $batchId): void
    {
        $deleted = RecordingSession::query()
            ->where('erasure_batch_id', $batchId)
            ->where('status', RecordingSessionStatus::Deleted)
            ->count();
        $remaining = RecordingSession::query()
            ->where('erasure_batch_id', $batchId)
            ->where('status', '!=', RecordingSessionStatus::Deleted)
            ->count();

        UserErasureAudit::query()->where('batch_id', $batchId)->update([
            'deleted_count' => $deleted,
            'failed_count' => $remaining,
            'outcome' => 'partial_failure',
            'completed_at' => now(),
        ]);
    }
}
