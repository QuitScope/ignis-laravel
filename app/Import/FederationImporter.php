<?php

namespace App\Import;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Federation: FEDERATION_* config and intra_federation_links => module Federation.
 * legacy_id_map entity `federation` (links).
 *
 * - FEDERATION_ENABLED => modules.federation.enabled, FEDERATION_INSTANCE_ID => federation.instance_id UNCHANGED (peers
 *   know us by it), FEDERATION_INSTANCE_NAME => federation.instance_name; "the first one wins" like every setting.
 * - Links with both keys ENCRYPTED (HMAC needs the plain key, hashing is impossible), flags, interval (clamped 5-1440) and
 *   is_active as they were; confirmed_at null, cursors empty, status pending. When both sides run the new system the
 *   link works without a new pairing (same keys); a legacy peer shows `peer_v1` until it migrates. last_sync_error is not
 *   taken over (it may hold response text). A new-instance link with the same instance id is kept (warning).
 * - Caches and the sync log are counted only: they are fetched again on the first sync.
 * - FireTab `fed:` leaders: nothing to add. Legacy intra_fire_incidents.leader_id is an INTEGER foreign key on
 *   intra_mitarbeiter, so a federation leader was never stored there (the legacy save failed or kept a local id).
 * Reports never carry keys: counts and instance names only.
 */
final class FederationImporter implements Importer
{
    private const ENTITY = 'federation';

    private const CACHES = ['intra_federation_cache_personnel', 'intra_federation_cache_enotf', 'intra_federation_cache_fire', 'intra_federation_sync_log'];

    private const FLAGS = ['consume_personnel', 'consume_enotf', 'consume_fire', 'provide_personnel', 'provide_enotf', 'provide_fire'];

    public function entity(): string
    {
        return self::ENTITY;
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function requiredTables(): array
    {
        return [];
    }

    public function import(ImportContext $ctx): void
    {
        $report = $ctx->report();
        $this->config($ctx);

        if (! $ctx->legacy()->tableExists('intra_federation_links')) {
            $report->note(self::ENTITY.': legacy table intra_federation_links missing, links skipped.');

            return;
        }

        $names = $kept = $notHttps = [];
        foreach ($ctx->legacy()->table('intra_federation_links')->orderBy('id')->get() as $row) {
            $report->read(self::ENTITY);
            $legacyId = (string) $row->id;
            $instanceId = trim((string) $row->instance_id);
            $out = (string) $row->api_key_outgoing;
            $in = (string) $row->api_key_incoming;
            if ($instanceId === '' || mb_strlen($instanceId) > 36 || $out === '' || $in === '') {
                $report->failed(self::ENTITY, "legacy link {$legacyId}: instance id or key missing");

                continue;
            }
            $mapped = $ctx->ids()->get(self::ENTITY, $legacyId);
            if (DB::table('federation_links')->where('instance_id', $instanceId)->when($mapped !== null, fn ($q) => $q->where('id', '!=', $mapped))->exists()) {
                $kept[] = $legacyId;
                $report->skippedCount(self::ENTITY, 1);

                continue;
            }
            $url = rtrim(trim((string) $row->instance_url), '/');
            if (! str_starts_with(strtolower($url), 'https://')) {
                $notHttps[] = $legacyId;
            }
            $now = now()->toDateTimeString();
            $values = [
                'instance_id' => $instanceId,
                'name' => mb_substr(trim((string) $row->instance_name), 0, 100),
                'url' => mb_substr($url, 0, 255),
                'secret_outgoing' => Crypt::encryptString($out),
                'secret_incoming' => Crypt::encryptString($in),
                ...array_map(fn (string $f) => (bool) $row->{$f}, array_combine(self::FLAGS, self::FLAGS)),
                'sync_interval_minutes' => max(5, min(1440, (int) $row->sync_interval_minutes)),
                'is_active' => (bool) $row->is_active,
                'confirmed_at' => null, 'cursors' => null,
                'last_sync_at' => null, 'last_sync_status' => 'pending', 'last_sync_error' => null,
                'created_at' => $now, 'updated_at' => $now,
            ];
            try {
                DB::transaction(fn () => $ctx->ids()->write(self::ENTITY, $legacyId, 'federation_links', $values));
            } catch (Throwable $e) {
                $report->failed(self::ENTITY, "legacy link {$legacyId}: ".ImportReport::describe($e));

                continue;
            }
            $report->written(self::ENTITY);
            $names[] = $values['name'];
        }

        if ($names !== []) {
            $report->warn(count($names).' Vernetzungs-Verbindung(en) übernommen ('.implode(', ', $names).'): Schlüssel verschlüsselt, Status ausstehend. '
                .'Die Gegenstellen müssen ebenfalls auf das neue System (Protokoll v2) migrieren; Flags unter Einstellungen > Vernetzung prüfen.');
        }
        $report->warnRows('Vernetzung: Instanz-ID im Neusystem schon gekoppelt, Neusystem-Verbindung bleibt', $kept);
        $report->warnRows('Vernetzung: Adresse ohne https, der Abruf meldet invalid_url bis sie korrigiert ist', $notHttps);

        $counts = [];
        foreach (self::CACHES as $table) {
            if ($ctx->legacy()->tableExists($table) && ($n = $ctx->legacy()->table($table)->count()) > 0) {
                $counts[] = "{$table}: {$n}";
            }
        }
        if ($counts !== []) {
            $report->note(self::ENTITY.': Caches und Sync-Log nicht übernommen (werden beim ersten Abruf neu geladen): '.implode(', ', $counts));
        }
    }

    private function config(ImportContext $ctx): void
    {
        $report = $ctx->report();
        if (! $ctx->legacy()->tableExists('intra_config')) {
            return;
        }
        $config = $ctx->legacy()->table('intra_config')->whereIn('config_key', ['FEDERATION_ENABLED', 'FEDERATION_INSTANCE_ID', 'FEDERATION_INSTANCE_NAME'])
            ->pluck('config_value', 'config_key')->all();
        $values = [];
        if (array_key_exists('FEDERATION_ENABLED', $config)) {
            $values['modules.federation.enabled'] = LegacyConfigMap::bool($config['FEDERATION_ENABLED']) ?? false;
        }
        foreach (['FEDERATION_INSTANCE_ID' => ['federation.instance_id', 36], 'FEDERATION_INSTANCE_NAME' => ['federation.instance_name', 100]] as $key => [$setting, $max]) {
            $v = trim((string) ($config[$key] ?? ''));
            if ($v !== '') {
                $values[$setting] = mb_substr($v, 0, $max);
            }
        }
        $kept = [];
        foreach ($values as $setting => $value) {
            if ($ctx->fillSetting($setting, $value) === 'kept') {
                $kept[] = $setting;
            }
        }
        if ($kept !== []) {
            $report->note(self::ENTITY.': Neusystem-Wert bleibt; Altwert unterscheidet sich — mit --overwrite-settings übernehmen: '.ImportReport::idList($kept));
        }
    }
}
