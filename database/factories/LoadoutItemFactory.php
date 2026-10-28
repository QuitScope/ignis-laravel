<?php

namespace Database\Factories;

use App\Models\LoadoutCategory;
use App\Models\LoadoutItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoadoutItem>
 */
class LoadoutItemFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'loadout_category_id' => LoadoutCategory::factory(),
            'title' => fake()->words(2, true),
            'quantity' => fake()->numberBetween(1, 5),
        ];
    }
}
