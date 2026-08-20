<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperReplayView
 */
#[Fillable(['user_id', 'application_id', 'recording_session_id', 'viewed_at'])]
class ReplayView extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

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
        return ['viewed_at' => 'immutable_datetime'];
    }
}
