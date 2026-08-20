<?php

namespace App\Models;

use App\Enums\RecordingSessionStatus;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * @mixin IdeHelperRecordingSession
 */
#[Fillable([
    'application_id',
    'application_credential_id',
    'session_id',
    'grant_id_hash',
    'origin',
    'protocol_version',
    'max_chunks',
    'max_compressed_bytes',
    'max_chunk_bytes',
    'chunk_count',
    'compressed_bytes',
    'conflicting_retry_count',
    'epoch_count',
    'started_at',
    'max_event_time',
    'upload_cutoff_at',
    'closing_at',
    'failure_code',
])]
class RecordingSession extends Model
{
    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** @return BelongsTo<ApplicationCredential, $this> */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(ApplicationCredential::class, 'application_credential_id');
    }

    /** @return HasMany<RecordingChunk, $this> */
    public function chunks(): HasMany
    {
        return $this->hasMany(RecordingChunk::class);
    }

    /** @return HasMany<RecordingSessionTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(RecordingSessionTransition::class);
    }

    /** @return HasMany<RecordingEpoch, $this> */
    public function epochs(): HasMany
    {
        return $this->hasMany(RecordingEpoch::class);
    }

    public function recordInitialTransition(string $reason, int $attempt = 1): void
    {
        DB::transaction(function () use ($reason, $attempt): void {
            $locked = self::query()->lockForUpdate()->findOrFail($this->getKey());

            if ($locked->status !== RecordingSessionStatus::Recording || $locked->transitions()->exists()) {
                throw new DomainException('The initial recording session transition is invalid.');
            }

            $locked->recordTransition(null, RecordingSessionStatus::Recording, $reason, $attempt);
        });
    }

    public function transitionTo(RecordingSessionStatus $next, string $reason, int $attempt = 1): void
    {
        DB::transaction(function () use ($next, $reason, $attempt): void {
            $locked = self::query()->lockForUpdate()->findOrFail($this->getKey());
            $allowed = match ($locked->status) {
                RecordingSessionStatus::Recording => [RecordingSessionStatus::Closing, RecordingSessionStatus::Failed],
                RecordingSessionStatus::Closing => [RecordingSessionStatus::Compacting, RecordingSessionStatus::Failed],
                RecordingSessionStatus::Compacting => [RecordingSessionStatus::Ready, RecordingSessionStatus::Failed],
                RecordingSessionStatus::Ready, RecordingSessionStatus::Failed => [RecordingSessionStatus::Deleting],
                RecordingSessionStatus::Deleting => [RecordingSessionStatus::Deleted],
                RecordingSessionStatus::Deleted => [],
            };

            if (! in_array($next, $allowed, true)) {
                throw new DomainException('The recording session transition is invalid.');
            }

            $previous = $locked->status;
            $attributes = ['status' => $next];

            if ($next === RecordingSessionStatus::Closing) {
                $attributes['closing_at'] = now();
            }

            $locked->forceFill($attributes)->save();
            $locked->recordTransition($previous, $next, $reason, $attempt);
            $this->setRawAttributes($locked->getAttributes(), true);
        });
    }

    private function recordTransition(
        ?RecordingSessionStatus $previous,
        RecordingSessionStatus $next,
        string $reason,
        int $attempt,
    ): void {
        $this->transitions()->create([
            'previous_state' => $previous?->value,
            'new_state' => $next->value,
            'reason' => $reason,
            'attempt' => $attempt,
            'transitioned_at' => now(),
        ]);
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'status' => RecordingSessionStatus::class,
            'started_at' => 'immutable_datetime',
            'max_event_time' => 'immutable_datetime',
            'upload_cutoff_at' => 'immutable_datetime',
            'closing_at' => 'immutable_datetime',
        ];
    }
}
