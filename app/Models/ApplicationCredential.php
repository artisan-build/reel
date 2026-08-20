<?php

namespace App\Models;

use App\Enums\CredentialStatus;
use Database\Factories\ApplicationCredentialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperApplicationCredential
 */
#[Fillable([
    'application_id',
    'public_key',
    'algorithm',
    'status',
    'enrollment_code_hash',
    'enrollment_expires_at',
    'enrolled_at',
    'revoked_at',
])]
#[Hidden(['enrollment_code_hash'])]
class ApplicationCredential extends Model
{
    /** @use HasFactory<ApplicationCredentialFactory> */
    use HasFactory;

    public const string ALGORITHM = 'RS256';

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function isActive(): bool
    {
        return $this->status === CredentialStatus::Active
            && $this->enrolled_at !== null
            && $this->revoked_at === null;
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'status' => CredentialStatus::class,
            'enrollment_expires_at' => 'immutable_datetime',
            'enrolled_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
