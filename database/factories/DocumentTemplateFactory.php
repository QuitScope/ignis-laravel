<?php

namespace Database\Factories;

use App\Models\DocumentTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentTemplate>
 */
class DocumentTemplateFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'category' => null,
            'content' => ['type' => 'doc', 'content' => [['type' => 'docSection', 'attrs' => ['mode' => 'free', 'sectionId' => 's1'], 'content' => [['type' => 'paragraph']]]]],
            'is_active' => true,
        ];
    }
}
