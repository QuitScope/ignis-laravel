<?php

namespace Database\Factories;

use App\Models\VehicleRegistryEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleRegistryEntry>
 */
class VehicleRegistryEntryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->bothify('Florian ##/##'),
            'identifier' => fake()->bothify('??-###'),
            'service' => 0,
            'status' => VehicleRegistryEntry::PENDING,
        ];
    }
}
