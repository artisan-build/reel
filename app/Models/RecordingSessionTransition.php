<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperRecordingSessionTransition
 */
#[Fillable([
    'recording_session_id',
    'previous_state',
    'new_state',
    'reason',
    'attempt',
    'transitioned_at',
])]
class RecordingSessionTransition extends Model
{
    public $timestamps = false;

    /** @return BelongsTo<RecordingSession, $this> */
    public function recordingSession(): BelongsTo
    {
        return $this->belongsTo(RecordingSession::class);
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['transitioned_at' => 'immutable_datetime'];
    }
}
