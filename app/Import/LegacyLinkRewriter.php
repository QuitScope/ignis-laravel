<?php

namespace App\Import;

use App\Dashboard\LinkTarget;
use App\Http\LegacyUrlMap;
use App\Notifications\InboxNotification;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Enotf\Support\FieldSchema;

/**
 * Legacy links of notifications and quick links in the new instance. Steps: strip the legacy BASE_PATH (config
 * ignis.import.legacy_base_path), translate German paths and drop `.php` like the legacy URL layer (LegacyUrlMap),
 * then rewrite the known core pages, mapping their LEGACY record ids through legacy_id_map (vehicle, personnel, kb_entry,
 * calendar_event, manv_incident, manv_patient, mail_message, fire_incident; applications by their legacy uniqueid, entity form_uniqueid, to the submission's public id).
 * Everything else stays as it was and is checked: notifications keep internal paths only
 * (InboxNotification::safeLink), quick links also keep http(s)/mailto targets (LinkTarget).
 *
 * eNOTF protocol pages (entity enotf_protocol, by ENR) are known. Module pages (knowledge base, forms,
 * calendar, MANV, mail messages, fireTab and eNOTF are done) MUST add their pages to known() with their id map
 * entity: until then their links are kept unchanged and still carry legacy record ids (they would open the wrong
 * record). A re-run of the importers rewrites them again.
 */
final class LegacyLinkRewriter
{
    /**
     * @param  array<int|string, int>  $vehicles  legacy id => new id
     * @param  array<int|string, int>  $personnel  legacy id => new id
     * @param  array<int|string, int>  $kbEntries  legacy id => new id (knowledge base, entity kb_entry)
     * @param  array<int|string, string>  $forms  legacy uniqueid => public id of the imported submission
     * @param  array<int|string, int>  $calendarEvents  legacy id => new id (entity calendar_event)
     * @param  array<int|string, int>  $manvIncidents  legacy id => new id (entity manv_incident)
     * @param  array<int|string, array{0: int, 1: int}>  $manvPatients  legacy id => [new incident id, new patient id] (entity manv_patient)
     * @param  array<int|string, int>  $mailMessages  legacy id => new id (entity mail_message)
     * @param  array<int|string, int>  $fireIncidents  legacy id => new id (entity fire_incident)
     * @param  array<int|string, string>  $enotfProtocols  legacy id => ENR (entity enotf_protocol; the ENR is kept on import)
     */
    public function __construct(private readonly array $vehicles, private readonly array $personnel, private readonly array $kbEntries = [],
        private readonly array $forms = [], private readonly array $calendarEvents = [], private readonly array $manvIncidents = [],
        private readonly array $manvPatients = [], private readonly array $mailMessages = [], private readonly array $fireIncidents = [],
        private readonly array $enotfProtocols = []) {}

    /** @var array<string, true>|null imported ENRs */
    private ?array $enrs = null;

    public static function fromIds(IdMap $ids): self
    {
        $forms = DB::table('legacy_id_map')->join('form_submissions', 'form_submissions.id', '=', 'legacy_id_map.new_id')
            ->where('legacy_id_map.entity', 'form_uniqueid')->pluck('form_submissions.public_id', 'legacy_id_map.legacy_id')
            ->map(fn ($id) => (string) $id)->all();

        $patients = Schema::hasTable('manv_patients')
            ? DB::table('legacy_id_map')->join('manv_patients', 'manv_patients.id', '=', 'legacy_id_map.new_id')->where('legacy_id_map.entity', 'manv_patient')
                ->get(['legacy_id_map.legacy_id', 'manv_patients.id', 'manv_patients.manv_incident_id'])
                ->mapWithKeys(fn ($p) => [(string) $p->legacy_id => [(int) $p->manv_incident_id, (int) $p->id]])->all()
            : [];

        $protocols = Schema::hasTable('enotf_protocols')
            ? DB::table('legacy_id_map')->join('enotf_protocols', 'enotf_protocols.id', '=', 'legacy_id_map.new_id')->where('legacy_id_map.entity', 'enotf_protocol')
                ->pluck('enotf_protocols.enr', 'legacy_id_map.legacy_id')->map(fn ($enr) => (string) $enr)->all()
            : [];

        return new self($ids->forEntity('vehicle'), $ids->forEntity('personnel'), $ids->forEntity('kb_entry'), $forms, $ids->forEntity('calendar_event'),
            $ids->forEntity('manv_incident'), $patients, $ids->forEntity('mail_message'), $ids->forEntity('fire_incident'), $protocols);
    }

    public static function withoutBasePath(string $url): string
    {
        $prefix = '/'.trim((string) config('ignis.import.legacy_base_path', ''), '/');
        if ($prefix === '/') {
            return $url;
        }
        if ($url === $prefix) {
            return '/';
        }
        if (str_starts_with($url, $prefix.'/')) {
            return substr($url, strlen($prefix));
        }

        return str_starts_with($url, $prefix.'?') ? '/'.substr($url, strlen($prefix)) : $url;
    }

    /**
     * Host and port of the legacy SYSTEM_URL (a bare domain counts as https), null when unset or a placeholder. Content
     * importers use it with localPath() to tell absolute links to the legacy instance from external ones.
     *
     * @return ?array{0: string, 1: string}
     */
    public static function legacyHost(LegacyReader $legacy): ?array
    {
        if (! $legacy->tableExists('intra_config')) {
            return null;
        }
        $url = trim((string) $legacy->table('intra_config')->where('config_key', 'SYSTEM_URL')->value('config_value'));
        if (LegacyConfigMap::isPlaceholder($url)) {
            return null;
        }
        $parts = parse_url(preg_match('~^https?://~i', $url) === 1 ? $url : 'https://'.$url);

        return is_array($parts) && isset($parts['host']) ? self::hostPort($parts) : null;
    }

    /**
     * Path (with query and fragment) of an absolute URL on $host (legacyHost()), else null.
     *
     * @param  ?array{0: string, 1: string}  $host
     */
    public static function localPath(string $url, ?array $host): ?string
    {
        $parts = parse_url($url);
        if ($host === null || ! is_array($parts) || self::hostPort($parts) !== $host) {
            return null;
        }

        return ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '').(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    /**
     * @param  array<string, int|string>  $parts  parse_url() result
     * @return ?array{0: string, 1: string}
     */
    private static function hostPort(array $parts): ?array
    {
        if (! isset($parts['host'])) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $port = isset($parts['port']) && (int) $parts['port'] !== ($scheme === 'http' ? 80 : 443) ? (string) $parts['port'] : '';

        return [strtolower((string) $parts['host']), $port];
    }

    /**
     * A notification link: `none` (no link), `rewritten` (a known legacy page), `unmapped` (its vehicle or personnel
     * file was not imported: the list page), `kept` (another internal path) or `dropped` (not an internal path).
     *
     * @return array{0: ?string, 1: 'none'|'rewritten'|'unmapped'|'kept'|'dropped'}
     */
    public function notificationLink(?string $raw): array
    {
        $url = trim((string) $raw);
        if ($url === '') {
            return [null, 'none'];
        }
        $url = self::withoutBasePath($url);
        $known = str_starts_with($url, '/') ? $this->known($url) : null;
        if ($known !== null) {
            return $known[0] === null ? [null, 'dropped'] : $known;
        }
        $kept = InboxNotification::safeLink($url);

        return $kept === null ? [null, 'dropped'] : [$kept, 'kept'];
    }

    /**
     * A quick link target, the outcomes of notificationLink() plus `external` (http(s)/mailto, kept) and `placeholder`
     * (`#`, `#…`, `?…` or empty: pointed nowhere). A relative path without leading `/` was rendered below the legacy
     * BASE_PATH, so it gets a `/` (third value true), unless it has a `..` segment.
     *
     * @return array{0: ?string, 1: 'rewritten'|'unmapped'|'kept'|'external'|'placeholder'|'dropped', 2: bool}
     */
    public function tileTarget(string $raw): array
    {
        $url = self::withoutBasePath(trim($raw));
        if ($url === '' || $url[0] === '#' || $url[0] === '?') {
            return [null, 'placeholder', false];
        }
        if (preg_match('~^[A-Za-z][A-Za-z0-9+.-]*:~', $url) === 1) {
            $external = LinkTarget::normalise($url);

            return $external === null ? [null, 'dropped', false] : [$external, 'external', false];
        }
        $prefixed = ! str_starts_with($url, '/');
        if ($prefixed) {
            if (preg_match('~(?:^|/)\.\.(?:[/?#]|$)~', $url) === 1) {
                return [null, 'dropped', false];
            }
            $url = '/'.$url;
        }
        [$link, $outcome] = $this->known($url) ?? [$url, 'kept'];
        $link = $link === null ? null : LinkTarget::normalise($link);

        return $link === null ? [null, 'dropped', false] : [$link, $outcome, $prefixed];
    }

    /**
     * The new page of a known legacy core page ($url starts with `/`), null when it is none. A known page with an
     * unusable parameter (bad docid) gives [null, 'dropped'].
     *
     * @return ?array{0: ?string, 1: 'rewritten'|'unmapped'|'dropped'}
     */
    private function known(string $url): ?array
    {
        [$path, $query] = explode('?', explode('#', $url, 2)[0], 2) + [1 => ''];
        $path = (string) preg_replace('~\.php$~', '', $path);
        $path = LegacyUrlMap::translate($path) ?? $path;
        parse_str($query, $params);
        $param = fn (string $key) => is_string($params[$key] ?? null) ? $params[$key] : '';
        $map = fn (array $ids, string $legacy) => ctype_digit($legacy) ? ($ids[$legacy] ?? null) : null;

        if (preg_match('#^/settings/vehicles/defects(?:/index)?/?$#D', $path) === 1) {
            if ($param('vehicle') === '') {
                return ['/vehicles/defects', 'rewritten'];
            }
            $id = $map($this->vehicles, $param('vehicle'));

            return $id === null ? ['/vehicles/defects', 'unmapped'] : ["/vehicles/defects?vehicle={$id}", 'rewritten'];
        }
        if (preg_match('#^/settings/vehicles/vehicles(?:/index)?/?$#D', $path) === 1) {
            return ['/vehicles', 'rewritten'];
        }
        if (preg_match('#^/settings/vehicles/vehicles/(\d{1,18})(/edit)?/?$#D', $path, $m) === 1) {
            $id = $map($this->vehicles, $m[1]);

            return $id === null ? ['/vehicles', 'unmapped'] : ["/vehicles/{$id}".($m[2] ?? ''), 'rewritten'];
        }
        if (preg_match('#^/personnel/profile/?$#D', $path) === 1) {
            $id = $map($this->personnel, $param('id'));

            return $id === null ? ['/personnel', 'unmapped'] : ["/personnel/{$id}", 'rewritten'];
        }
        if (preg_match('#^/(?:personnel/document-view|personnel/dokument-view|assets/functions/docredir)/?$#D', $path) === 1) {
            $docid = $param('docid');

            return preg_match('/^[A-Za-z0-9-]{1,32}$/D', $docid) === 1
                ? ["/personnel/document-view?docid={$docid}", 'rewritten'] : [null, 'dropped'];
        }
        // Knowledge base (entity kb_entry; `/wissensdb/...` is translated above). Entry ids are new, so an entry that was not
        // imported (or a bad id) leads to the list.
        if (preg_match('#^/lexicon/(view|edit)/?$#D', $path, $m) === 1) {
            $id = $map($this->kbEntries, $param('id'));

            return $id === null ? ['/lexicon', 'unmapped'] : ["/lexicon/{$id}".($m[1] === 'edit' ? '/edit' : ''), 'rewritten'];
        }
        if (preg_match('#^/lexicon(?:/index)?/?$#D', $path) === 1) {
            return ['/lexicon', 'rewritten'];
        }
        if (preg_match('#^/lexicon/manage-taxonomy/?$#D', $path) === 1) {
            return ['/lexicon/taxonomy', 'rewritten'];
        }
        // Applications (`/antrag/...`, `/antraege/...` are translated above): the legacy uniqueid leads to the public id, which
        // equals it unless it was taken or invalid. A submission that was not imported leads to the list.
        if (preg_match('#^/forms/(admin/)?view/?$#D', $path, $m) === 1) {
            $base = ($m[1] ?? '') === '' ? '/forms' : '/forms/admin';
            $publicId = $this->forms[$param('antrag')] ?? null;

            return $publicId === null ? [$base, 'unmapped'] : ["{$base}/{$publicId}", 'rewritten'];
        }
        if (preg_match('#^/forms(?:/select|/create)?/?$#D', $path) === 1) {
            return ['/forms', 'rewritten'];
        }
        if (preg_match('#^/forms/admin(?:/list)?/?$#D', $path) === 1) {
            return ['/forms/admin', 'rewritten'];
        }
        // Calendar (`/kalender/...` is translated above): the event opens on the calendar page; one that was not imported
        // (absence mirrors are recreated under new ids) leads to the calendar.
        if (preg_match('#^/calendar/view/?$#D', $path) === 1) {
            $id = $map($this->calendarEvents, $param('id'));

            return $id === null ? ['/calendar', 'unmapped'] : ["/calendar?event={$id}", 'rewritten'];
        }
        if (preg_match('#^/calendar(?:/index)?/?$#D', $path) === 1) {
            return ['/calendar', 'rewritten'];
        }
        if (preg_match('#^/calendar/create/?$#D', $path) === 1) {
            return ['/calendar?new=1', 'rewritten'];
        }
        // FireTab (entity fire_incident; `/einsatz/...` is translated to `/firetab/...` above): an incident that was not
        // imported leads to the list.
        if (preg_match('#^/firetab/(view|asu)/?$#D', $path, $m) === 1) {
            $id = $map($this->fireIncidents, $param($m[1] === 'view' ? 'id' : 'incident_id'));
            $tab = $m[1] === 'asu' ? 'atemschutz' : $param('tab');

            return $id === null ? ['/firetab/list', 'unmapped']
                : ["/firetab/incidents/{$id}".(preg_match('/^[a-z_-]{1,30}$/D', $tab) === 1 ? "?tab={$tab}" : ''), 'rewritten'];
        }
        // MANV board (`/manv/...` is translated to `/mci/...` above; entities manv_incident, manv_patient): an incident or patient
        // that was not imported leads to the list.
        if (isset(LegacyUrlMap::MANV_PAGES[rtrim($path, '/')])) {
            [$key, $suffix] = LegacyUrlMap::MANV_PAGES[rtrim($path, '/')];
            $id = $map($this->manvIncidents, $param($key));

            return $id === null ? ['/mci', 'unmapped'] : ["/mci/{$id}{$suffix}", 'rewritten'];
        }
        if (preg_match('#^/mci/patient-view/?$#D', $path) === 1) {
            $patient = ctype_digit($param('id')) ? ($this->manvPatients[$param('id')] ?? null) : null;

            return $patient === null ? ['/mci', 'unmapped'] : ["/mci/{$patient[0]}/patients/{$patient[1]}", 'rewritten'];
        }
        if (preg_match('#^/mci(?:/index)?/?$#D', $path) === 1) {
            $status = LegacyUrlMap::MANV_STATUSES[$param('status')] ?? null;

            return [$status === null ? '/mci' : "/mci?status={$status}", 'rewritten'];
        }
        if (preg_match('#^/mci/create/?$#D', $path) === 1) {
            return ['/mci/create', 'rewritten'];
        }
        // Mail (entity mail_message): a message in one of the legacy folders, the notification key `mail/inbox/<id>` included;
        // the dropped preview fragment opens the message. `?postfach=` is dropped (the new page picks the mailbox itself).
        // A message that was not imported leads to the inbox.
        if (preg_match('#^/mail/(inbox|sent|drafts|archive|trash)/(\d{1,18})(?:/preview)?/?$#D', $path, $m) === 1) {
            $id = $map($this->mailMessages, $m[2]);

            return $id === null ? ['/mail', 'unmapped'] : ["/mail/{$m[1]}/{$id}", 'rewritten'];
        }
        if (preg_match('#^/(?:inbox(?:/index)?/?|notifications(?:/.*)?)$#D', $path) === 1) {
            return ['/inbox', 'rewritten'];
        }

        return str_starts_with($path, '/enotf') ? $this->enotf($path, $param) : null;
    }

    /**
     * eNOTF (group enotf_protocol): protocol, print and old protokoll pages by ENR (kept on import),
     * the QM fragments by the legacy protocol id. A protocol that was not imported leads to the overview (crew pages) or
     * the admin list (QM pages).
     *
     * @param  Closure(string): string  $param
     * @return ?array{0: ?string, 1: 'rewritten'|'unmapped'|'dropped'}
     */
    private function enotf(string $path, Closure $param): ?array
    {
        $this->enrs ??= array_fill_keys(array_map('strval', $this->enotfProtocols), true);
        $page = function (string $enr, string $rest, string $base): array {
            $section = explode('/', trim($rest, '/'))[0];
            $section = $base === '/enotf/p/' && in_array($section, FieldSchema::SECTIONS, true) ? '/'.$section : '';

            return isset($this->enrs[$enr]) ? [$base.rawurlencode($enr).$section, 'rewritten'] : ['/enotf/overview', 'unmapped'];
        };
        if (preg_match('#^/enotf(?:/|/index)?$#D', $path) === 1) {
            return ['/enotf', 'rewritten'];
        }
        if (preg_match('#^/enotf/p/([\w.-]{1,64})(/[\w/-]*)?$#D', $path, $m) === 1) {
            return $page($m[1], $m[2] ?? '', '/enotf/p/');
        }
        if (preg_match('#^/enotf/protokoll(/[\w./-]*)?$#D', $path, $m) === 1) {
            return $param('enr') === '' ? ['/enotf/overview', 'rewritten'] : $page($param('enr'), (string) preg_replace('/\.php$/', '', $m[1] ?? ''), '/enotf/p/');
        }
        if (preg_match('#^/enotf/print(?:/|/index)?$#D', $path) === 1) {
            return $param('enr') === '' ? ['/enotf/overview', 'rewritten'] : $page($param('enr'), '', '/enotf/print/');
        }
        if (preg_match('#^/enotf/print/([\w.-]{1,64})/?$#D', $path, $m) === 1) {
            return $page($m[1], '', '/enotf/print/');
        }
        if (preg_match('#^/enotf/(?:qm/(?:actions|log)/(\d{1,18})|admin/qm-(?:actions|log)-modal)/?$#D', $path, $m) === 1) {
            $enr = $this->enotfProtocols[($m[1] ?? '') !== '' ? $m[1] : $param('id')] ?? null;

            return $enr === null ? ['/enotf/admin', 'unmapped'] : ['/enotf/qm/'.rawurlencode($enr), 'rewritten'];
        }
        if (preg_match('#^/enotf/admin(?:/|/list|/index)?$#D', $path) === 1) {
            return ['/enotf/admin', 'rewritten'];
        }

        return null;
    }
}
