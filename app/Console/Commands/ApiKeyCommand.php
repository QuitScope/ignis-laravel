<?php

namespace App\Console\Commands;

use App\Auth\ApiKey;
use App\Support\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

class ApiKeyCommand extends Command
{
    protected $signature = 'ignis:api-key {--regenerate : Replace the key (the FiveM server must be updated)}';

    protected $description = 'Show the API key for ef_bridge, creating one if none exists';

    public function handle(ApiKey $apiKey): int
    {
        try {
            if ($this->option('regenerate')) {
                $key = $apiKey->regenerate();
                AuditLogger::log(null, 'API-Schlüssel neu erzeugt', null, 'System', true);
                $this->warn('New key generated. The old key is invalid now: update ef_bridge on the FiveM server.');
            } else {
                $key = $apiKey->current();
            }
        } catch (QueryException) {
            $this->error('Die Datenbank ist noch nicht eingerichtet. Zuerst installieren.');

            return self::FAILURE;
        }

        $this->line($key);
        $this->info('Enter it once in ef_bridge (server console): efbridge key ignis '.$key);

        return self::SUCCESS;
    }
}
