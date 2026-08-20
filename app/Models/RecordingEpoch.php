<?php

namespace App\Models;

use App\Enums\RecordingEpochStatus;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @mixin IdeHelperRecordingEpoch
 */
#[Fillable(['recording_session_id', 'epoch_id', 'failure_code', 'terminal_sequence'])]
class RecordingEpoch extends Model
{
    /** @return BelongsTo<RecordingSession, $this> */
    public function recordingSession(): BelongsTo
    {
        return $this->belongsTo(RecordingSession::class);
    }

    /** @return HasMany<RecordingEpochTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(RecordingEpochTransition::class);
    }

    public function fail(string $reason, int $attempt = 1): void
    {
        if ($this->status !== RecordingEpochStatus::Active) {
            throw new DomainException('The recording epoch transition is invalid.');
        }

        $previous = $this->status;
        $this->forceFill([
            'status' => RecordingEpochStatus::Failed,
            'failure_code' => $reason,
        ])->save();
        $this->transitions()->create([
            'previous_state' => $previous->value,
            'new_state' => RecordingEpochStatus::Failed->value,
            'reason' => $reason,
            'attempt' => $attempt,
            'transitioned_at' => now(),
        ]);
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['status' => RecordingEpochStatus::class];
    }
}
