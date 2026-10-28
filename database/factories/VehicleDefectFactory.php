<?php

namespace Database\Factories;

use App\Models\Vehicle;
use App\Models\VehicleDefect;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleDefect>
 */
class VehicleDefectFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'title' => fake()->sentence(3),
            'category' => fake()->randomElement(VehicleDefect::CATEGORIES),
            'is_operable' => true,
            'status' => VehicleDefect::OPEN,
        ];
    }

    public function blocking(): static
    {
        return $this->state(['is_operable' => false]);
    }

    public function resolved(): static
    {
        return $this->state(['status' => VehicleDefect::RESOLVED, 'resolved_at' => now()]);
    }
}
