<?php

namespace Database\Factories;

use App\Models\DashboardCategory;
use App\Models\DashboardLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DashboardLink>
 */
class DashboardLinkFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'dashboard_category_id' => DashboardCategory::factory(),
            'title' => fake()->words(2, true),
            'url' => '/vehicles',
            'icon' => 'fa-solid fa-truck',
        ];
    }
}
