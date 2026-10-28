<?php

namespace App\Models\Concerns;

/** Label by gender: 0 male, 1 female, anything else the neutral `name`. */
trait HasGenderedName
{
    public function labelFor(int $gender): string
    {
        return match ($gender) {
            0 => $this->name_male,
            1 => $this->name_female,
            default => $this->name,
        };
    }
}
