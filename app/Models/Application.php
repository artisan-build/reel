<?php

namespace App\Models;

use App\Enums\CaptureSeverity;
use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @mixin IdeHelperApplication
 */
#[Fillable([
    'name',
    'allowed_origins',
    'severity',
    'mask_selectors',
    'block_selectors',
    'excluded_paths',
    'sampling_percent',
    'ingest_enabled',
    'max_new_sessions_per_day',
    'max_concurrent_sessions',
    'max_chunks_per_session',
    'max_compressed_bytes_per_session',
    'max_compressed_chunk_bytes',
])]
class Application extends Model
{
    /** @use HasFactory<ApplicationFactory> */
    use HasFactory;

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (Application $application): void {
            $application->public_id ??= (string) Str::ulid();
        });
    }

    /**
     * @return HasMany<ApplicationCredential, $this>
     */
    public function credentials(): HasMany
    {
        return $this->hasMany(ApplicationCredential::class);
    }

    /**
     * @return HasMany<RecordingSession, $this>
     */
    public function recordingSessions(): HasMany
    {
        return $this->hasMany(RecordingSession::class);
    }

    #[\Override]
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'allowed_origins' => 'array',
            'severity' => CaptureSeverity::class,
            'mask_selectors' => 'array',
            'block_selectors' => 'array',
            'excluded_paths' => 'array',
            'sampling_percent' => 'integer',
            'ingest_enabled' => 'boolean',
            'max_new_sessions_per_day' => 'integer',
            'max_concurrent_sessions' => 'integer',
            'max_chunks_per_session' => 'integer',
            'max_compressed_bytes_per_session' => 'integer',
            'max_compressed_chunk_bytes' => 'integer',
        ];
    }
}
