<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        $name = fake()->name();

        return [
            'name' => $name,
            'short_name' => Str::limit(strtok($name, ' ').' '.Str::upper(Str::random(3)), 40, ''),
            'email' => fake()->unique()->safeEmail(),
            'role' => Role::Mecanico,
            'active' => true,
            'must_change_password' => false,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function gestor(): static
    {
        return $this->state(['role' => Role::Gestor]);
    }

    public function atendimento(): static
    {
        return $this->state(['role' => Role::Atendimento]);
    }

    public function mecanico(): static
    {
        return $this->state(['role' => Role::Mecanico]);
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }

    public function withTemporaryPassword(): static
    {
        return $this->state(['must_change_password' => true]);
    }
}
