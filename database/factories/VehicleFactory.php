<?php

namespace Database\Factories;

use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'identifier' => fake()->unique()->bothify('??-###'),
            'name' => fake()->unique()->bothify('Florian ##/##'),
            'type' => 'RTW',
            'service' => Vehicle::SERVICE_EMS,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
