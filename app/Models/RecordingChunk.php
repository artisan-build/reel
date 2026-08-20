<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperRecordingChunk
 */
#[Fillable([
    'application_id',
    'recording_session_id',
    'epoch_id',
    'sequence',
    'checksum',
    'compressed_bytes',
    'decompressed_bytes',
    'event_started_at',
    'event_ended_at',
    'object_key',
])]
class RecordingChunk extends Model
{
    /** @return BelongsTo<RecordingSession, $this> */
    public function recordingSession(): BelongsTo
    {
        return $this->belongsTo(RecordingSession::class);
    }
}
