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
    'closing_cutoff_at',
    'ended_at',
    'maximum_expires_at',
    'status_changed_at',
    'failure_code',
    'is_complete',
    'incomplete_reasons',
    'gap_count',
    'max_reorder_distance',
    'concurrent_epoch_count',
    'initial_path',
    'latest_path',
    'initial_path_recorded_at',
    'latest_path_recorded_at',
    'application_user_id',
    'release_id',
    'duration_seconds',
    'protected_at',
    'protected_by',
    'expires_at',
    'delete_not_before',
    'unprotected_at',
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

    /** @return HasMany<RecordingMarker, $this> */
    public function markers(): HasMany
    {
        return $this->hasMany(RecordingMarker::class);
    }

    /** @return HasMany<ReplayView, $this> */
    public function replayViews(): HasMany
    {
        return $this->hasMany(ReplayView::class);
    }

    /** @return HasMany<RecordingProtectionEvent, $this> */
    public function protectionEvents(): HasMany
    {
        return $this->hasMany(RecordingProtectionEvent::class);
    }

    /** @return BelongsTo<User, $this> */
    public function protectionOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'protected_by');
    }

    #[\Override]
    public function getRouteKeyName(): string
    {
        return 'session_id';
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
                RecordingSessionStatus::Recording => [RecordingSessionStatus::Closing, RecordingSessionStatus::Failed, RecordingSessionStatus::Deleting],
                RecordingSessionStatus::Closing => [RecordingSessionStatus::Compacting, RecordingSessionStatus::Failed, RecordingSessionStatus::Deleting],
                RecordingSessionStatus::Compacting => [RecordingSessionStatus::Ready, RecordingSessionStatus::Failed, RecordingSessionStatus::Deleting],
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
                $attributes['closing_cutoff_at'] = min(
                    $locked->upload_cutoff_at,
                    now()->addSeconds((int) config('reel_ingest.late_arrival_window_seconds')),
                );
            }

            $attributes['status_changed_at'] = now();

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
            'closing_cutoff_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'maximum_expires_at' => 'immutable_datetime',
            'status_changed_at' => 'immutable_datetime',
            'is_complete' => 'boolean',
            'incomplete_reasons' => 'array',
            'manifest' => 'array',
            'compacted_at' => 'immutable_datetime',
            'initial_path_recorded_at' => 'integer',
            'latest_path_recorded_at' => 'integer',
            'duration_seconds' => 'integer',
            'protected_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'delete_not_before' => 'immutable_datetime',
            'unprotected_at' => 'immutable_datetime',
            'deletion_started_at' => 'immutable_datetime',
            'deletion_completed_at' => 'immutable_datetime',
            'retention_skipped_at' => 'immutable_datetime',
        ];
    }
}
