<?php

namespace Database\Factories;

use App\Models\LegacyDocument;
use App\Models\Personnel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegacyDocument>
 */
class LegacyDocumentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'public_id' => fake()->unique()->numerify('#######'),
            'type' => 0,
            'personnel_id' => Personnel::factory(),
            'recipient_name' => fake()->name(),
        ];
    }
}
