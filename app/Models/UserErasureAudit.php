<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperUserErasureAudit
 */
#[Fillable([
    'batch_id',
    'actor_user_id',
    'actor_name',
    'application_id',
    'requested_at',
    'completed_at',
    'matched_count',
    'deleted_count',
    'failed_count',
    'outcome',
])]
class UserErasureAudit extends Model
{
    public $timestamps = false;

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'requested_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
