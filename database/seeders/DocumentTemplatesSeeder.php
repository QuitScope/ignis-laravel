<?php

namespace Database\Seeders;

use App\Documents\SystemTemplates;
use App\Models\DocumentTemplate;
use Illuminate\Database\Seeder;

/**
 * Creates the ten system templates that are missing by name (case-insensitive). Never touches an existing
 * template, so edits of the operator survive; deleting one and re-running restores only that one.
 */
class DocumentTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $existing = DocumentTemplate::pluck('name')->map(fn (string $n) => mb_strtolower($n))->all();

        foreach (SystemTemplates::all() as $name => $t) {
            if (! in_array(mb_strtolower($name), $existing, true)) {
                DocumentTemplate::create(['name' => $name, 'category' => $t['category'], 'content' => $t['content']]);
            }
        }
    }
}
