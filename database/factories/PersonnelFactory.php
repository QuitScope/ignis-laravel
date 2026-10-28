<?php

namespace Database\Factories;

use App\Models\EmsQualification;
use App\Models\FireQualification;
use App\Models\Personnel;
use App\Models\Rank;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Personnel>
 */
class PersonnelFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'birth_date' => fake()->date(max: '-18 years'),
            'gender' => fake()->numberBetween(0, 2),
            'service_number' => fake()->unique()->numerify('BF-####'),
            'hired_at' => fake()->date(),
            'rank_id' => Rank::factory(),
            'fire_qualification_id' => fn () => FireQualification::create([
                'priority' => 0, 'short_name' => '-', 'name' => 'Keine', 'name_male' => 'Keine', 'name_female' => 'Keine', 'is_none' => true,
            ])->id,
            'ems_qualification_id' => fn () => EmsQualification::create([
                'priority' => 0, 'name' => 'Keine', 'name_male' => 'Keine', 'name_female' => 'Keine', 'is_none' => true,
            ])->id,
        ];
    }
}
