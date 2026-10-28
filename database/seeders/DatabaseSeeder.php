<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RoleSeeder::class);
        $this->call(PersonnelDefaultsSeeder::class);
        $this->call(DocumentTemplatesSeeder::class);
    }
}
