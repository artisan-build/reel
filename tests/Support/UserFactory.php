<?php

declare(strict_types=1);

namespace Tests\Support;

use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<User> */
final class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => Hash::make('test-created-password'),
            'role' => UserRole::Member->value,
            'status' => 'active',
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (): array => ['role' => UserRole::Admin->value]);
    }

    public function owner(): static
    {
        return $this->state(fn (): array => ['role' => UserRole::Owner->value]);
    }
}
