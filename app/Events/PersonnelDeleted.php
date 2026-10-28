<?php

namespace App\Events;

class PersonnelDeleted
{
    public function __construct(public readonly int $personnelId) {}
}
