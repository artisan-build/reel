<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\ApplicationCredential;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApplicationCredential>
 */
class ApplicationCredentialFactory extends Factory
{
    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'public_key' => null,
            'algorithm' => ApplicationCredential::ALGORITHM,
            'status' => null,
            'enrollment_code_hash' => Hash::make(Str::random(32)),
            'enrollment_expires_at' => now()->addMinutes(15),
            'enrolled_at' => null,
            'revoked_at' => null,
        ];
    }
}
