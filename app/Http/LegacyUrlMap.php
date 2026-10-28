<?php

namespace App\Http;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy URL tables for NormalizeLegacyPath. The three German tables and translate() are ported
 * unchanged from the legacy src/Http/UrlMap.php; redirects() maps legacy English pages to this app.
 */
final class LegacyUrlMap
{
    private const TOP = [
        'benutzer' => 'users',
        'antrag' => 'forms',
        'antraege' => 'forms',
        'benachrichtigungen' => 'notifications',
        'fahrtenbuch' => 'logbook',
        'kalender' => 'calendar',
        'mitarbeiter' => 'personnel',
        'manv' => 'mci',
        'einsatz' => 'firetab',
        'wissensdb' => 'lexicon',
        'dokumente' => 'documents',
    ];

    /** Applied to every segment after `/settings/`. */
    private const SETTINGS = [
        'fahrzeuge' => 'vehicles',
        'dienstgrade' => 'ranks',
        'qualifw' => 'fdskills',
        'qualird' => 'ambskills',
        'qualifd' => 'specialties',
        'beladelisten' => 'vehload',
        'defekte' => 'defects',
        'medikamente' => 'medications',
        'antrag' => 'forms',
        'personal' => 'personnel',
    ];

    /** Applied to the segment after `/api/` or `/api/v1/`. */
    private const API = [
        'kalender' => 'calendar',
        'manv' => 'mci',
        'mitarbeiter' => 'personnel',
    ];

    /**
     * Legacy English page paths (after `.php` stripping and German translation) → current paths,
     * GET/HEAD only, answered with the given status (301; 308 for docredir, 302 where browsers must not cache it). Each module adds its legacy pages here.
     * Keys must not be live routes.
     */
    private const REDIRECTS = [
        '/index' => ['/', 301],
        '/settings/dashboard/index' => ['/settings/dashboard', 301],
        '/users/list' => ['/users', 301],
        '/users/roles/' => ['/users/roles', 301],
        '/users/roles/index' => ['/users/roles', 301],
        '/users/registration-codes' => ['/users/invitations', 301],
        '/settings/personnel/ranks/index' => ['/settings/personnel/ranks', 301],
        '/settings/personnel/fdskills/index' => ['/settings/personnel/fire-qualifications', 301],
        '/settings/personnel/ambskills/index' => ['/settings/personnel/ems-qualifications', 301],
        '/settings/personnel/specialties/index' => ['/settings/personnel/specialties', 301],
        '/settings/personnel/titles/index' => ['/settings/personnel/titles', 301],
        // The old template/category/visual-editor pages were folded into the one editor-templates page.
        '/settings/documents/templates' => ['/settings/documents/editor-templates', 301],
        '/settings/documents/categories' => ['/settings/documents/editor-templates', 301],
        '/settings/documents/visual-editor' => ['/settings/documents/editor-templates', 301],
        // Document links: the log's `mitarbeiter/dokument-view` (German prefix already translated) and the old redirect endpoint.
        '/personnel/dokument-view' => ['/personnel/document-view', 301],
        '/assets/functions/docredir' => ['/personnel/document-view', 308],
        '/personnel/list' => ['/personnel', 301],
        // The target depends on `?id=`, see redirectLocation().
        '/personnel/profile' => ['/personnel', 301],
        // No logout happens on GET (CSRF). 302: a cached 301 would outlive the rule.
        '/logout' => ['/login', 302],
        // System pages: the settings overview replaces index; performance and telemetry are gone (no telemetry); the updater is back (update by click, draft first).
        '/settings/index' => ['/settings', 301],
        '/settings/system/index' => ['/settings/system', 301],
        '/settings/system/performance' => ['/settings/system', 301],
        '/settings/system/updater' => ['/settings/system', 301],
        '/settings/system/telemetry' => ['/settings/system', 301],
        '/settings/system/cron' => ['/settings/system/jobs', 301],
        '/settings/system/cron/history' => ['/settings/system/jobs', 301],
        '/settings/system/logs' => ['/settings/system/jobs', 301],
        '/settings/system/plugins' => ['/settings/system/modules', 301],
        // Module Federation (404 behind the target while it is off); the old collective POST answers 405.
        '/settings/federation/index' => ['/settings/federation', 301],
        '/settings/vehicles/vehicles/index' => ['/vehicles', 301],
        '/settings/vehicles/vehicles/create' => ['/vehicles/create', 301],
        // Notification links carry `?vehicle=&status=`, kept as they are. The old handler and API dispatchers
        // (defects-handler, import-handler) are gone (404).
        '/settings/vehicles/defects/index' => ['/vehicles/defects', 301],
        '/settings/vehicles/defects/create' => ['/vehicles/defects/create', 301],
        // The AJAX handler vehload/beladung_handler is gone (404); `beladelisten` is translated to `vehload` first.
        '/settings/vehicles/vehload/index' => ['/settings/vehicles/loadouts', 301],
        // Logbook: the AJAX dispatcher POST /logbook/actions is gone (404); the list is /logbook.
        '/logbook/' => ['/logbook', 301],
        '/logbook/index' => ['/logbook', 301],
        // Knowledge base: entry ids are new (imported content links are rewritten), so old entry links open the list; the
        // query is dropped (redirectQuery). The legacy POST endpoints and /api/knowledgebase/* are gone (404/405).
        '/lexicon/' => ['/lexicon', 301],
        '/lexicon/index' => ['/lexicon', 301],
        '/lexicon/view' => ['/lexicon', 301],
        '/lexicon/edit' => ['/lexicon', 301],
        '/lexicon/manage-taxonomy' => ['/lexicon/taxonomy', 301],
        // Applications: type ids are new, the query is dropped (redirectQuery); `?antrag=X` with six digits leads to the
        // submission (public id = legacy uniqueid), see redirectLocation(). The legacy POST endpoints are gone (404/405).
        '/forms/select' => ['/forms', 301],
        '/forms/create' => ['/forms', 301],
        '/forms/view' => ['/forms', 301],
        '/forms/admin/list' => ['/forms/admin', 301],
        '/forms/admin/view' => ['/forms/admin', 301],
        '/settings/forms/list' => ['/settings/forms', 301],
        '/settings/forms/edit' => ['/settings/forms', 301],
        // Calendar: `?id=N` leads to the imported event (legacy_id_map calendar_event), see redirectLocation(). GET
        // /calendar/create is a live alias of /calendar?new=1 (same page, create dialog), so it needs no entry. The legacy
        // POST endpoints and /api/calendar/{events,event,subscribe-info,subscribe-regenerate} are gone (404/405).
        '/calendar/' => ['/calendar', 301],
        '/calendar/view' => ['/calendar', 301],
        // FireTab (`/einsatz/...` is translated to `/firetab/...` by TOP): `?id=N` / `?incident_id=N` lead to the
        // imported incident (legacy_id_map fire_incident), else to /firetab/list, see redirectLocation(); the queries are
        // dropped except on the QM list (redirectQuery). `?logout=1` on the login page does NOT log out (GET). The legacy
        // POST endpoints, /api/fire/*, /firetab/lagekarte-api and /firetab/status-api are gone (404/405).
        '/firetab/' => ['/firetab', 301],
        '/firetab/index' => ['/firetab', 301],
        '/firetab/view' => ['/firetab/list', 301],
        '/firetab/asu' => ['/firetab/list', 301],
        '/firetab/create' => ['/firetab/incidents/create', 301],
        '/firetab/login-vehicle' => ['/firetab/login', 301],
        '/firetab/status-reports' => ['/firetab/status', 301],
        '/firetab/admin/list' => ['/firetab/qm', 301],
        // MANV board (`/manv/...` is translated to `/mci/...` by TOP): the list keeps its query (legacy status values
        // translated, redirectQuery); `?id=N` / `?lage_id=N` lead to the imported incident or patient (legacy_id_map
        // manv_incident, manv_patient), else to the list, see redirectLocation(). GET /mci/create is live. The legacy POST
        // endpoints and /api/mci/api, /api/mci-api are gone (404/405).
        '/mci/' => ['/mci', 301],
        '/mci/index' => ['/mci', 301],
        '/mci/board' => ['/mci', 301],
        '/mci/edit' => ['/mci', 301],
        '/mci/log' => ['/mci', 301],
        '/mci/patient-view' => ['/mci', 301],
        '/mci/patient-create' => ['/mci', 301],
        '/mci/resources' => ['/mci', 301],
        // eNOTF print view: `?enr=X` leads to /enotf/print/X when X is a well-formed ENR (no lookup; the policy decides
        // there), otherwise to the overview; see redirectLocation(). The query is dropped.
        '/enotf/print' => ['/enotf/overview', 301],
        '/enotf/print/' => ['/enotf/overview', 301],
        '/enotf/print/index' => ['/enotf/overview', 301],
        // eNOTF crew and QM pages. `/enotf/protokoll[/…]?enr=X` and the QM fragments `?id=N` (legacy protocol id,
        // legacy_id_map enotf_protocol) lead to the protocol page resp. the QM dialog, see redirectLocation().
        '/enotf/' => ['/enotf', 301],
        '/enotf/index' => ['/enotf', 301],
        '/enotf/protokoll' => ['/enotf/overview', 301],
        '/enotf/admin/' => ['/enotf/admin', 301],
        '/enotf/admin/list' => ['/enotf/admin', 301],
        '/enotf/admin/qm-actions-modal' => ['/enotf/admin', 301],
        '/enotf/admin/qm-log-modal' => ['/enotf/admin', 301],
        '/enotf/admin/bulk-delete-empty' => ['/enotf/admin/empty', 301],
        '/settings/enotf/index' => ['/settings/enotf/quicklinks', 301],
        '/settings/enotf/kategorien/index' => ['/settings/enotf/categories', 301],
        // `/settings/medikamente/*` is translated to `/settings/medications/*` first (SETTINGS table).
        '/settings/medications/index' => ['/settings/medications', 301],
        '/settings/pois/index' => ['/settings/pois', 301],
        // eNOTF hospitals: crew view and clinic portal availability.
        '/enotf/hospital-availability' => ['/enotf/hospitals', 301],
        '/enotf/schnittstelle/hospital-availability' => ['/enotf/schnittstelle/availability', 301],
        // Arrival board (the hospital comes from the device sign-in, `?klinik=` is dropped) and its poll; the pre-registration
        // form `?enr=X` (Discord links) leads to /enotf/p/X/prereg, see enotfLocation().
        '/enotf/schnittstelle/' => ['/enotf/schnittstelle', 301],
        '/enotf/schnittstelle/index' => ['/enotf/schnittstelle', 301],
        '/enotf/schnittstelle/api-prereg' => ['/enotf/schnittstelle/prereg', 301],
        '/enotf/schnittstelle/voranmeldung' => ['/enotf/overview', 301],
    ];

    /**
     * Like REDIRECTS for paths with an id: pattern => [target with `$n` groups, status, sample path]. The sample
     * matches the pattern; tests check it is no live route, and its first segment marks the path as a candidate.
     * The legacy `/{id}/preview` is gone.
     */
    private const PATTERN_REDIRECTS = [
        '#^/settings/vehicles/vehicles/([1-9]\d{0,17})(/edit)?$#D' => ['/vehicles/$1$2', 301, '/settings/vehicles/vehicles/12/edit'],
        // `/notifications`, `/notifications/`, `/notifications/index(.php)` and, translated, `/benachrichtigungen/*`.
        '#^/notifications(/.*)?$#D' => ['/inbox', 301, '/notifications/index'],
        // eNOTF: the old sub pages land on their section, `/enotf/protokoll/{section}/…?enr=` on the protocol,
        // the QM fragments on the protocol page (redirectLocation()), the beta paths on the live ones (query kept).
        self::ENOTF_SUBPAGE => ['/enotf/p/$1', 301, '/enotf/p/123/rettdaten/seite'],
        '#^/enotf/protokoll/[\w./-]*$#D' => ['/enotf/overview', 301, '/enotf/protokoll/rettdaten/index'],
        '#^/enotf/qm/(?:actions|log)/[1-9]\d{0,17}$#D' => ['/enotf/admin', 301, '/enotf/qm/actions/12'],
        // Old target administration (consolidated into the POIs): every sub page lands on the POI settings.
        '#^/enotf/admin/zielverwaltung(/.*)?$#D' => ['/settings/pois', 301, '/enotf/admin/zielverwaltung/create'],
        '#^/enotf-v2(/.*)?$#D' => ['/enotf$1', 301, '/enotf-v2/overview'],
        // Departments and access codes moved under the POI (/settings/pois/{poi}/departments); the list is the way in.
        '#^/settings/pois/(?:departments|access-codes)(/.*)?$#D' => ['/settings/pois', 301, '/settings/pois/departments/sort'],
    ];

    /** `/enotf/p/{enr}/{anything but a live section}[/…]` (live: the seven sections, `plausibility`, `vitals`, `medications`, `changes`, `prereg`, `clinic-code`). */
    private const ENOTF_SUBPAGE = '#^/enotf/p/([\w.-]{1,64})/(?!(?:rettdaten|anamnese|erstbefund|diagnose|massnahmen|verlauf|abschluss|plausibility|vitals|medications|changes|prereg|clinic-code)$)([\w-]+(?:/[\w/-]*)?)$#D';

    /**
     * Legacy endpoints answered by another route, rewritten internally for every method (a redirect would drop
     * a POST body). `.php` is already stripped when this is consulted.
     */
    private const REWRITES = [
        '/assets/functions/checkdienstnr2' => '/api/personnel/check-dienstnr',
        '/assets/functions/checkdnr' => '/api/personnel/check-dienstnr-legacy',
        '/notifications/mark-read' => '/api/notifications/mark-read',
        '/api/dismiss-announcement' => '/api/announcements/dismiss',
        '/assets/functions/system/global-search-api' => '/api/system/global-search',
        // ef_bridge's `api/enotf/billing.php` needs no entry (`.php` is stripped); this is its old alias (route in 9d).
        '/api/enotf-billing' => '/api/enotf/billing',
        '/api/emd-sync' => '/api/emd/sync', // ef_bridge's old alias of api/emd/sync.php
    ];

    /** MANV pages of one incident: legacy path => [query key of the legacy incident id, path after `/mci/<new id>`]. */
    public const MANV_PAGES = [
        '/mci/board' => ['id', ''],
        '/mci/edit' => ['id', '/edit'],
        '/mci/log' => ['id', '/log'],
        '/mci/patient-create' => ['lage_id', '/patients/create'],
        '/mci/resources' => ['lage_id', '/resources'],
    ];

    /**
     * Mail pages with a legacy record id: pattern => [id map entity, target table, target with `$n`
     * groups and `{id}`, page when the id is unknown]. They have the same shape as the live routes, so mailRedirect()
     * only applies them to a request the legacy app made: one with `?postfach=` (the legacy mailbox choice, the new app
     * uses `?mailbox=`) or a `.php` path.
     */
    private const MAIL_PAGES = [
        '#^/mail/(inbox|sent|drafts|archive|trash)/([1-9]\d{0,17})$#D' => ['mail_message', 'mail_messages', '/mail/$1/{id}', '/mail/$1'],
        '#^/mail/compose/(reply|reply-all|forward|draft)/([1-9]\d{0,17})$#D' => ['mail_message', 'mail_messages', '/mail/compose/$1/{id}', '/mail'],
        '#^/mail/attachments/([1-9]\d{0,17})$#D' => ['mail_attachment', 'mail_attachments', '/mail/attachments/{id}', '/mail'],
        '#^/mail/lists/([1-9]\d{0,17})/edit$#D' => ['mail_list', 'mail_lists', '/mail/lists/{id}/edit', '/mail/lists'],
        '#^/settings/mail/mailboxes/([1-9]\d{0,17})/edit$#D' => ['mail_mailbox', 'mail_mailboxes', '/settings/mail/mailboxes/{id}/edit', '/settings/mail/mailboxes'],
        // Pages without an id keep their path; only `?postfach=` becomes `?mailbox=`.
        '#^/mail(/(?:inbox|sent|drafts|archive|trash|compose|signature|addressbook|lists|lists/create))?/?$#D' => [null, null, '/mail$1', '/mail$1'],
        '#^/settings/mail(/mailboxes(?:/groups/create)?)?/?$#D' => [null, null, '/settings/mail$1', '/settings/mail$1'],
    ];

    /**
     * The 301 target of a legacy mail page (MAIL_PAGES) with the new ids: `?postfach=N` => `?mailbox=<new mailbox>`
     * (dropped when not imported), other query parameters dropped. Null when $path is no mail page or the request is not
     * marked as legacy (no `?postfach=`, no `.php`); then the live route answers. The `/preview` fragment and the legacy
     * POST endpoints are gone (404/405).
     */
    public static function mailRedirect(string $path, string $rawQuery, bool $php): ?string
    {
        parse_str(ltrim($rawQuery, '?'), $params);
        $postfach = $params['postfach'] ?? null;
        if (! $php && $postfach === null) {
            return null;
        }
        foreach (self::MAIL_PAGES as $pattern => [$entity, $table, $target, $parent]) {
            if (preg_match($pattern, $path, $m) !== 1) {
                continue;
            }
            $id = $entity === null ? null : self::mapped($entity, (string) $table, $m[count($m) - 1]);
            $location = (string) preg_replace($pattern, $entity !== null && $id === null ? $parent : $target, $path);
            $location = str_replace('{id}', (string) $id, $location);
            $mailbox = is_string($postfach) && preg_match('/^[1-9]\d{0,17}$/D', $postfach) === 1 ? self::mapped('mail_mailbox', 'mail_mailboxes', $postfach) : null;

            return ($location === '' ? '/mail' : $location).($mailbox === null ? '' : '?mailbox='.$mailbox);
        }

        return null;
    }

    /** The new id of legacy $id in $entity when its row still exists. */
    private static function mapped(string $entity, string $table, string $id): ?int
    {
        if (! Schema::hasTable($table)) {
            return null;
        }
        $new = DB::table('legacy_id_map')->join($table, "{$table}.id", '=', 'legacy_id_map.new_id')
            ->where('legacy_id_map.entity', $entity)->where('legacy_id_map.legacy_id', $id)->value("{$table}.id");

        return $new === null ? null : (int) $new;
    }

    /** Legacy incident status values (list filter `?status=`, intra_manv_lagen.status) => new. */
    public const MANV_STATUSES = ['aktiv' => 'active', 'abgeschlossen' => 'closed', 'archiviert' => 'archived'];

    /** Legacy fireTab QM list sort keys (`?sort=`) => QM list sort columns; others fall back to the default. */
    private const FIRE_SORTS = ['nr' => 'number', 'start' => 'started_at', 'location' => 'location', 'keyword' => 'keyword', 'status' => 'status'];

    /** Query parameters (and sort values) renamed when a legacy page is redirected, keyed by the legacy path. */
    private const QUERY = [
        '/personnel/list' => [
            'keys' => ['archiv' => 'archive', 'q' => 'search', 'dg' => 'rank', 'rd' => 'ems', 'fw' => 'fire'],
            'sort' => ['dienstnr' => 'service_number', 'dienstgrad' => 'rank', 'rd' => 'ems', 'fw' => 'fire', 'einstdatum' => 'hired_at'],
        ],
        '/settings/vehicles/vehicles/index' => [
            'keys' => ['q' => 'search'],
            'sort' => ['kennzeichen' => 'license_plate', 'rd' => 'service'],
        ],
    ];

    /** @return array<string, string> */
    public static function rewrites(): array
    {
        return self::REWRITES;
    }

    /**
     * The query string (with leading `?`, or empty) for the redirect from `$legacyPath`: untouched unless the
     * table renames parameters. `archiv` was a bare flag, `archive` takes 1.
     */
    public static function redirectQuery(string $legacyPath, string $rawQuery): string
    {
        if ($legacyPath === '/personnel/profile') {
            return '';
        }
        if ($legacyPath === '/lexicon/view' || $legacyPath === '/lexicon/edit' || $legacyPath === '/calendar/view') {
            return '';
        }
        if (isset(self::MANV_PAGES[$legacyPath]) || $legacyPath === '/mci/patient-view') {
            return '';
        }
        if ($legacyPath === '/firetab/admin/list') {
            parse_str(ltrim($rawQuery, '?'), $params);
            $out = array_filter([
                'archived' => ($params['show_archived'] ?? null) === '1' ? '1' : null,
                'q' => is_string($params['q'] ?? null) ? $params['q'] : null,
                'sort' => is_string($params['sort'] ?? null) ? (self::FIRE_SORTS[$params['sort']] ?? null) : null,
                'dir' => in_array($params['dir'] ?? null, ['asc', 'desc'], true) ? $params['dir'] : null,
            ], fn ($v) => $v !== null && $v !== '');

            return $out === [] ? '' : '?'.http_build_query($out);
        }
        if (str_starts_with($legacyPath, '/firetab/') || str_starts_with($legacyPath, '/enotf/')) {
            return '';
        }
        if (in_array($legacyPath, ['/mci', '/mci/', '/mci/index'], true) && strlen($rawQuery) > 1) {
            parse_str(substr($rawQuery, 1), $params);
            if (is_string($params['status'] ?? null) && isset(self::MANV_STATUSES[$params['status']])) {
                $params['status'] = self::MANV_STATUSES[$params['status']];

                return '?'.http_build_query($params);
            }

            return $rawQuery;
        }
        if (isset(self::REDIRECTS[$legacyPath]) && (str_starts_with($legacyPath, '/forms/') || str_starts_with($legacyPath, '/settings/forms/'))) {
            return '';
        }

        $map = self::QUERY[$legacyPath] ?? null;
        if ($map === null || strlen($rawQuery) < 2) {
            return $rawQuery;
        }

        parse_str(substr($rawQuery, 1), $params);
        $out = [];
        foreach ($params as $key => $value) {
            $new = $map['keys'][$key] ?? $key;
            $out[$new] = match (true) {
                $key === 'archiv' => '1',
                $new === 'sort' && is_string($value) => $map['sort'][$value] ?? $value,
                default => $value,
            };
        }

        return '?'.http_build_query($out);
    }

    /**
     * Redirect target for `$legacyPath`: the table's one, except `/personnel/profile?id=N` goes to `/personnel/N` and
     * `/forms(/admin)/view?antrag=X` to `/forms(/admin)/<public id>`: the submission imported under the legacy uniqueid X
     * (legacy_id_map `form_uniqueid`; its public id may have been regenerated), else X itself when it has six digits;
     * `/calendar/view?id=N` to `/calendar?event=<new id>` when legacy event N was imported (entity `calendar_event`);
     * the MANV pages (MANV_PAGES, `/mci/patient-view?id=N`) to the imported incident or patient, else the list.
     */
    public static function redirectLocation(string $legacyPath, string $location, string $rawQuery): string
    {
        if ($legacyPath === '/firetab/view' || $legacyPath === '/firetab/asu') {
            parse_str(ltrim($rawQuery, '?'), $params);
            $id = $params[$legacyPath === '/firetab/view' ? 'id' : 'incident_id'] ?? null;
            $new = is_string($id) && preg_match('/^[1-9]\d{0,17}$/D', $id) === 1 ? self::mapped('fire_incident', 'fire_incidents', $id) : null;
            if ($new === null) {
                return $location;
            }
            $tab = $legacyPath === '/firetab/asu' ? 'atemschutz' : ($params['tab'] ?? null);

            return "/firetab/incidents/{$new}".(is_string($tab) && preg_match('/^[a-z_-]{1,30}$/D', $tab) === 1 ? "?tab={$tab}" : '');
        }
        if (in_array($legacyPath, ['/enotf/print', '/enotf/print/', '/enotf/print/index'], true)) {
            parse_str(ltrim($rawQuery, '?'), $params);
            $enr = $params['enr'] ?? null;
            // Shape check only, no lookup (a guest must not learn which ENRs exist); the print view's policy decides.
            $wellFormed = is_string($enr) && preg_match('/^[0-9A-Za-z_.-]{1,64}$/D', $enr) === 1;

            return $wellFormed ? '/enotf/print/'.rawurlencode($enr) : $location;
        }
        if (str_starts_with($legacyPath, '/enotf/')) {
            return self::enotfLocation($legacyPath, $location, $rawQuery);
        }
        if (isset(self::MANV_PAGES[$legacyPath]) || $legacyPath === '/mci/patient-view') {
            [$key, $suffix] = self::MANV_PAGES[$legacyPath] ?? ['id', null];
            parse_str(substr($rawQuery, 1), $params);
            $id = $params[$key] ?? null;
            if (! is_string($id) || preg_match('/^[1-9]\d{0,17}$/D', $id) !== 1 || ! Schema::hasTable('manv_incidents')) {
                return $location;
            }
            if ($suffix === null) {
                $patient = DB::table('legacy_id_map')->join('manv_patients', 'manv_patients.id', '=', 'legacy_id_map.new_id')
                    ->where('legacy_id_map.entity', 'manv_patient')->where('legacy_id_map.legacy_id', $id)->first(['manv_patients.id', 'manv_patients.manv_incident_id']);

                return $patient === null ? $location : '/mci/'.(int) $patient->manv_incident_id.'/patients/'.(int) $patient->id;
            }
            $incident = DB::table('legacy_id_map')->join('manv_incidents', 'manv_incidents.id', '=', 'legacy_id_map.new_id')
                ->where('legacy_id_map.entity', 'manv_incident')->where('legacy_id_map.legacy_id', $id)->value('manv_incidents.id');

            return $incident === null ? $location : '/mci/'.(int) $incident.$suffix;
        }
        if ($legacyPath === '/calendar/view') {
            parse_str(substr($rawQuery, 1), $params);
            $id = $params['id'] ?? null;
            if (! is_string($id) || preg_match('/^[1-9]\d{0,17}$/D', $id) !== 1) {
                return $location;
            }
            $event = DB::table('legacy_id_map')->join('calendar_events', 'calendar_events.id', '=', 'legacy_id_map.new_id')
                ->where('legacy_id_map.entity', 'calendar_event')->where('legacy_id_map.legacy_id', $id)->value('calendar_events.id');

            return $event === null ? $location : $location.'?event='.(int) $event;
        }
        if ($legacyPath === '/forms/view' || $legacyPath === '/forms/admin/view') {
            parse_str(substr($rawQuery, 1), $params);
            $id = $params['antrag'] ?? null;

            if (! is_string($id) || $id === '' || strlen($id) > 64) {
                return $location;
            }
            if (! Schema::hasTable('form_submissions')) {
                return $location;
            }
            $imported = DB::table('legacy_id_map')->join('form_submissions', 'form_submissions.id', '=', 'legacy_id_map.new_id')
                ->where('legacy_id_map.entity', 'form_uniqueid')->where('legacy_id_map.legacy_id', $id)->value('form_submissions.public_id');
            $publicId = $imported === null ? $id : (string) $imported;

            return preg_match('/^[0-9]{6}$/D', $publicId) === 1 ? $location.'/'.$publicId : $location;
        }
        if ($legacyPath === '/personnel/profile') {
            parse_str(substr($rawQuery, 1), $params);
            $id = $params['id'] ?? null;

            return is_string($id) && preg_match('/^[1-9]\d{0,17}$/', $id) === 1 ? '/personnel/'.$id : $location;
        }

        return $location;
    }

    /**
     * eNOTF sub pages: `/enotf/p/{enr}/{section}/…` => the section (else the protocol), `/enotf/protokoll[/{section}/…]?enr=X`
     * => /enotf/p/X[/{section}] (no enr: the overview), the QM fragments by legacy protocol id => /enotf/qm/{enr} (not
     * imported: the admin list). The ENR is only shape-checked; the page's policy decides.
     */
    private static function enotfLocation(string $legacyPath, string $location, string $rawQuery): string
    {
        $sections = ['rettdaten', 'anamnese', 'erstbefund', 'diagnose', 'massnahmen', 'verlauf', 'abschluss'];
        $section = fn (string $rest) => in_array($s = explode('/', trim($rest, '/'))[0], $sections, true) ? '/'.$s : '';
        if (preg_match(self::ENOTF_SUBPAGE, $legacyPath, $m) === 1) {
            return '/enotf/p/'.$m[1].$section($m[2]);
        }
        parse_str(ltrim($rawQuery, '?'), $params);
        if ($legacyPath === '/enotf/schnittstelle/voranmeldung') {
            $enr = $params['enr'] ?? null;

            return is_string($enr) && preg_match('/^[\w.-]{1,64}$/D', $enr) === 1 ? '/enotf/p/'.$enr.'/prereg' : $location;
        }
        if ($legacyPath === '/enotf/protokoll' || str_starts_with($legacyPath, '/enotf/protokoll/')) {
            $enr = $params['enr'] ?? null;

            return is_string($enr) && preg_match('/^[\w.-]{1,64}$/D', $enr) === 1
                ? '/enotf/p/'.$enr.$section(substr($legacyPath, strlen('/enotf/protokoll'))) : $location;
        }
        $id = preg_match('#^/enotf/qm/(?:actions|log)/(\d+)$#D', $legacyPath, $m) === 1 ? $m[1]
            : (str_starts_with($legacyPath, '/enotf/admin/qm-') ? ($params['id'] ?? null) : null);
        if (is_string($id) && preg_match('/^[1-9]\d{0,17}$/D', $id) === 1 && Schema::hasTable('enotf_protocols')) {
            $enr = DB::table('legacy_id_map')->join('enotf_protocols', 'enotf_protocols.id', '=', 'legacy_id_map.new_id')
                ->where('legacy_id_map.entity', 'enotf_protocol')->where('legacy_id_map.legacy_id', $id)->value('enotf_protocols.enr');

            return $enr === null ? $location : '/enotf/qm/'.rawurlencode((string) $enr);
        }

        return $location;
    }

    /** @return array<string, string> */
    public static function topSegments(): array
    {
        return self::TOP;
    }

    /** @return array<string, array{string, int}> from => [to, status] */
    public static function redirects(): array
    {
        return self::REDIRECTS;
    }

    /** @return array<string, array{string, int, string}> pattern => [to, status, sample] */
    public static function patternRedirects(): array
    {
        return self::PATTERN_REDIRECTS;
    }

    /** @return array{string, int}|null [to, status] from REDIRECTS or PATTERN_REDIRECTS */
    public static function redirectFor(string $path): ?array
    {
        if (isset(self::REDIRECTS[$path])) {
            return self::REDIRECTS[$path];
        }
        foreach (self::PATTERN_REDIRECTS as $pattern => [$to, $status]) {
            if (preg_match($pattern, $path) === 1) {
                return [(string) preg_replace($pattern, $to, $path), $status];
            }
        }

        return null;
    }

    /**
     * German legacy path → canonical English path (leading `/`, trailing slash kept, empty
     * segments dropped as in the legacy code), or null if nothing changes.
     */
    public static function translate(string $path): ?string
    {
        $trailing = str_ends_with($path, '/');
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s) => $s !== ''));
        if ($segments === []) {
            return null;
        }

        $changed = false;
        if ($segments[0] === 'settings') {
            for ($i = 1; $i < count($segments); $i++) {
                if (isset(self::SETTINGS[$segments[$i]])) {
                    $segments[$i] = self::SETTINGS[$segments[$i]];
                    $changed = true;
                }
            }
        } elseif ($segments[0] === 'api') {
            $i = ($segments[1] ?? null) === 'v1' && isset($segments[2]) ? 2 : 1;
            if (isset($segments[$i], self::API[$segments[$i]])) {
                $segments[$i] = self::API[$segments[$i]];
                $changed = true;
            }
        } elseif (isset(self::TOP[$segments[0]])) {
            $segments[0] = self::TOP[$segments[0]];
            $changed = true;
        }

        return $changed ? '/'.implode('/', $segments).($trailing ? '/' : '') : null;
    }
}
