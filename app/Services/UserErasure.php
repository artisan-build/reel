<?php

namespace App\Services;

use App\Enums\RecordingSessionStatus;
use App\Exceptions\RetentionRejected;
use App\Models\Application;
use App\Models\User;
use App\Models\UserErasureAudit;
use Illuminate\Support\Str;

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

        $sessionIds = $application->recordingSessions()
            ->where('application_user_id', $applicationUserId)
            ->where('status', '!=', RecordingSessionStatus::Deleted)
            ->pluck('id');
        $audit = UserErasureAudit::query()->create([
            'batch_id' => (string) Str::uuid(),
            'actor_user_id' => $actor->getKey(),
            'actor_name' => $actor->name,
            'application_id' => $application->getKey(),
            'requested_at' => now(),
            'matched_count' => $sessionIds->count(),
            'outcome' => 'running',
        ]);
        $deleted = 0;

        foreach ($sessionIds as $sessionId) {
            $deleted += (int) $this->deletion->delete((int) $sessionId, 'application_user_erasure', $actor);
        }

        $failed = $sessionIds->count() - $deleted;
        $audit->forceFill([
            'completed_at' => now(),
            'deleted_count' => $deleted,
            'failed_count' => $failed,
            'outcome' => $failed === 0 ? 'completed' : 'partial_failure',
        ])->save();

        return $audit->fresh();
    }
}
