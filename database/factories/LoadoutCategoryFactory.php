<?php

namespace Database\Factories;

use App\Models\LoadoutCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoadoutCategory>
 */
class LoadoutCategoryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'title' => fake()->words(2, true),
            'compartment' => LoadoutCategory::KIT,
            'vehicle_type' => 'RTW',
        ];
    }
}
