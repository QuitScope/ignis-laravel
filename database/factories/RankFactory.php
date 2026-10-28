<?php

namespace Database\Factories;

use App\Models\Rank;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rank>
 */
class RankFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'priority' => fake()->numberBetween(1, 100),
            'name' => $name.'/-in',
            'name_male' => $name,
            'name_female' => $name.'in',
            'is_archive' => false,
        ];
    }

    public function archive(): static
    {
        return $this->state(['is_archive' => true]);
    }
}
