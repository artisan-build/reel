<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'recording_epoch_id',
    'previous_state',
    'new_state',
    'reason',
    'attempt',
    'transitioned_at',
])]
class RecordingEpochTransition extends Model
{
    public $timestamps = false;

    /** @return BelongsTo<RecordingEpoch, $this> */
    public function recordingEpoch(): BelongsTo
    {
        return $this->belongsTo(RecordingEpoch::class);
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['transitioned_at' => 'immutable_datetime'];
    }
}
