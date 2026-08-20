<?php

namespace App\Models;

use App\Enums\RecordingSessionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property RecordingSessionStatus $status
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable $max_event_time
 * @property CarbonImmutable $upload_cutoff_at
 * @property CarbonImmutable|null $closing_at
 */
#[Fillable([
    'application_id',
    'application_credential_id',
    'session_id',
    'grant_id_hash',
    'origin',
    'status',
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
