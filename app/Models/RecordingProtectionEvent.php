<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperRecordingProtectionEvent
 */
#[Fillable(['actor_id', 'action', 'occurred_at'])]
class RecordingProtectionEvent extends Model
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
        return ['occurred_at' => 'immutable_datetime'];
    }
}
