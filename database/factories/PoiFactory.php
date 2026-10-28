<?php

namespace Database\Factories;

use App\Models\Poi;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Poi>
 */
class PoiFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'city' => fake()->city(),
            'type' => fake()->randomElement(Poi::STATION_TYPES),
            'is_active' => true,
        ];
    }
}
