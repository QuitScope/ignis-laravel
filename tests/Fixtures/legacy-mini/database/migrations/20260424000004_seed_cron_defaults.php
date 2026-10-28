<?php

use Phinx\Migration\AbstractMigration;

final class SeedCronDefaults extends AbstractMigration
{
    public function up(): void
    {
        $jobs = [
            [
                'name'         => 'Queue-Worker',
                'handler'      => 'queue:work',
            ],
            [
                'name'         => 'Telemetrie',
                'handler'      => 'telemetry:send',
            ],
        ];
        foreach ($jobs as $job) {
            $row = ['handler'      => $job['handler']];
        }
        $token = [
            'key'         => 'CRON_ENDPOINT_TOKEN',
            'value'       => 'x',
        ];
        $this->execute("DELETE FROM intra_config WHERE config_key IN ('FIRE_ASU_CONSUMPTION', 'FIRE_ASU_START_BAR')");
        $this->execute("UPDATE intra_config SET config_value = 'true' WHERE config_key = 'UI_NEW_NAVBAR_ENABLED'");
        // $this->execute("DELETE FROM intra_config WHERE config_key = 'COMMENTED_OUT'");
    }
}
