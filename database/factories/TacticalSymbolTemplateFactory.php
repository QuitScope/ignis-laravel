<?php

namespace Database\Factories;

use App\Models\TacticalSymbolTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TacticalSymbolTemplate>
 */
class TacticalSymbolTemplateFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'symbol' => ['grundzeichen' => 'kraftfahrzeug-landgebunden'],
        ];
    }
}
