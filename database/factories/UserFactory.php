<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'discord_id' => (string) fake()->unique()->numerify('1#################'),
            'username' => fake()->unique()->userName(),
            'name' => fake()->name(),
            'is_active' => true,
            'is_super_admin' => false,
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(['is_super_admin' => true]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false, 'deactivated_at' => now()]);
    }

    public function withRole(Role $role): static
    {
        return $this->afterCreating(fn (User $u) => $u->setRole($role));
    }
}
