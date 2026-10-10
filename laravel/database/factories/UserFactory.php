<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => null,
            'password' => 'password', // hashed by the model cast
            'role' => User::ROLE_CANDIDATE,
            'status' => 'active',
        ];
    }

    public function role(string $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }
}
