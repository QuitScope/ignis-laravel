<?php

use Phinx\Migration\AbstractMigration;

final class InsertIntraConfigDefaults extends AbstractMigration
{
    public function up(): void
    {
        $rows = [
            ['config_key' => 'API_KEY', 'config_value' => 'x', 'config_type' => 'string'],
            ['config_key' => 'SYSTEM_COLOR', 'config_value' => '#d10000', 'config_type' => 'color'],
        ];
    }
}
