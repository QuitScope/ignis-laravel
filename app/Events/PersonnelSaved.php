<?php

namespace App\Events;

class PersonnelSaved
{
    public function __construct(public readonly int $personnelId) {}
}
