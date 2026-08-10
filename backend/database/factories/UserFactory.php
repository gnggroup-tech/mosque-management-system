<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'status' => AccountStatus::Active,
            'activated_at' => now(),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function pendingEmail(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AccountStatus::PendingEmail,
            'email_verified_at' => null,
            'activated_at' => null,
        ]);
    }

    public function pendingApproval(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AccountStatus::PendingApproval,
            'activated_at' => null,
        ]);
    }

    public function suspended(?string $reason = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AccountStatus::Suspended,
            'suspended_at' => now(),
            'suspension_reason' => $reason ?? 'Suspended for testing.',
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AccountStatus::Archived,
            'archived_at' => now(),
        ]);
    }
}
