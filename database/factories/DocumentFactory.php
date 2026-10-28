<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\Personnel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'public_id' => fake()->unique()->numerify('#######'),
            'template_id' => DocumentTemplate::factory(),
            'personnel_id' => Personnel::factory(),
            'title' => fake()->sentence(3),
            'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]],
            'status' => Document::DRAFT,
        ];
    }

    public function issued(): static
    {
        return $this->state(['status' => Document::ISSUED, 'issued_at' => now(), 'frozen_values' => []]);
    }
}
