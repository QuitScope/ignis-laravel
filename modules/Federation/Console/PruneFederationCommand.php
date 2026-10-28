<?php

namespace Modules\Federation\Console;

use App\Modules\ModuleCommand;
use App\Support\Settings;
use Modules\Federation\Models\FederatedIncident;
use Modules\Federation\Models\FederatedProtocol;
use Modules\Federation\Models\SyncRun;
use Modules\Federation\Support\Pairing;

/**
 * Daily retention: eNOTF/incident cache rows older than federation.cache_days (by the provider's
 * updated_at, else our own), sync runs older than 30 days (model:prune does not scan modules), an expired invite.
 */
class PruneFederationCommand extends ModuleCommand
{
    protected $signature = 'federation:prune';

    protected $description = 'Delete old federation caches, sync runs and an expired invite';

    public function module(): string
    {
        return 'federation';
    }

    public function handle(Settings $settings): int
    {
        $cutoff = now()->subDays(max(7, (int) $settings->get('federation.cache_days')));
        $old = fn ($q) => $q->where('remote_updated_at', '<', $cutoff)->orWhere(fn ($q) => $q->whereNull('remote_updated_at')->where('updated_at', '<', $cutoff));
        $caches = FederatedProtocol::query()->where($old)->delete() + FederatedIncident::query()->where($old)->delete();
        $runs = (new SyncRun)->prunable()->delete();

        $invite = $settings->get(Pairing::SETTING);
        if (is_array($invite) && (int) ($invite['expires'] ?? 0) < now()->getTimestamp()) {
            $settings->forget(Pairing::SETTING);
        }

        $this->info("caches={$caches} runs={$runs}");

        return self::SUCCESS;
    }
}
