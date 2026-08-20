<?php

namespace Database\Factories;

use App\Enums\CaptureSeverity;
use App\Models\Application;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Web',
            'allowed_origins' => ['https://'.fake()->unique()->domainName()],
            'severity' => CaptureSeverity::Inputs,
            'mask_selectors' => [],
            'block_selectors' => [],
            'excluded_paths' => [],
            'sampling_percent' => 100,
            'ingest_enabled' => true,
            'max_new_sessions_per_day' => 1_000,
            'max_concurrent_sessions' => 100,
            'max_chunks_per_session' => 360,
            'max_compressed_bytes_per_session' => 64 * 1024 * 1024,
            'max_compressed_chunk_bytes' => 256 * 1024,
            'max_daily_chunks' => 100_000,
            'max_daily_compressed_bytes' => 10 * 1024 * 1024 * 1024,
            'max_ingest_requests_per_minute' => 600,
        ];
    }
}
