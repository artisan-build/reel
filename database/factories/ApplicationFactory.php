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
        ];
    }
}
