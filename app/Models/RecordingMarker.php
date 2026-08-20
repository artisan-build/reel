<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'application_id',
    'recording_session_id',
    'marker_type',
    'occurred_at',
    'metadata',
])]
class RecordingMarker extends Model
{
    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** @return BelongsTo<RecordingSession, $this> */
    public function recordingSession(): BelongsTo
    {
        return $this->belongsTo(RecordingSession::class);
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'occurred_at' => 'integer',
            'metadata' => 'array',
        ];
    }
}
