<?php

namespace App\Services;

use App\Enums\RecordingSessionStatus;
use App\Exceptions\RetentionRejected;
use App\Models\RecordingSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class RecordingProtection
{
    public function protect(int $recordingSessionId, User $actor): bool
    {
        return DB::transaction(function () use ($recordingSessionId, $actor): bool {
            $session = RecordingSession::query()->lockForUpdate()->findOrFail($recordingSessionId);

            if ($session->status !== RecordingSessionStatus::Ready) {
                throw new RetentionRejected('session_not_protectable', 409);
            }

            if ($session->protected_at !== null) {
                return false;
            }

            $occurredAt = now();
            $session->forceFill([
                'protected_at' => $occurredAt,
                'protected_by' => $actor->getKey(),
                'unprotected_at' => null,
            ])->save();
            $session->protectionEvents()->create([
                'actor_user_id' => $actor->getKey(),
                'actor_name' => $actor->name,
                'action' => 'protected',
                'occurred_at' => $occurredAt,
            ]);

            return true;
        }, 3);
    }

    public function unprotect(int $recordingSessionId, User $actor): bool
    {
        return DB::transaction(function () use ($recordingSessionId, $actor): bool {
            $session = RecordingSession::query()->lockForUpdate()->findOrFail($recordingSessionId);

            if ($session->status !== RecordingSessionStatus::Ready) {
                throw new RetentionRejected('session_not_unprotectable', 409);
            }

            if ($session->protected_at === null) {
                return false;
            }

            if (! $actor->is_admin && ($session->protected_by === null || $session->protected_by !== $actor->getKey())) {
                throw new RetentionRejected('protection_owned_by_another_user', 403);
            }

            $occurredAt = now();
            $coolingEndsAt = $occurredAt->addHours((int) config('reel_retention.unprotect_cooling_hours'));
            $expiresAt = $session->expires_at === null ? null : CarbonImmutable::parse($session->expires_at);
            $deleteNotBefore = $expiresAt !== null && $expiresAt->isAfter($coolingEndsAt)
                ? $expiresAt
                : $coolingEndsAt;

            $session->forceFill([
                'protected_at' => null,
                'protected_by' => null,
                'unprotected_at' => $occurredAt,
                'delete_not_before' => $deleteNotBefore,
            ])->save();
            $session->protectionEvents()->create([
                'actor_user_id' => $actor->getKey(),
                'actor_name' => $actor->name,
                'action' => 'unprotected',
                'occurred_at' => $occurredAt,
            ]);

            return true;
        }, 3);
    }
}
