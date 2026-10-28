<?php

namespace App\Import;

use Carbon\Carbon;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Enotf\Http\Controllers\QuicklinkController;
use Modules\Enotf\Support\FieldSchema;
use Modules\Enotf\Support\MedicationSeeds;
use Modules\Enotf\Support\VitalparameterCatalog;
use Throwable;

/**
 * eNOTF: intra_edivi => enotf_protocols + enotf_protocol_crew,
 * intra_enotf_categories / _quicklinks => enotf_categories / enotf_quicklinks, intra_edivi_medikamente => enotf_medications,
 * medis => enotf_medications_given, intra_edivi_vitalparameter_einzelwerte => enotf_vitals (id map enotf_protocol,
 * enotf_category, enotf_quicklink, enotf_medication, enotf_medication_given, enotf_vital). Sessions and share requests are not imported
 * (part 3, 9c).
 *
 * Part 4 (9d): intra_edivi_qmlog => enotf_review_log (id map enotf_review_log): a status chip
 * (allowlist regex of the legacy log view) => kind status, to_status by its label, from_status = the previous status entry
 * of the protocol (first: null); anything else => kind comment, text without tags. bearbeiter => actor_user_id when exactly
 * one account has that name, the name is always the snapshot. Rows of protocols not imported are skipped (counted).
 * intra_edivi_hospital_departments + _availability => enotf_hospital_departments (status columns, id map
 * enotf_hospital_department; POI by poi map, else skipped; a department of the same name in the new instance is adopted).
 * intra_edivi_hospital_access_codes (plain text) => enotf_hospital_access_codes with Hash::make of the trimmed code (id map
 * enotf_hospital_access_code): the codes stay valid, ONE warning recommends rotation, codes shared by several hospitals are
 * counted; a code is never printed. A re-run keeps a hash that still matches (no new salt), a code rotated or set in the new
 * instance is kept. Clinic codes and preregistrations are short-lived and not imported (one warning with counts).
 *
 * Part 2 (9b):
 * - Medication catalog: the legacy catalog wins (a seed of the same substance, case-insensitive, is adopted and
 *   overwritten, incl. active); seeds the legacy catalog does not know stay as they are; never a second row per name.
 * - medis: '0' => data.medis_keine = true (explicit "no medication"); '[]'/empty => no rows, unconfirmed; every entry
 *   => one enotf_medications_given row (substance or brand against the catalog by normalized name, else free text; raw
 *   entry in `legacy`), keyed by protocol + entry timestamp (else its index). given_at = mission date + zeit as legacy
 *   wall time, else the entry timestamp (ms), else the protocol creation. Entries gone from the legacy list since the last
 *   run are removed again; a row deleted in the new instance stays deleted. Invalid JSON => `legacy` (warning).
 * - transportziel: catalog code => destination_code; `poi_<legacy id>` => poi map; a POI identifier =>
 *   pois.legacy_identifier (case-insensitive); else raw value in `legacy` (counted warning).
 * - Vital signs: enr => imported protocol by ENR; orphans (shortened ENR, protocol not imported) are skipped and
 *   reported in ONE warning with the first ENRs; parameter_name => VitalparameterCatalog code, unknown => 'other:<name>';
 *   a number => value, else value_text ('ng'/'nm' never 0; a legacy blood sugar 0 stays 0, counted); soft delete kept.
 *   Clocks: zeitpunkt DATETIME = legacy PHP wall time, erstellt_am TIMESTAMP = instant, geloescht_am TIMESTAMP written
 *   with PHP date() => its legacy database wall text read as legacy PHP wall time.
 * - Child rows of a protocol edited in the new instance (revision > 1) are not touched (counted warning).
 *
 * - ENR verbatim (the business key, `_n` variants stay separate protocols); longer than 64 characters, empty or already
 *   used by a protocol of the new instance that is not mapped => row skipped (warning).
 * - State (1.3a): hidden_user => withdrawn (withdrawn_at = last_edit), else freigegeben => released (released_at =
 *   last_edit, an approximation, note), else open; hidden => hidden_at = last_edit; protokoll_status 0-4 => qm_status
 *   (unknown => 0, warning). billing_sent => billed_at = billing_sent_at EXACTLY (missing => last_edit, warning), batch
 *   empty: without it the first ef_bridge call after the switch would bill the whole backlog again.
 * - Origin (6.4-4): createdby 2 => manual; 1 => dispatch only when sendezeit is after the createdby migration (phinxlog,
 *   else its date) or the row carries EMD traces (pat_synced <> 0), else unknown (the legacy default set every old row to 1).
 * - pat_synced 0/1/2 => none/synced/pending.
 * - Vehicles: fzg_transp/fzg_na are identifiers; exactly one vehicle with that identifier => FK + label, else label only.
 * - Crew texts "Name (Quali)" => enotf_protocol_crew (slot, position 1-3); personnel only for exactly one exact name match.
 * - Form fields by FieldSchema (key = legacy column), typed like the autosave; a value the schema rejects goes to
 *   `legacy` verbatim (warning per field). JSON columns: '0' = empty (an empty list for list fields), invalid => `legacy`.
 *   Every other non-empty column (dead
 *   columns, bearbeiter, a patname that differs from the split names, ...) goes to `legacy`; enotf:prune clears the
 *   patient keys there too.
 * - Clocks (6.3): created_at/last_edit TIMESTAMP => instants; sendezeit/billing_sent_at DATETIME written by NOW() =>
 *   legacy database clock; status times (salarm..sende, text "Y-m-d H:i" or EMD "d.m.Y H:i") => wall time of
 *   IGNIS_IMPORT_LEGACY_TIMEZONE => UTC instants (unparsable => `legacy`); edatum/ezeit, symptom and Rea times stay wall time.
 * - Categories by slug (an existing new category with the slug is adopted), quick links: orphan slug => category
 *   `schnellzugriff`, url resolved against the legacy page /enotf/ and passed through LegacyLinkRewriter, Font Awesome
 *   => Lucide icon (unknown => link), Bootstrap widths outside the list => col-6.
 * chunkById, one transaction per block, a savepoint per row; re-runnable by legacy_id_map, never deletes. A mapped protocol
 * edited in the new instance (revision > 1) is skipped (counted warning); a billed_at already set is never cleared.
 */
final class EnotfImporter implements Importer
{
    private const ENTITY = 'enotf';

    private const CHUNK = 500;

    private const MAX_ENR = 64;

    public const CREATEDBY_MIGRATION = '20250607000114';

    private const CREATEDBY_DATE = '2026-02-04 00:00:00';

    private const CREW = [
        'transport' => [1 => 'fzg_transp_perso', 2 => 'fzg_transp_perso_2', 3 => 'fzg_transp_perso_3'],
        'na' => [1 => 'fzg_na_perso', 2 => 'fzg_na_perso_2', 3 => 'fzg_na_perso_3'],
    ];

    private const META = ['id', 'enr', 'createdby', 'sendezeit', 'created_at', 'last_edit', 'patname', 'pat_synced', 'freigegeben',
        'freigeber_name', 'hidden', 'hidden_user', 'protokoll_status', 'billing_sent', 'billing_sent_at', 'medis'];

    private const ICONS = [
        'link' => 'link', 'up-right-from-square' => 'link', 'external-link' => 'link', 'external-link-alt' => 'link', 'globe' => 'link',
        'book' => 'book-open', 'book-open' => 'book-open', 'book-medical' => 'book-open',
        'file' => 'file-text', 'file-lines' => 'file-text', 'file-alt' => 'file-text', 'file-medical' => 'file-text', 'file-pdf' => 'file-text',
        'clipboard' => 'clipboard-list', 'clipboard-list' => 'clipboard-list', 'list' => 'clipboard-list', 'list-check' => 'clipboard-list',
        'ambulance' => 'ambulance', 'truck-medical' => 'ambulance', 'car' => 'ambulance', 'truck' => 'ambulance',
        'stethoscope' => 'stethoscope', 'user-doctor' => 'stethoscope', 'user-md' => 'stethoscope',
        'syringe' => 'syringe', 'pills' => 'pill', 'pill' => 'pill', 'capsules' => 'pill', 'prescription-bottle' => 'pill',
        'heart-pulse' => 'heart-pulse', 'heartbeat' => 'heart-pulse', 'heart' => 'heart-pulse',
        'wave-square' => 'activity', 'chart-line' => 'activity', 'wave-pulse' => 'activity',
        'bell' => 'siren', 'siren' => 'siren', 'siren-on' => 'siren', 'triangle-exclamation' => 'siren', 'exclamation-triangle' => 'siren',
        'phone' => 'phone', 'phone-alt' => 'phone', 'phone-volume' => 'phone',
        'map' => 'map', 'map-location-dot' => 'map', 'map-marked-alt' => 'map', 'location-dot' => 'map', 'map-marker-alt' => 'map', 'route' => 'map',
        'hospital' => 'hospital', 'house-medical' => 'hospital', 'clinic-medical' => 'hospital', 'hospital-alt' => 'hospital',
        'calculator' => 'calculator', 'info' => 'info', 'circle-info' => 'info', 'info-circle' => 'info', 'question' => 'info', 'circle-question' => 'info',
    ];

    private const ORPHAN_CATEGORY = 'schnellzugriff';

    private const GIVEN = 'enotf_medication_given';

    private const ORPHAN_VITAL = 'no imported protocol with this ENR (orphan)';

    /** @var array<string, int> lower-case POI identifier => new POI id */
    private array $poiIdentifiers = [];

    /** @var array<string, int> normalized substance or brand => catalog id */
    private array $medicationIds = [];

    private ImportContext $ctx;

    private LegacyDbClock $db;

    /** @var array<string, list<int|string>> warning => legacy ids */
    private array $warn = [];

    /** @var array<string, list<int>> vehicle identifier => new ids */
    private array $vehicleIds = [];

    /** @var array<string, list<int>> personnel full name => new ids */
    private array $personnelIds = [];

    /** @var array<int|string, int> */
    private array $pois = [];

    private Carbon $createdbyCut;

    private string $now;

    public function entity(): string
    {
        return self::ENTITY;
    }

    public function dependsOn(): array
    {
        return ['user', 'personnel', 'vehicle', 'poi'];
    }

    public function requiredTables(): array
    {
        return [];
    }

    public function import(ImportContext $ctx): void
    {
        if (! $ctx->legacy()->tableExists('intra_edivi')) {
            $ctx->report()->note(self::ENTITY.': legacy table intra_edivi missing (plugin enotf not installed), skipped.');

            return;
        }
        $this->ctx = $ctx;
        $this->warn = [];
        $this->now = now()->toDateTimeString();
        $this->db = LegacyDbClock::for($ctx->legacy(), $ctx->report());
        $this->vehicleIds = self::grouped(DB::table('vehicles')->get(['id', 'identifier']), 'identifier');
        $this->personnelIds = self::grouped(DB::table('personnel')->get(['id', 'full_name']), 'full_name');
        $this->pois = DB::table('legacy_id_map')->join('pois', 'pois.id', '=', 'legacy_id_map.new_id')->where('legacy_id_map.entity', 'poi')
            ->pluck('pois.id', 'legacy_id_map.legacy_id')->map(fn ($id) => (int) $id)->all();
        $this->createdbyCut = $this->createdbyCut();
        $this->poiIdentifiers = [];
        foreach (DB::table('pois')->whereNotNull('legacy_identifier')->get(['id', 'legacy_identifier']) as $poi) {
            $this->poiIdentifiers[mb_strtolower((string) $poi->legacy_identifier)] = (int) $poi->id;
        }

        if ($ctx->legacy()->tableExists('intra_edivi_medikamente')) {
            $this->medicationCatalog();
        }
        $this->medicationIds = [];
        $catalog = DB::table('enotf_medications')->orderByDesc('id')->get(['id', 'substance', 'brand']);
        foreach ([...$catalog->pluck('id', 'brand'), ...$catalog->pluck('id', 'substance')] as $name => $catalogId) {
            if ($name !== '') {
                $this->medicationIds[self::norm((string) $name)] = (int) $catalogId;
            }
        }

        $this->protocols();
        if ($ctx->legacy()->tableExists('intra_edivi_vitalparameter_einzelwerte')) {
            $this->vitals();
        }
        if ($ctx->legacy()->tableExists('intra_enotf_categories') && $ctx->legacy()->tableExists('intra_enotf_quicklinks')) {
            $this->categories();
            $this->quicklinks();
        }
        if ($ctx->legacy()->tableExists('intra_edivi_qmlog')) {
            $this->reviewLog();
        }
        if ($ctx->legacy()->tableExists('intra_edivi_hospital_departments')) {
            $this->departments();
        }
        if ($ctx->legacy()->tableExists('intra_edivi_hospital_access_codes')) {
            $this->accessCodes();
        }
        $this->volatile($ctx);

        foreach ($this->warn as $what => $ids) {
            $ctx->report()->warnRows($what, array_values(array_unique($ids)));
        }
    }

    /**
     * Part 3 (9c): short-lived device state is never imported, only counted (one warning each, nothing written):
     * crews and their browser tokens (intra_enotf_sessions/_members; the crews log in again, with a PIN of their own) and share
     * requests (intra_edivi_share_requests; open ones are lost, finished history is dropped).
     */
    private function volatile(ImportContext $ctx): void
    {
        $legacy = $ctx->legacy();
        if ($legacy->tableExists('intra_enotf_sessions')) {
            $active = $legacy->table('intra_enotf_sessions')->where('active', 1)->count();
            $total = $legacy->table('intra_enotf_sessions')->count();
            $members = $legacy->tableExists('intra_enotf_session_members') ? $legacy->table('intra_enotf_session_members')->count() : 0;
            if ($total > 0) {
                $ctx->report()->warn("enotf: {$total} legacy vehicle session(s) ({$active} active, {$members} member token(s)) not imported; crews log in again and set a session PIN");
            }
        }
        if ($legacy->tableExists('intra_edivi_share_requests')) {
            $total = $legacy->table('intra_edivi_share_requests')->count();
            $open = $legacy->table('intra_edivi_share_requests')->where('status', 'pending')->count();
            if ($total > 0) {
                $ctx->report()->warn("enotf: {$open} open legacy share request(s) not imported (they must be sent again), {$total} in total, finished history dropped");
            }
        }
        // 9d: clinic codes (1 h) and preregistrations are short-lived; valid/active ones are lost (counted, compared in the legacy clock)
        $codes = $legacy->tableExists('intra_edivi_klinikcodes') ? $legacy->table('intra_edivi_klinikcodes')->count() : 0;
        $valid = $codes > 0 ? $legacy->table('intra_edivi_klinikcodes')->whereRaw('expires_at > NOW()')->count() : 0;
        $preregs = $legacy->tableExists('intra_edivi_prereg') ? $legacy->table('intra_edivi_prereg')->count() : 0;
        $active = $preregs > 0 ? $legacy->table('intra_edivi_prereg')->where('active', 1)->count() : 0;
        if ($codes + $preregs > 0) {
            $ctx->report()->warn("enotf: {$codes} legacy clinic code(s) ({$valid} still valid) and {$preregs} preregistration(s) ({$active} active) not imported (short-lived; crews create new ones)");
        }
    }

    private const QM_LABELS = ['Ungesehen' => 0, 'in Prüfung' => 1, 'Freigegeben' => 2, 'Ungenügend' => 3, 'Ausgeblendet' => 4];

    private const QM_CHIP = '~^<span class="(?:ignis-chip(?: ignis-chip--\w+)?|badge(?: (?:text-)?bg-\w+)?)"(?: style="line-height: var\(--bs-body-line-height\); border-radius: 0;")?>([\p{L} ]+)</span>$~uD';

    private const AVAILABILITY = ['not_staffed', 'available', 'partially_available', 'full'];

    private function reviewLog(): void
    {
        $users = self::grouped(DB::table('users')->whereNotNull('name')->get(['id', 'name']), 'name');
        $last = [];
        $query = $this->ctx->legacy()->tableWithInstants('intra_edivi_qmlog', ['timestamp']);
        $this->each('enotf_review_log', 'enotf_review_log', $query, function (object $row, int $id, array &$problems) use ($users, &$last) {
            $legacyProtocol = (int) $row->protokoll_id;
            $protocol = $this->ctx->ids()->get('enotf_protocol', $legacyProtocol);
            if ($protocol === null) {
                return 'protocol not imported';
            }
            $raw = trim((string) $row->kommentar);
            $to = preg_match(self::QM_CHIP, $raw, $m) === 1 ? (self::QM_LABELS[trim($m[1])] ?? null) : null;
            $from = $last[$legacyProtocol] ?? null;
            if ($to !== null) {
                $last[$legacyProtocol] = $to;
            } elseif ((int) $row->log_aktion === 1) {
                $problems[] = 'status entry without a known status chip, kept as comment';
            }
            // log_aktion 0 comments were escaped plain text in the legacy view: keep them verbatim; only status rows carry markup
            $text = $to !== null ? '' : ((int) $row->log_aktion === 0 ? $raw : trim((string) preg_replace('~</?[a-zA-Z][^>]*>~', '', $raw)));
            $name = self::text($row->bearbeiter);
            $ids = $name === null ? [] : ($users[$name] ?? []);
            if ($name !== null && count($ids) !== 1) {
                $problems[] = 'reviewer name without exactly one matching account, kept as name only';
            }

            return [
                'enotf_protocol_id' => $protocol,
                'kind' => $to === null ? 'comment' : 'status',
                'from_status' => $to === null ? null : $from,
                'to_status' => $to,
                'text' => $text === '' ? null : $text,
                'actor_user_id' => count($ids) === 1 ? $ids[0] : null,
                'actor_name' => $name ?? '?',
                'created_at' => LegacyClock::instant($row, 'timestamp') ?? $this->now,
            ];
        });
    }

    private function departments(): void
    {
        $legacy = $this->ctx->legacy();
        $status = $legacy->tableExists('intra_edivi_hospital_availability')
            ? $legacy->tableWithInstants('intra_edivi_hospital_availability', ['updated_at'])->get()->keyBy('department_id') : collect();
        $query = $legacy->tableWithInstants('intra_edivi_hospital_departments', ['created_at']);
        $this->each('enotf_hospital_department', 'enotf_hospital_departments', $query, function (object $row, int $id, array &$problems) use ($status) {
            $poi = $this->pois[(string) $row->poi_id] ?? null;
            if ($poi === null) {
                return 'hospital POI not imported';
            }
            $name = mb_substr(trim((string) $row->name), 0, 100);
            if ($name === '') {
                return 'empty department name';
            }
            $own = DB::table('enotf_hospital_departments')->where('poi_id', $poi)->where('name', $name)->value('id');
            if ($own !== null && (int) $own !== $this->ctx->ids()->get('enotf_hospital_department', $id)) {
                if (DB::table('legacy_id_map')->where('entity', 'enotf_hospital_department')->where('new_id', $own)->exists()) {
                    return 'department name used twice in the same hospital';
                }
                $this->ctx->ids()->put('enotf_hospital_department', $id, (int) $own);
            }
            $a = $status[$id] ?? null;
            $state = $a === null ? 'not_staffed' : (string) $a->status;
            if (! in_array($state, self::AVAILABILITY, true)) {
                $problems[] = 'unknown availability status, set to not_staffed';
                $state = 'not_staffed';
            }
            $by = $a === null ? null : self::text($a->updated_by);

            return [
                'poi_id' => $poi,
                'name' => $name,
                'sort_order' => max(0, min(65535, (int) $row->sort_order)),
                'status' => $state,
                'status_updated_at' => $a === null ? null : LegacyClock::instant($a, 'updated_at'),
                'status_updated_by' => $by === null ? null : mb_substr($by, 0, 100),
                'created_at' => LegacyClock::instant($row, 'created_at') ?? $this->now,
            ];
        });
        if ($status->isNotEmpty()) {
            $this->ctx->report()->note(self::ENTITY.': hospital availability times taken as instants; the legacy system wrote them with the database or the PHP clock (not distinguishable).');
        }
    }

    private function accessCodes(): void
    {
        $query = $this->ctx->legacy()->tableWithInstants('intra_edivi_hospital_access_codes', ['created_at', 'updated_at']);
        $codes = (clone $query)->pluck('code', 'id')->map(fn ($c) => trim((string) $c))->filter(fn ($c) => $c !== '');
        $uses = $codes->countBy();
        $shared = $codes->filter(fn ($c) => $uses[$c] > 1)->keys()->all();
        $this->each('enotf_hospital_access_code', 'enotf_hospital_access_codes', $query, function (object $row, int $id) {
            $poi = $this->pois[(string) $row->poi_id] ?? null;
            if ($poi === null) {
                return 'hospital POI not imported';
            }
            $code = trim((string) $row->code);
            if ($code === '') {
                return 'empty access code';
            }
            $own = DB::table('enotf_hospital_access_codes')->where('poi_id', $poi)->first(['id', 'code_hash', 'rotated_at']);
            if ($own !== null && (int) $own->id !== $this->ctx->ids()->get('enotf_hospital_access_code', $id)) {
                return 'hospital has an access code set in the new instance, kept';
            }
            if ($own !== null && ! Hash::check($code, (string) $own->code_hash)) {
                return 'access code rotated in the new instance since the import, kept';
            }

            return [
                'poi_id' => $poi,
                // a hash that still matches stays (a new salt changes the portal fingerprint and signs out every clinic device)
                'code_hash' => $own !== null ? $own->code_hash : Hash::make($code),
                'rotated_at' => $own !== null ? $own->rotated_at : (LegacyClock::instant($row, 'updated_at') ?? LegacyClock::instant($row, 'created_at') ?? $this->now),
            ];
        });
        if ($codes->isNotEmpty()) {
            $this->ctx->report()->warn(self::ENTITY.": {$codes->count()} legacy hospital access code(s) stored only as hash; they stay valid, rotation recommended (settings, POI, access code)"
                .($shared === [] ? '' : '; '.count($shared).' of them shared by more than one hospital (legacy ids: '.implode(', ', $shared).')'));
        }
    }

    private function protocols(): void
    {
        $origins = ['unknown' => 0, 'released' => 0];
        $query = $this->ctx->legacy()->tableWithInstants('intra_edivi', ['created_at', 'last_edit']);
        $this->each('enotf_protocol', 'enotf_protocols', $query, function (object $row, int $id, array &$problems, array &$after) use (&$origins) {
            $columns = $this->protocol($row, $id, $problems, $after);
            if (is_array($columns)) {
                $origins['unknown'] += $columns['origin'] === 'unknown' ? 1 : 0;
                $origins['released'] += $columns['status'] === 'released' ? 1 : 0;
            }

            return $columns;
        });
        if ($origins['unknown'] > 0) {
            $this->ctx->report()->note(self::ENTITY.": {$origins['unknown']} protocol(s) with origin unknown (legacy createdby = 1 before migration "
                .self::CREATEDBY_MIGRATION.' without EMD traces); the crew may withdraw them, only dispatch protocols are locked.');
        }
        if ($origins['released'] > 0) {
            $this->ctx->report()->note(self::ENTITY.": released_at of {$origins['released']} released protocol(s) is the legacy last_edit (the legacy system kept no release time).");
        }
    }

    /**
     * @param  list<string>  $problems
     * @param  list<Closure(int): void>  $after  run with the new id after the protocol row is written
     * @return array<string, mixed>|string columns, or why the row is skipped
     */
    private function protocol(object $row, int $id, array &$problems, array &$after): array|string
    {
        $enr = (string) $row->enr;
        if (trim($enr) === '') {
            return 'empty ENR';
        }
        if (mb_strlen($enr) > self::MAX_ENR) {
            return 'ENR longer than '.self::MAX_ENR.' characters';
        }
        $mapped = $this->ctx->ids()->get('enotf_protocol', $id);
        $taken = DB::table('enotf_protocols')->where('enr', $enr)->value('id');
        if ($taken !== null && (int) $taken !== $mapped) {
            return 'ENR already used by another protocol of the new instance';
        }
        $existing = $mapped === null ? null : DB::table('enotf_protocols')->where('id', $mapped)->first(['revision', 'billed_at', 'qm_status', 'hidden_at', 'billing_batch']);
        if ($existing !== null && (int) $existing->revision > 1) {
            return 'edited in the new instance since the import (revision > 1), kept unchanged';
        }

        $legacy = [];
        $columns = [];
        $data = [];
        $header = FieldSchema::headerKeys();
        foreach (FieldSchema::keys() as $key) {
            $value = $key === 'transportziel'
                ? $this->destination($row->transportziel, $columns, $legacy, $problems)
                : $this->field($key, $row->{$key} ?? null, isset($header[$key]), $legacy, $problems);
            if (isset($header[$key])) {
                $columns[$header[$key]] = $value;
            } elseif ($value !== null) {
                $data[$key] = $value;
            }
        }
        $medis = $this->medis($row->medis, $data, $legacy, $problems);
        $this->patientName($row, $columns, $legacy);

        $created = LegacyClock::instant($row, 'created_at') ?? $this->db->toApp($row->sendezeit) ?? $this->now;
        $missionDate = $columns['mission_date'];
        $after[] = function (int $protocolId) use ($id, $medis, $missionDate, $created, &$problems) {
            $this->givenRows($protocolId, $id, $medis, $missionDate, $created, $problems);
        };
        $edited = LegacyClock::instant($row, 'last_edit') ?? $created;

        $status = 'open';
        if ((int) $row->hidden_user === 1) {
            $status = 'withdrawn';
            if ((int) $row->freigegeben !== 1) {
                $problems[] = 'deleted by the crew without release (not producible by the legacy code), imported as withdrawn';
            }
        } elseif ((int) $row->freigegeben === 1) {
            $status = 'released';
        }
        $qm = $row->protokoll_status === null ? 0 : (ctype_digit((string) $row->protokoll_status) ? (int) $row->protokoll_status : -1);
        if ($qm < 0 || $qm > 4) {
            $problems[] = 'unknown protokoll_status, QM status set to 0 (new)';
            $legacy['protokoll_status'] = $row->protokoll_status;
            $qm = 0;
        }
        $billed = null;
        if ((int) $row->billing_sent === 1) {
            $billed = $this->db->toApp($row->billing_sent_at);
            if ($billed === null) {
                $problems[] = 'billed without billing_sent_at, billed_at set to last_edit';
                $billed = $edited;
            }
        }
        // Never un-bill: a protocol the new instance billed since the last run stays billed.
        $billed ??= $existing?->billed_at;
        $sync = match ((string) $row->pat_synced) {
            '', '0' => 'none',
            '1' => 'synced',
            '2' => 'pending',
            default => null,
        };
        if ($sync === null) {
            $problems[] = 'unknown pat_synced, patient sync set to none';
            $legacy['pat_synced'] = $row->pat_synced;
        }
        $origin = match ((string) $row->createdby) {
            '2' => 'manual',
            '1' => $this->dispatched($row) ? 'dispatch' : 'unknown',
            default => null,
        };
        if ($origin === null) {
            $problems[] = 'unknown createdby, origin unknown';
            $legacy['createdby'] = $row->createdby;
        }
        foreach (['transport' => 'vehicle_transport', 'na' => 'vehicle_na'] as $slot => $prefix) {
            $label = $columns[$prefix.'_label'];
            $ids = $label === null ? [] : ($this->vehicleIds[$label] ?? []);
            $columns[$prefix.'_id'] = count($ids) === 1 ? $ids[0] : null;
            if ($label !== null && count($ids) !== 1) {
                $problems[] = "{$slot} vehicle identifier ".(count($ids) === 0 ? 'unknown' : 'not unique').', kept as label only';
            }
        }

        $crew = $this->crew($row, $problems);
        $after[] = function (int $protocolId) use ($crew) {
            foreach ($crew as $member) {
                DB::table('enotf_protocol_crew')->updateOrInsert(
                    ['enotf_protocol_id' => $protocolId, 'slot' => $member['slot'], 'position' => $member['position']],
                    ['personnel_id' => $member['personnel_id'], 'name' => $member['name'], 'qualification' => $member['qualification']],
                );
            }
        };

        $handled = array_flip([...FieldSchema::keys(), ...self::META, ...array_merge(...array_values(array_map('array_values', self::CREW)))]);
        foreach ((array) $row as $column => $value) {
            if (! isset($handled[$column]) && ! str_ends_with((string) $column, '__unix') && $value !== null && trim((string) $value) !== '') {
                $legacy[$column] = $value;
            }
        }
        ksort($legacy);

        return $columns + [
            'enr' => $enr,
            'status' => $status,
            'origin' => $origin ?? 'unknown',
            // QM review and billing do not bump revision: a re-run keeps what the new instance did
            'qm_status' => $existing !== null ? (int) $existing->qm_status : $qm,
            'hidden_at' => $existing !== null ? $existing->hidden_at : ((int) $row->hidden === 1 ? $edited : null),
            'released_at' => $status === 'released' ? $edited : null,
            'released_by_name' => self::text($row->freigeber_name),
            'withdrawn_at' => $status === 'withdrawn' ? $edited : null,
            'billed_at' => $billed,
            'billing_batch' => $existing !== null ? $existing->billing_batch : ($billed !== null ? 'legacy' : null),
            'data' => $data === [] ? null : json_encode($data),
            'legacy' => $legacy === [] ? null : json_encode($legacy),
            'patient_sync' => $sync ?? 'none',
            'created_at' => $created,
            'updated_at' => $edited,
        ];
    }

    /**
     * One form field, typed like the autosave (FieldSchema); a rejected value lands in `legacy` verbatim.
     *
     * @param  array<string, mixed>  $legacy
     * @param  list<string>  $problems
     */
    private function field(string $key, mixed $raw, bool $column, array &$legacy, array &$problems): mixed
    {
        $f = (array) FieldSchema::field($key);
        $text = trim((string) $raw);
        if ($raw === null || $text === '' || str_starts_with($text, '0000-00-00')) {
            return null;
        }
        if ($f['type'] === 'datetime') {
            $at = self::wallTime($text);
            if ($at === null) {
                $legacy[$key] = $raw;
                $problems[] = "unusable time in {$key}, kept in legacy";

                return null;
            }

            return $column ? $at->format('Y-m-d H:i:s') : $at->format('Y-m-d\TH:i:s\Z');
        }
        if ($f['type'] === 'json') {
            if ($text === '0') {
                return $f['shape'] === 'object' ? null : [];
            }
            $raw = json_decode($text, true);
            if (! is_array($raw)) {
                $legacy[$key] = $text;
                $problems[] = "invalid JSON in {$key}, kept in legacy";

                return null;
            }
            if ($raw === [] || ($f['shape'] === 'object' && array_filter($raw, fn ($v) => trim((string) (is_scalar($v) ? $v : '')) !== '') === [])) {
                return $f['shape'] === 'object' ? null : [];
            }
        }
        $checked = FieldSchema::validate([$key => $raw]);
        if (isset($checked['errors'][$key])) {
            $legacy[$key] = is_array($raw) ? $text : $raw;
            $problems[] = "value of {$key} outside the form's type or catalog, kept in legacy";

            return null;
        }

        return $checked['values'][$key];
    }

    /**
     * transportziel: a catalog code => destination_code; `poi_<legacy id>` => destination_poi_id (poi map); a POI
     * identifier => destination_poi_id (pois.legacy_identifier); anything else => `legacy`.
     *
     * @param  array<string, mixed>  $columns
     * @param  array<string, mixed>  $legacy
     * @param  list<string>  $problems
     */
    private function destination(mixed $raw, array &$columns, array &$legacy, array &$problems): int|string|null
    {
        $text = trim((string) $raw);
        $columns['destination_poi_id'] = null;
        if ($text === '') {
            return null;
        }
        $code = FieldSchema::validate(['transportziel' => $text])['values']['transportziel'] ?? null;
        if ($code !== null) {
            return $code;
        }
        $poi = preg_match('/^poi_(\d{1,18})$/D', $text, $m) === 1 ? ($this->pois[$m[1]] ?? null) : ($this->poiIdentifiers[mb_strtolower($text)] ?? null);
        if ($poi !== null) {
            $columns['destination_poi_id'] = $poi;

            return null;
        }
        $legacy['transportziel'] = $raw;
        $problems[] = 'transport destination is no catalog code, no imported POI and no POI identifier, kept in legacy';

        return null;
    }

    /**
     * medis: '0' = explicit "no medication" (data.medis_keine); a JSON list => its entries (rows after the protocol is
     * written); '[]' or empty => nothing (unconfirmed); invalid => `legacy`.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $legacy
     * @param  list<string>  $problems
     * @return list<mixed>
     */
    private function medis(mixed $raw, array &$data, array &$legacy, array &$problems): array
    {
        $text = trim((string) $raw);
        if ($text === '') {
            return [];
        }
        if ($text === '0') {
            $data['medis_keine'] = true;

            return [];
        }
        $list = json_decode($text, true);
        if (! is_array($list) || ! array_is_list($list)) {
            $legacy['medis'] = $text;
            $problems[] = 'invalid JSON in medis, kept in legacy';

            return [];
        }

        return $list;
    }

    /**
     * One enotf_medications_given row per legacy medis entry (see the class doc).
     *
     * @param  list<mixed>  $entries
     * @param  list<string>  $problems
     */
    private function givenRows(int $protocolId, int $legacyId, array $entries, ?string $missionDate, string $created, array &$problems): void
    {
        $keys = [];
        foreach ($entries as $i => $entry) {
            $e = is_array($entry) ? $entry : [];
            $stamp = $e['timestamp'] ?? null;
            $stamp = is_numeric($stamp) && (float) $stamp > 0 ? (string) (int) $stamp : null;
            $key = $legacyId.':'.($stamp !== null && ! isset($keys[$legacyId.':'.$stamp]) ? $stamp : '#'.$i);
            $keys[$key] = true;
            $this->ctx->report()->read(self::GIVEN);
            $mapped = $this->ctx->ids()->get(self::GIVEN, $key);
            if ($mapped !== null && DB::table('enotf_medications_given')->where('id', $mapped)->whereNotNull('deleted_at')->exists()) {
                $problems[] = 'given medication deleted in the new instance, kept deleted';

                continue;
            }
            $name = self::text(is_scalar($e['wirkstoff'] ?? null) ? $e['wirkstoff'] : null);
            $catalog = $name === null ? null : ($this->medicationIds[self::norm($name)] ?? null);
            if ($name === null) {
                $problems[] = 'medication entry without substance, imported as "?"';
            } elseif ($catalog === null) {
                $problems[] = 'medication substance not in the catalog, kept as free text';
            }
            $zeit = trim(is_scalar($e['zeit'] ?? null) ? (string) $e['zeit'] : '');
            $stampAt = $stamp === null ? null : Carbon::createFromTimestampMs((int) $stamp, (string) config('app.timezone'))->format('Y-m-d H:i:s');
            $at = $missionDate !== null && preg_match('/^([01]?\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/D', $zeit) === 1
                ? LegacyClock::toApp("{$missionDate} {$zeit}") : null;
            $at ??= $stampAt;
            if ($at === null) {
                $problems[] = 'medication without usable time, given_at set to the protocol creation';
            }
            $field = fn (string $k, int $max) => is_scalar($e[$k] ?? null) && trim((string) $e[$k]) !== '' ? mb_substr(trim((string) $e[$k]), 0, $max) : null;
            $this->ctx->ids()->write(self::GIVEN, $key, 'enotf_medications_given', [
                'enotf_protocol_id' => $protocolId, 'medication_id' => $catalog, 'name' => $name ?? '?',
                'dose' => $field('dosierung', 50), 'unit' => $field('einheit', 10), 'route' => $field('applikation', 20),
                'given_at' => $at ?? $created, 'created_by_user_id' => null, 'personnel_id' => null,
                'deleted_at' => null, 'deleted_by_user_id' => null, 'legacy' => json_encode($entry, JSON_UNESCAPED_UNICODE),
                'created_at' => $stampAt ?? $created, 'updated_at' => $stampAt ?? $created,
            ]);
            $this->ctx->report()->written(self::GIVEN);
        }
        $stale = DB::table('legacy_id_map')->where('entity', self::GIVEN)->where('legacy_id', 'like', $legacyId.':%')
            ->whereNotIn('legacy_id', array_keys($keys))->pluck('new_id', 'legacy_id');
        DB::table('enotf_medications_given')->whereIn('id', $stale->values())->whereNull('deleted_at')->delete();
        DB::table('legacy_id_map')->where('entity', self::GIVEN)->whereIn('legacy_id', $stale->keys()->map(fn ($k) => (string) $k))->delete();
    }

    private function medicationCatalog(): void
    {
        $query = $this->ctx->legacy()->tableWithInstants('intra_edivi_medikamente', ['created_at']);
        $this->each('enotf_medication', 'enotf_medications', $query, function (object $row, int $id) {
            $substance = mb_substr(trim((string) $row->wirkstoff), 0, 255);
            if ($substance === '') {
                return 'empty substance';
            }
            $own = DB::table('enotf_medications')->whereRaw('LOWER(substance) = ?', [mb_strtolower($substance)])->value('id');
            if ($own !== null && (int) $own !== $this->ctx->ids()->get('enotf_medication', $id)) {
                $this->ctx->ids()->put('enotf_medication', $id, (int) $own);
            }
            $created = LegacyClock::instant($row, 'created_at') ?? $this->now;

            return [
                'substance' => $substance,
                'brand' => self::text($row->herstellername),
                'doses' => json_encode(MedicationSeeds::doses($row->dosierungen), JSON_UNESCAPED_UNICODE),
                'sort' => (int) $row->priority,
                'is_active' => (bool) $row->active,
                'created_at' => $created,
                'updated_at' => $created,
            ];
        });
    }

    private function vitals(): void
    {
        $protocols = DB::table('legacy_id_map')->join('enotf_protocols', 'enotf_protocols.id', '=', 'legacy_id_map.new_id')
            ->where('legacy_id_map.entity', 'enotf_protocol')->get(['enotf_protocols.id', 'enotf_protocols.enr', 'enotf_protocols.revision'])->keyBy('enr');
        $orphans = [];
        $query = $this->ctx->legacy()->tableWithInstants('intra_edivi_vitalparameter_einzelwerte', ['erstellt_am', 'geloescht_am']);
        $this->each('enotf_vital', 'enotf_vitals', $query, function (object $row, int $id, array &$problems) use ($protocols, &$orphans) {
            $protocol = $protocols[(string) $row->enr] ?? null;
            if ($protocol === null) {
                $orphans[] = (string) $row->enr;

                return self::ORPHAN_VITAL;
            }
            if ((int) $protocol->revision > 1) {
                return 'protocol edited in the new instance since the import (revision > 1), kept unchanged';
            }
            $mapped = $this->ctx->ids()->get('enotf_vital', $id);
            // a row deleted in the legacy system stays imported as deleted (rewritten); deleted here only otherwise
            if ($mapped !== null && (int) $row->geloescht !== 1 && DB::table('enotf_vitals')->where('id', $mapped)->whereNotNull('deleted_at')->exists()) {
                return 'deleted in the new instance, kept deleted';
            }
            $legacy = array_filter(['erstellt_von' => self::text($row->erstellt_von), 'geloescht_von' => self::text($row->geloescht_von)]);
            $name = trim((string) $row->parameter_name);
            $code = VitalparameterCatalog::fromLegacyName($name);
            if ($code === null) {
                $problems[] = 'unknown vital parameter, kept as other:<name>';
                $code = mb_substr('other:'.$name, 0, 50);
                $legacy['parameter_name'] = $name;
            }
            $raw = trim((string) $row->parameter_wert);
            $parsed = str_starts_with($code, 'other:')
                ? (preg_match('/^\d{1,8}([.,]\d{1,2})?$/D', $raw) === 1 ? [(float) str_replace(',', '.', $raw), null] : null)
                : VitalparameterCatalog::parse($code, $raw);
            if ($parsed === null) {
                $parsed = [null, $raw === '' ? null : mb_substr($raw, 0, 50)];
                $problems[] = 'vital value is no number, kept as text';
            }
            if ($code === 'bz' && $parsed[0] === 0.0) {
                $problems[] = 'blood sugar 0 kept as 0 (legacy stored "ng"/"nm" as 0, not distinguishable)';
            }
            $created = LegacyClock::instant($row, 'erstellt_am');
            $measured = LegacyClock::toApp($row->zeitpunkt) ?? $created;
            if ($measured === null) {
                $problems[] = 'vital without usable time, measured_at set to the import time';
            }
            $deleted = null;
            if ((int) $row->geloescht === 1) {
                $unix = $row->geloescht_am__unix ?? null;
                // written with PHP date() into a TIMESTAMP: the database wall text is legacy PHP wall time
                $deleted = $unix !== null && (float) $unix > 0
                    ? LegacyClock::toApp(Carbon::createFromTimestamp((int) $unix, $this->db->zone)->format('Y-m-d H:i:s')) : null;
                $deleted ??= $created ?? $measured ?? $this->now;
            }

            return [
                'enotf_protocol_id' => (int) $protocol->id,
                'parameter' => $code,
                'value' => $parsed[0],
                'value_text' => $parsed[1],
                'unit' => self::text($row->parameter_einheit) === null ? null : mb_substr(trim((string) $row->parameter_einheit), 0, 20),
                'measured_at' => $measured ?? $this->now,
                'created_by_user_id' => null,
                'personnel_id' => null,
                'deleted_at' => $deleted,
                'deleted_by_user_id' => null,
                'legacy' => $legacy === [] ? null : json_encode($legacy, JSON_UNESCAPED_UNICODE),
                'created_at' => $created ?? $measured ?? $this->now,
                'updated_at' => $created ?? $measured ?? $this->now,
            ];
        });
        $key = 'enotf_vital(s) skipped: '.self::ORPHAN_VITAL;
        if (isset($this->warn[$key])) {
            $ids = $this->warn[$key];
            unset($this->warn[$key]);
            $first = array_slice(array_values(array_unique($orphans)), 0, 20);
            $this->warn['enotf_vital(s) skipped: '.count($ids).' orphan(s) without an imported protocol of this ENR (shortened ENR, protocol not '
                .'imported or deleted), not imported; first ENRs: '.implode(', ', $first)] = $ids;
        }
    }

    private static function norm(string $name): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($name)));
    }

    /**
     * Split names win; only patname => the comma rule of legacy migration 20250607000120. A patname that differs from the
     * split names is kept in `legacy` (enotf:prune clears it with the header columns).
     *
     * @param  array<string, mixed>  $columns
     * @param  array<string, mixed>  $legacy
     */
    private function patientName(object $row, array &$columns, array &$legacy): void
    {
        $patname = trim((string) $row->patname);
        if ($patname === '') {
            return;
        }
        if ($columns['patient_first_name'] === null && $columns['patient_last_name'] === null) {
            [$last, $first] = array_map('trim', explode(',', $patname, 2)) + [1 => ''];
            $columns['patient_last_name'] = mb_substr($last, 0, 255) ?: null;
            $columns['patient_first_name'] = mb_substr($first, 0, 255) ?: null;
        }
        $derived = trim((string) $columns['patient_last_name'].($columns['patient_first_name'] !== null ? ', '.$columns['patient_first_name'] : ''));
        if ($patname !== $derived) {
            $legacy['patname'] = $row->patname;
        }
    }

    /**
     * @param  list<string>  $problems
     * @return list<array{slot: string, position: int, name: string, qualification: ?string, personnel_id: ?int}>
     */
    private function crew(object $row, array &$problems): array
    {
        $out = [];
        foreach (self::CREW as $slot => $positions) {
            foreach ($positions as $position => $column) {
                $text = trim((string) ($row->{$column} ?? ''));
                if ($text === '') {
                    continue;
                }
                $quali = null;
                if (preg_match('/^(.+?)\s*\(([^()]*)\)$/Du', $text, $m) === 1) {
                    [$text, $quali] = [trim($m[1]), self::text($m[2])];
                }
                $ids = $this->personnelIds[$text] ?? [];
                if (count($ids) !== 1) {
                    $problems[] = 'crew name without exactly one matching personnel record, kept as text';
                }
                $out[] = ['slot' => $slot, 'position' => $position, 'name' => mb_substr($text, 0, 255),
                    'qualification' => $quali === null ? null : mb_substr($quali, 0, 255), 'personnel_id' => count($ids) === 1 ? $ids[0] : null];
            }
        }

        return $out;
    }

    /** createdby = 1 means dispatch only after the createdby migration or with EMD traces (6.4-4). */
    private function dispatched(object $row): bool
    {
        if ((string) $row->pat_synced !== '' && (string) $row->pat_synced !== '0') {
            return true;
        }
        $sent = $this->db->toApp($row->sendezeit);

        return $sent !== null && Carbon::parse($sent, (string) config('app.timezone'))->greaterThan($this->createdbyCut);
    }

    private function createdbyCut(): Carbon
    {
        if ($this->ctx->legacy()->tableExists('phinxlog')) {
            $at = $this->ctx->legacy()->table('phinxlog')->where('version', self::CREATEDBY_MIGRATION)->selectRaw('UNIX_TIMESTAMP(start_time) AS u')->value('u');
            if ($at !== null && (float) $at > 0) {
                return Carbon::createFromTimestamp((int) $at);
            }
        }

        return Carbon::parse(self::CREATEDBY_DATE, LegacyClock::legacyZone());
    }

    private function categories(): void
    {
        $query = $this->ctx->legacy()->tableWithInstants('intra_enotf_categories', ['created_at', 'updated_at']);
        $this->each('enotf_category', 'enotf_categories', $query, function (object $row, int $id, array &$problems) {
            $slug = mb_substr(trim((string) $row->slug), 0, 100);
            if ($slug === '') {
                return 'empty slug';
            }
            $own = DB::table('enotf_categories')->where('slug', $slug)->value('id');
            $mapped = $this->ctx->ids()->get('enotf_category', $id);
            if ($own !== null && (int) $own !== $mapped) {
                $this->ctx->ids()->put('enotf_category', $id, (int) $own);
            }
            $created = LegacyClock::instant($row, 'created_at') ?? $this->now;

            return [
                'name' => mb_substr(trim((string) $row->name), 0, 100) ?: $slug,
                'slug' => $slug,
                'sort_order' => (int) $row->sort_order,
                'active' => (bool) $row->active,
                'created_at' => $created,
                'updated_at' => LegacyClock::instant($row, 'updated_at') ?? $created,
            ];
        });
    }

    private function quicklinks(): void
    {
        $rewriter = LegacyLinkRewriter::fromIds($this->ctx->ids());
        $query = $this->ctx->legacy()->tableWithInstants('intra_enotf_quicklinks', ['created_at', 'updated_at']);
        $this->each('enotf_quicklink', 'enotf_quicklinks', $query, function (object $row, int $id, array &$problems) use ($rewriter) {
            $category = DB::table('enotf_categories')->where('slug', trim((string) $row->category_slug))->value('id');
            if ($category === null) {
                $problems[] = 'category slug unknown, moved to category '.self::ORPHAN_CATEGORY;
                $category = $this->orphanCategory();
            }
            [$url, $outcome] = $rewriter->tileTarget(self::absolute((string) $row->url));
            $active = (bool) $row->active;
            if ($url === null) {
                $problems[] = 'link target unusable, set to /enotf and deactivated';
                [$url, $active] = ['/enotf', false];
            } elseif ($outcome === 'unmapped') {
                $problems[] = 'link to a record that was not imported, leads to its list';
            }
            $fa = (string) preg_replace('/^fa-/', '', (string) collect(preg_split('/\s+/', strtolower(trim((string) $row->icon))) ?: [])
                ->first(fn (string $c) => str_starts_with($c, 'fa-') && ! in_array($c, ['fa-solid', 'fa-regular', 'fa-light', 'fa-brands', 'fa-duotone', 'fa-thin', 'fa-fw'], true), ''));
            $icon = self::ICONS[$fa] ?? null;
            if ($icon === null && $fa !== '') {
                $problems[] = 'Font Awesome icon without Lucide counterpart, set to link';
            }
            $width = (string) $row->col_width;
            if (! in_array($width, QuicklinkController::WIDTHS, true)) {
                if ($width !== 'col') {
                    $problems[] = 'unknown column width, set to col-6';
                }
                $width = 'col-6';
            }
            $created = LegacyClock::instant($row, 'created_at') ?? $this->now;

            return [
                'enotf_category_id' => (int) $category,
                'title' => mb_substr(trim((string) $row->title), 0, 255),
                'url' => mb_substr($url, 0, 500),
                'icon' => $icon ?? 'link',
                'sort_order' => (int) $row->sort_order,
                'col_width' => $width,
                'active' => $active,
                'created_at' => $created,
                'updated_at' => LegacyClock::instant($row, 'updated_at') ?? $created,
            ];
        });
    }

    private function orphanCategory(): int
    {
        $id = DB::table('enotf_categories')->where('slug', self::ORPHAN_CATEGORY)->value('id');

        return $id !== null ? (int) $id : (int) DB::table('enotf_categories')->insertGetId([
            'name' => 'Schnellzugriff', 'slug' => self::ORPHAN_CATEGORY, 'sort_order' => 0, 'active' => true, 'created_at' => $this->now, 'updated_at' => $this->now,
        ]);
    }

    /**
     * A quick link url as an absolute path: relative ones were rendered on /enotf/overview, so they resolve against /enotf/
     * (`../enotf/fahrzeuginfo` => /enotf/fahrzeuginfo, `../` => /). Absolute paths, schemes and placeholders stay.
     */
    public static function absolute(string $url): string
    {
        $url = trim($url);
        if ($url === '' || in_array($url[0], ['/', '#', '?'], true) || preg_match('~^[A-Za-z][A-Za-z0-9+.-]*:~', $url) === 1) {
            return $url;
        }
        $cut = strcspn($url, '?#');
        [$path, $rest] = [substr($url, 0, $cut), substr($url, $cut)];
        $stack = ['enotf'];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($stack);
            } elseif ($segment !== '' && $segment !== '.') {
                $stack[] = $segment;
            }
        }
        $trailing = $stack !== [] && (str_ends_with($path, '/') || str_ends_with($path, '..'));

        return '/'.implode('/', $stack).($trailing ? '/' : '').$rest;
    }

    private static function wallTime(string $text): ?Carbon
    {
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})[ T](\d{1,2}):(\d{2})(?::(\d{2}))?$/D', $text, $m) === 1) {
            $text = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $m[3], $m[2], $m[1], $m[4], $m[5], $m[6] ?? 0);
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/D', $text) === 1) {
            $text = str_replace('T', ' ', strlen($text) === 16 ? $text.':00' : $text);
        } else {
            return null;
        }
        try {
            $at = Carbon::createFromFormat('Y-m-d H:i:s', $text, LegacyClock::legacyZone());
        } catch (Throwable) {
            return null;
        }

        return $at instanceof Carbon && $at->format('Y-m-d H:i:s') === $text && $at->year >= 2000 && $at->year <= 2100 ? $at->utc() : null;
    }

    /**
     * Rows of $query in blocks (chunkById), one transaction per block and a savepoint per row: mapping and writing happen
     * inside the row's try, so a failing row is rolled back alone. $values returns the columns or why the row is skipped;
     * closures it appends to $after run with the new id. Problems count only when written.
     *
     * @param  Closure(object, int, list<string>&, list<Closure(int): void>&): (array<string, mixed>|string)  $values
     */
    private function each(string $entity, string $table, Builder $query, Closure $values): void
    {
        $report = $this->ctx->report();
        $query->chunkById(self::CHUNK, function (Collection $rows) use ($entity, $table, $values, $report) {
            DB::transaction(function () use ($rows, $entity, $table, $values, $report) {
                foreach ($rows as $row) {
                    $report->read($entity);
                    $id = (int) $row->id;
                    $problems = [];
                    try {
                        $skip = DB::transaction(function () use ($values, $row, $id, &$problems, $entity, $table) {
                            $after = [];
                            $columns = $values($row, $id, $problems, $after);
                            if (is_string($columns)) {
                                return $columns;
                            }
                            $new = $this->ctx->ids()->write($entity, $id, $table, $columns);
                            foreach ($after as $fn) {
                                $fn($new);
                            }

                            return null;
                        });
                    } catch (Throwable $e) {
                        $report->failed($entity, "legacy {$entity} {$id}: ".ImportReport::describe($e));

                        continue;
                    }
                    if ($skip !== null) {
                        $report->skippedCount($entity, 1);
                        $this->warn["{$entity}(s) skipped: {$skip}"][] = $id;

                        continue;
                    }
                    $report->written($entity);
                    foreach (array_unique($problems) as $what) {
                        $this->warn["{$entity}(s): {$what}"][] = $id;
                    }
                }
            });
        }, 'id');
    }

    /**
     * @param  Collection<int, \stdClass>  $rows
     * @return array<string, list<int>> value of $column => ids
     */
    private static function grouped(Collection $rows, string $column): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->{$column}][] = (int) $row->id;
        }

        return $out;
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 255);
    }
}
