<?php

namespace Database\Factories;

use App\Models\VehicleDefect;
use App\Models\VehicleDefectEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleDefectEvent>
 */
class VehicleDefectEventFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'vehicle_defect_id' => VehicleDefect::factory(),
            'action' => 'created',
        ];
    }
}
