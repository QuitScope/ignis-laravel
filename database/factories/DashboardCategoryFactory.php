<?php

namespace Database\Factories;

use App\Models\DashboardCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DashboardCategory>
 */
class DashboardCategoryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['title' => fake()->words(2, true)];
    }
}
