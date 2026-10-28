<?php

namespace App\Import;

use Carbon\Carbon;
use Closure;
use finfo;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Modules\Mail\Content\MailContentValidator;
use Modules\Mail\Content\PlainText;
use Modules\Mail\Models\MailSignature;
use Modules\Mail\Support\AddressGenerator;
use Modules\Mail\Support\AddressRules;
use Modules\Mail\Support\AttachmentStorage;
use Modules\Mail\Support\MailboxAccess;
use Modules\Mail\Support\SignatureTemplate;
use RuntimeException;
use Throwable;

/**
 * The nine intra_mail_* tables and the MAIL_* config => module Mail. legacy_id_map
 * entities mail_mailbox, mail_list, mail_message, mail_attachment; members, list members, former addresses, copies and
 * signatures are found again by their unique keys. Report rows mail_mailbox, mail_mailbox_member, mail_address_history,
 * mail_list, mail_list_member, mail_message, mail_delivery, mail_attachment, mail_signature, mail_setting.
 *
 * - Every table in blocks of 200 (chunkById, never all rows), one transaction per block, a savepoint per row: a failing
 *   row is rolled back alone and named in the report.
 * - Clocks (3.3): created_at is an instant; updated_at, sent_at, read_at, deleted_at, released_at are PHP wall time the
 *   legacy app wrote into TIMESTAMP columns, so they are read back as wall time of the legacy database clock and then
 *   converted from the PHP zone.
 * - Mailboxes first, the address LITERALLY (replies and history depend on it). personnel/user through their maps (stale
 *   or missing => free; autoBind binds later). A mailbox the new instance already provisioned for the same record is
 *   adopted (its rows stay, address and name come from legacy; its own address stays an alias unless legacy uses it). A
 *   new-instance mailbox that holds a legacy address gets a new one. Personal mailboxes without a record stay inactive.
 * - Lists: rule ids rewritten through the role/rank/ems_qualification/fire_qualification/specialty maps (unmapped ids
 *   dropped, an empty dynamic rule kept, both warned).
 * - Messages in id order: body_json through the strict MailContentValidator after rank badges were rewritten to the new
 *   badge path (or removed) and links to the legacy instance rewritten (LegacyLinkRewriter); rejected => paragraphs of
 *   the plain text of body_html. body_html itself is never stored. in_reply_to to a newer id: second pass.
 * - Copies complete (folder, read_at, flagged, deleted_at); an unknown role or folder fails the row.
 * - Attachments only with --uploads: the file is checked again (name pattern, realpath inside the legacy folder, 5 MB,
 *   magic bytes) and copied with a new name onto the disk mail-attachments; a missing or refused file keeps the row with
 *   missing = true. Files without a row are counted, never copied. A dry run copies nothing.
 * - Signatures and MAIL_DEFAULT_SIGNATURE (legacy plain text too) through SignatureTemplate; MAIL_* => mail.* settings
 *   ("the first one wins", like every setting). The legacy cron rows mail.backfill/mail.cleanup are not imported: the
 *   module schedules both itself.
 */
final class MailImporter implements Importer
{
    private const ENTITY = 'mail';

    public const CHUNK = 200;

    private const TABLES = ['intra_mail_mailboxes', 'intra_mail_mailbox_members', 'intra_mail_address_history', 'intra_mail_lists',
        'intra_mail_list_members', 'intra_mail_messages', 'intra_mail_deliveries', 'intra_mail_attachments', 'intra_mail_signatures'];

    public const RULE_KEYS = ['role_ids' => 'role', 'rank_ids' => 'rank', 'rd_quali_ids' => 'ems_qualification',
        'fw_quali_ids' => 'fire_qualification', 'fachdienst_ids' => 'specialty'];

    private const RULE_TABLES = ['role' => 'roles', 'rank' => 'ranks', 'ems_qualification' => 'ems_qualifications',
        'fire_qualification' => 'fire_qualifications', 'specialty' => 'specialties'];

    private const ROLES = ['sender', 'to', 'cc', 'bcc'];

    private const FOLDERS = ['inbox', 'sent', 'drafts', 'archive', 'trash'];

    private const LEGACY_FILE = '#^(?:storage/private/mail-attachments/)?(mail-\d{1,18}-[0-9a-f]{16}\.[a-z0-9]{1,10})$#D';

    private const LEGACY_DIR = 'storage/private/mail-attachments';

    private const FALLBACK_MAX_BYTES = 100_000;

    private ImportContext $ctx;

    private LegacyDbClock $db;

    private LegacyLinkRewriter $links;

    /** @var ?array{0: string, 1: string} */
    private ?array $legacyHost = null;

    /** @var array<string, list<int|string>> warning => legacy ids */
    private array $warn = [];

    /** @var array<int|string, int> legacy account id => new account id (existing accounts only) */
    private array $users = [];

    /** @var array<int|string, int> legacy mailbox id => new mailbox id */
    private array $mailboxes = [];

    /** @var array<string, string> normalised badge key => badge path of a rank of this instance */
    private array $badges = [];

    /** @var array<string, true> every legacy address (mailboxes, lists, history): never given to a renamed new mailbox */
    private array $legacyAddresses = [];

    /** @var list<string> problems the link/image hooks found for the current row */
    private array $hookProblems = [];

    public function entity(): string
    {
        return self::ENTITY;
    }

    public function dependsOn(): array
    {
        return ['user', 'personnel', 'role', 'rank', 'ems_qualification', 'fire_qualification', 'specialty', 'account_link'];
    }

    public function requiredTables(): array
    {
        return [];
    }

    public function import(ImportContext $ctx): void
    {
        foreach (self::TABLES as $table) {
            if (! $ctx->legacy()->tableExists($table)) {
                $ctx->report()->note(self::ENTITY.": legacy table {$table} missing (plugin mail not installed), skipped.");

                return;
            }
        }
        $this->ctx = $ctx;
        $this->warn = $this->badges = $this->legacyAddresses = [];
        $this->db = LegacyDbClock::for($ctx->legacy(), $ctx->report());
        $ctx->report()->note(self::ENTITY.': '.$this->db->describe());
        $existing = DB::table('users')->pluck('id')->map(fn ($id) => (int) $id)->flip();
        $this->users = array_filter($ctx->ids()->forEntity('user'), fn (int $id) => $existing->has($id));
        $this->legacyHost = LegacyLinkRewriter::legacyHost($ctx->legacy());
        $this->links = LegacyLinkRewriter::fromIds($ctx->ids());
        foreach (DB::table('ranks')->whereNotNull('badge')->orderBy('id')->pluck('badge') as $badge) {
            if (MailContentValidator::validImageSrc($badge)) {
                $this->badges[self::badgeKey((string) $badge)] ??= (string) $badge;
            }
        }

        $this->settings();
        $this->mailboxes();
        $this->members();
        $this->history();
        $this->lists();
        $this->listMembers();
        $this->messages();
        $this->deliveries();
        $this->attachments();
        $this->signatures();
        MailboxAccess::forget();

        foreach ($this->warn as $what => $ids) {
            $ctx->report()->warnRows($what, array_values(array_unique($ids)));
        }
    }

    // ---------------------------------------------------------------- settings

    private function settings(): void
    {
        $report = $this->ctx->report();
        /** @var array<string, ?string> $config */
        $config = $this->ctx->legacy()->tableExists('intra_config')
            ? $this->ctx->legacy()->table('intra_config')->where('config_key', 'like', 'MAIL\_%')->pluck('config_value', 'config_key')->all()
            : [];
        $values = [];
        if (array_key_exists('MAIL_DOMAIN', $config)) {
            $domain = AddressRules::normalize((string) $config['MAIL_DOMAIN']);
            AddressRules::isDomain($domain) ? $values['mail.domain'] = $domain : $report->warn('MAIL_DOMAIN: not a valid domain, not imported (mail.domain keeps its value).');
        }
        if (array_key_exists('MAIL_ADDRESS_PATTERN', $config)) {
            $pattern = trim((string) $config['MAIL_ADDRESS_PATTERN']);
            in_array($pattern, AddressGenerator::PATTERNS, true)
                ? $values['mail.address_pattern'] = $pattern
                : $report->warn('MAIL_ADDRESS_PATTERN: unknown pattern, not imported (mail.address_pattern keeps its value).');
        }
        if (array_key_exists('MAIL_ALLOWED_DOMAINS', $config)) {
            $entries = array_values(array_filter(array_map('trim', explode(',', (string) $config['MAIL_ALLOWED_DOMAINS'])), fn (string $d) => $d !== ''));
            $domains = AddressRules::parseDomains($entries);
            $invalid = count(array_filter($entries, fn (string $d) => ! AddressRules::isDomain(AddressRules::normalize($d))));
            if ($invalid > 0) {
                $report->warn("MAIL_ALLOWED_DOMAINS: {$invalid} invalid domain(s) dropped.");
            }
            $values['mail.allowed_domains'] = $domains;
        }
        if (array_key_exists('MAIL_SEND_COOLDOWN', $config)) {
            $raw = trim((string) $config['MAIL_SEND_COOLDOWN']);
            if (preg_match('/^\d{1,9}$/D', $raw) === 1) {
                $values['mail.send_cooldown'] = min((int) $raw, 3600);
                if ((int) $raw > 3600) {
                    $report->warn('MAIL_SEND_COOLDOWN: over 3600 seconds, set to 3600.');
                }
            } else {
                $report->warn('MAIL_SEND_COOLDOWN: not a number of seconds, not imported (mail.send_cooldown keeps its value).');
            }
        }
        $read = count(array_intersect_key($config, array_flip(['MAIL_DOMAIN', 'MAIL_ADDRESS_PATTERN', 'MAIL_ALLOWED_DOMAINS', 'MAIL_SEND_COOLDOWN'])));
        $report->read('mail_setting', $read);
        $kept = [];
        foreach ($values as $key => $value) {
            $outcome = $this->ctx->fillSetting($key, $value);
            $outcome === 'kept' ? $kept[] = $key : $report->written('mail_setting');
        }
        $report->skippedCount('mail_setting', $read - count($values) + count($kept));
        if ($kept !== []) {
            $report->note(self::ENTITY.': settings already stored in the new instance, kept (--overwrite-settings replaces them): '.implode(', ', $kept));
        }
        $this->defaultSignature($config['MAIL_DEFAULT_SIGNATURE'] ?? null);
    }

    private function defaultSignature(?string $stored): void
    {
        $report = $this->ctx->report();
        $doc = SignatureTemplate::decode($stored);
        if ($doc === null) {
            return;
        }
        $report->read('mail_signature');
        [$doc, $problems] = $this->signature($doc);
        foreach ($problems as $what) {
            $report->warn("MAIL_DEFAULT_SIGNATURE: {$what}.");
        }
        $current = MailSignature::query()->whereNull('mailbox_id')->orderBy('id')->first();
        $wanted = SignatureTemplate::hasContent($doc) && $doc !== SignatureTemplate::parse((string) json_encode(SignatureTemplate::builtIn())) ? $doc : null;
        if ($current?->body == $wanted) {
            $report->written('mail_signature');

            return;
        }
        if ($current !== null && ! $this->ctx->overwriteSettings()) {
            $report->skippedCount('mail_signature', 1);
            $report->note(self::ENTITY.': the default signature of the new instance is kept (--overwrite-settings replaces it with MAIL_DEFAULT_SIGNATURE).');

            return;
        }
        try {
            SignatureTemplate::saveDefault($doc);
        } catch (Throwable $e) {
            $report->failed('mail_signature', 'MAIL_DEFAULT_SIGNATURE: '.ImportReport::describe($e));

            return;
        }
        $report->written('mail_signature');
    }

    // ---------------------------------------------------------------- mailboxes

    private function mailboxes(): void
    {
        $legacy = $this->ctx->legacy();
        foreach (['intra_mail_mailboxes', 'intra_mail_lists', 'intra_mail_address_history'] as $table) {
            foreach ($legacy->table($table)->select(['id', 'address'])->lazyById(1000) as $row) {
                $this->legacyAddresses[AddressRules::normalize((string) $row->address)] = true;
            }
        }
        $personnel = $this->ctx->ids()->forEntity('personnel');
        $existingPersonnel = DB::table('personnel')->pluck('id')->map(fn ($id) => (int) $id)->flip();

        $this->each('mail_mailbox', $legacy->tableWithInstants('intra_mail_mailboxes', ['created_at', 'updated_at']), function (object $row, int $id, array &$problems) use ($personnel, $existingPersonnel) {
            $group = (string) $row->kind === 'group';
            $person = null;
            if (! $group && $row->mitarbeiter_id !== null) {
                $person = $personnel[(string) $row->mitarbeiter_id] ?? null;
                if ($person === null || ! $existingPersonnel->has($person)) {
                    $person = null;
                    $problems[] = 'personnel file not imported, mailbox imported without a record (inactive)';
                }
            }
            $user = $group ? null : $this->user($row->user_id, 'account', $problems);
            $active = (bool) $row->active;
            if (! $group && $person === null && $active) {
                $active = false;
            }

            $target = $this->ctx->ids()->get('mail_mailbox', $id);
            if ($target !== null && ! DB::table('mail_mailboxes')->where('id', $target)->exists()) {
                $target = null;
            }
            $adopted = null;
            if ($target === null && $person !== null) {
                $adopted = DB::table('mail_mailboxes')->where('personnel_id', $person)->first();
                if ($adopted !== null) {
                    $target = (int) $adopted->id;
                    $this->ctx->ids()->put('mail_mailbox', $id, $target);
                    $problems[] = 'mailbox the new instance had already provisioned for the record adopted (address and name from legacy)';
                }
            }
            $address = (string) $row->address;
            $this->freeAddress($address, $target, $id, $problems);

            if ($user !== null && DB::table('mail_mailboxes')->where('user_id', $user)->when($target !== null, fn ($q) => $q->where('id', '!=', $target))->exists()) {
                $problems[] = 'account already owns another mailbox in the new instance, mailbox left free';
                $user = null;
            }
            if ($user === null && $adopted !== null) {
                $user = $adopted->user_id === null ? null : (int) $adopted->user_id;
            }
            $created = LegacyClock::instant($row, 'created_at') ?? now()->toDateTimeString();
            $newId = $this->ctx->ids()->write('mail_mailbox', $id, 'mail_mailboxes', [
                'kind' => $group ? 'group' : 'personal',
                'personnel_id' => $person,
                'user_id' => $user,
                'address' => $address,
                'display_name' => mb_substr((string) $row->display_name, 0, 150),
                'domain' => mb_substr((string) $row->domain, 0, 100),
                'is_active' => $active,
                'is_locked' => (bool) $row->locked,
                'created_at' => $created,
                'updated_at' => $this->wall($row, 'updated_at') ?? $created,
            ]);
            if ($adopted !== null && AddressRules::normalize((string) $adopted->address) !== AddressRules::normalize($address)
                && ! isset($this->legacyAddresses[AddressRules::normalize((string) $adopted->address)])) {
                AddressRules::reserve((string) $adopted->address, $newId);
            }

            return null;
        });
        $this->mailboxes = $this->existing('mail_mailbox', 'mail_mailboxes');
    }

    /**
     * A new-instance mailbox (not imported) that holds the legacy address gets a new one: the legacy address wins. A
     * mailbox imported for another legacy row fails this row instead.
     *
     * @param  list<string>  $problems
     */
    private function freeAddress(string $address, ?int $target, int $legacyId, array &$problems): void
    {
        $other = DB::table('mail_mailboxes')->where('address', AddressRules::normalize($address))
            ->when($target !== null, fn ($q) => $q->where('id', '!=', $target))->first();
        if ($other === null) {
            return;
        }
        $mapped = DB::table('legacy_id_map')->where('entity', 'mail_mailbox')->where('new_id', $other->id)->where('legacy_id', '!=', (string) $legacyId)->exists();
        if ($mapped) {
            throw new RuntimeException('the address belongs to another imported mailbox');
        }
        [$local, $domain] = explode('@', (string) $other->address, 2) + [1 => AddressRules::defaultDomain()];
        for ($n = 2; ; $n++) {
            $candidate = rtrim(substr($local, 0, AddressRules::MAX_LOCAL_LENGTH - strlen((string) $n)), '.-').$n.'@'.$domain;
            if (! isset($this->legacyAddresses[$candidate]) && ! AddressRules::isTaken($candidate)) {
                break;
            }
        }
        DB::table('mail_mailboxes')->where('id', $other->id)->update(['address' => $candidate, 'updated_at' => now()]);
        $problems[] = 'address was held by a mailbox of the new instance, which got a new address';
        $this->ctx->report()->note(self::ENTITY.": new-instance mailbox {$other->id} renamed from {$other->address} to {$candidate} (legacy mailbox {$legacyId} keeps the address)");
    }

    private function members(): void
    {
        $this->each('mail_mailbox_member', $this->ctx->legacy()->tableWithInstants('intra_mail_mailbox_members', ['created_at']), function (object $row, int $id, array &$problems) {
            $mailbox = $this->mailboxes[(string) $row->mailbox_id] ?? null;
            if ($mailbox === null) {
                return 'mailbox not imported';
            }
            $user = $this->user($row->user_id, 'member account', $problems);
            if ($user === null) {
                return 'member account not imported';
            }
            $created = LegacyClock::instant($row, 'created_at') ?? now()->toDateTimeString();
            DB::table('mail_mailbox_members')->upsert([['mailbox_id' => $mailbox, 'user_id' => $user, 'created_at' => $created, 'updated_at' => $created]],
                ['mailbox_id', 'user_id'], ['created_at', 'updated_at']);

            return null;
        });
    }

    private function history(): void
    {
        $this->each('mail_address_history', $this->ctx->legacy()->tableWithInstants('intra_mail_address_history', ['released_at']), function (object $row, int $id) {
            $mailbox = $this->mailboxes[(string) $row->mailbox_id] ?? null;
            if ($mailbox === null) {
                return 'mailbox not imported';
            }
            DB::table('mail_address_history')->upsert([['address' => AddressRules::normalize((string) $row->address), 'mailbox_id' => $mailbox, 'released_at' => $this->wall($row, 'released_at')]],
                ['address'], ['mailbox_id', 'released_at']);

            return null;
        });
    }

    // ---------------------------------------------------------------- lists

    private function lists(): void
    {
        $maps = [];
        foreach (self::RULE_TABLES as $key => $table) {
            $existing = DB::table($table)->pluck('id')->map(fn ($id) => (int) $id)->flip();
            $maps[$key] = array_filter($this->ctx->ids()->forEntity($key), fn (int $id) => $existing->has($id));
        }
        $byRole = false;
        $this->each('mail_list', $this->ctx->legacy()->tableWithInstants('intra_mail_lists', ['created_at', 'updated_at']), function (object $row, int $id, array &$problems) use ($maps, &$byRole) {
            $kind = (string) $row->kind;
            if (! in_array($kind, ['static', 'dynamic'], true)) {
                $problems[] = "unknown kind '".mb_substr($kind, 0, 20)."', imported as static";
                $kind = 'static';
            }
            $senders = (string) $row->senders;
            if (! in_array($senders, ['all', 'managers'], true)) {
                $problems[] = "unknown senders '".mb_substr($senders, 0, 20)."', set to managers";
                $senders = 'managers';
            }
            $rule = null;
            if ($kind === 'dynamic') {
                [$rule, $ruleProblems] = self::rewriteRule($row->rule, $maps);
                array_push($problems, ...$ruleProblems);
                $byRole = $byRole || $rule['role'] !== [];
            }
            $created = LegacyClock::instant($row, 'created_at') ?? now()->toDateTimeString();
            $this->ctx->ids()->write('mail_list', $id, 'mail_lists', [
                'address' => AddressRules::normalize((string) $row->address),
                'name' => mb_substr((string) $row->name, 0, 150),
                'kind' => $kind,
                'rule' => $rule === null ? null : json_encode($rule),
                'senders' => $senders,
                'created_at' => $created,
                'updated_at' => $this->wall($row, 'updated_at') ?? $created,
            ]);

            return null;
        });
        if ($byRole) {
            $this->ctx->report()->note(self::ENTITY.': dynamic lists by role now match ANY role of an account, legacy had one role per account.');
        }
    }

    /**
     * A legacy dynamic rule {role_ids, rank_ids, rd_quali_ids, fw_quali_ids, fachdienst_ids} with the ids of this
     * instance: {role, rank, ems_qualification, fire_qualification, specialty}, every key present, ids sorted.
     *
     * @param  array<string, array<int|string, int>>  $maps  new key => legacy id => new id
     * @return array{0: array<string, list<int>>, 1: list<string>} rule, problems
     */
    public static function rewriteRule(mixed $raw, array $maps): array
    {
        $rule = is_string($raw) ? json_decode($raw, true) : $raw;
        $problems = [];
        if (! is_array($rule)) {
            if ($raw !== null && $raw !== '') {
                $problems[] = 'rule unreadable, imported empty';
            }
            $rule = [];
        }
        $out = [];
        $dropped = false;
        foreach (self::RULE_KEYS as $legacyKey => $key) {
            $ids = [];
            foreach ((array) ($rule[$legacyKey] ?? []) as $value) {
                $new = is_scalar($value) && (int) $value > 0 ? ($maps[$key][(string) (int) $value] ?? null) : null;
                $new === null ? $dropped = true : $ids[] = $new;
            }
            $ids = array_values(array_unique($ids));
            sort($ids);
            $out[$key] = $ids;
        }
        if ($dropped) {
            $problems[] = 'rule ids that were not imported (or invalid) dropped';
        }
        if (array_merge(...array_values($out)) === []) {
            $problems[] = 'dynamic list with an empty rule kept, it delivers to nobody';
        }

        return [$out, $problems];
    }

    private function listMembers(): void
    {
        $lists = $this->existing('mail_list', 'mail_lists');
        $this->each('mail_list_member', $this->ctx->legacy()->table('intra_mail_list_members'), function (object $row) use ($lists) {
            $list = $lists[(string) $row->list_id] ?? null;
            $mailbox = $this->mailboxes[(string) $row->mailbox_id] ?? null;
            if ($list === null || $mailbox === null) {
                return 'list or mailbox not imported';
            }
            DB::table('mail_list_members')->insertOrIgnore(['mail_list_id' => $list, 'mailbox_id' => $mailbox]);

            return null;
        });
    }

    // ---------------------------------------------------------------- messages

    private function messages(): void
    {
        /** @var array<int, int> $pending legacy message id => legacy in_reply_to not mapped yet */
        $pending = [];
        $query = $this->ctx->legacy()->tableWithInstants('intra_mail_messages', ['created_at', 'updated_at', 'sent_at']);

        $this->each('mail_message', $query, function (object $row, int $id, array &$problems, array $replies) use (&$pending) {
            $sender = $this->mailboxes[(string) $row->sender_mailbox_id] ?? null;
            if ($sender === null) {
                return 'sender mailbox not imported';
            }
            $reply = null;
            if ($row->in_reply_to !== null) {
                $reply = $replies[(string) $row->in_reply_to] ?? null;
                if ($reply === null) {
                    $pending[$id] = (int) $row->in_reply_to;
                }
            }
            $this->hookProblems = [];
            [$body, $bodyProblems] = self::body($row->body_json, $row->body_html, $this->href(...), $this->imageSrc(...));
            array_push($problems, ...$bodyProblems, ...$this->hookProblems);
            $status = (string) $row->status;
            if (! in_array($status, ['draft', 'sent'], true)) {
                $problems[] = "unknown status '".mb_substr($status, 0, 20)."', imported as ".($row->sent_at === null ? 'draft' : 'sent');
                $status = $row->sent_at === null ? 'draft' : 'sent';
            }
            $thread = (string) $row->thread_id;
            if (preg_match('/^[0-9A-Za-z]{1,32}$/D', $thread) !== 1) {
                $problems[] = 'thread id longer than 32 characters or not alphanumeric, replaced by its md5';
                $thread = md5($thread);
            }
            [$header, $headerProblems] = self::header($row->header_json);
            array_push($problems, ...$headerProblems);
            $subject = mb_substr((string) $row->subject, 0, 255);
            $created = LegacyClock::instant($row, 'created_at') ?? now()->toDateTimeString();
            $this->ctx->ids()->write('mail_message', $id, 'mail_messages', [
                'sender_mailbox_id' => $sender,
                'sent_by_user_id' => $this->user($row->sent_by_user_id, 'writing account', $problems),
                'subject' => $subject,
                'body' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'search_text' => PlainText::search($subject, $body),
                'header' => json_encode($header, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'thread_id' => $thread,
                'in_reply_to' => $reply,
                'status' => $status,
                'sent_at' => $this->wall($row, 'sent_at'),
                'created_at' => $created,
                'updated_at' => $this->wall($row, 'updated_at') ?? $created,
            ]);

            return null;
        }, function (Collection $rows) {
            $this->links = LegacyLinkRewriter::fromIds($this->ctx->ids());
            $ids = $rows->pluck('in_reply_to')->filter()->map(fn ($v) => (string) $v)->unique()->values()->all();

            return $ids === [] ? [] : DB::table('legacy_id_map')->join('mail_messages', 'mail_messages.id', '=', 'legacy_id_map.new_id')
                ->where('legacy_id_map.entity', 'mail_message')->whereIn('legacy_id_map.legacy_id', $ids)
                ->pluck('mail_messages.id', 'legacy_id_map.legacy_id')->map(fn ($v) => (int) $v)->all();
        });

        foreach (array_chunk($pending, self::CHUNK, true) as $block) {
            DB::transaction(function () use ($block) {
                $map = DB::table('legacy_id_map')->where('entity', 'mail_message')
                    ->whereIn('legacy_id', array_map('strval', [...array_keys($block), ...array_values($block)]))->pluck('new_id', 'legacy_id');
                foreach ($block as $legacyId => $replyTo) {
                    $message = $map[(string) $legacyId] ?? null;
                    $original = $map[(string) $replyTo] ?? null;
                    if ($message === null) {
                        continue;
                    }
                    if ($original === null || ! DB::table('mail_messages')->where('id', $original)->exists()) {
                        $this->warn['mail_message(s): the mail they answer was not imported, in_reply_to left empty'][] = $legacyId;

                        continue;
                    }
                    DB::table('mail_messages')->where('id', $message)->update(['in_reply_to' => (int) $original]);
                }
            });
        }
    }

    /**
     * The legacy header_json as {to, cc, bcc}, each a list of strings (the addresses unchanged): a string becomes a
     * one-element list, a missing key an empty list, anything that is no string (and unknown keys) is dropped and
     * reported; an unreadable header is imported empty.
     *
     * @return array{array{to: list<string>, cc: list<string>, bcc: list<string>}, list<string>}
     */
    public static function header(mixed $json): array
    {
        $out = ['to' => [], 'cc' => [], 'bcc' => []];
        $raw = is_string($json) ? json_decode($json, true) : null;
        if ($json !== null && ! is_array($raw)) {
            return [$out, ['header unreadable, imported empty']];
        }
        $dropped = array_diff_key($raw ?? [], $out) !== [];
        foreach (array_keys($out) as $key) {
            $value = $raw[$key] ?? [];
            $list = is_string($value) ? [$value] : (is_array($value) ? array_values($value) : null);
            if ($list === null) {
                $dropped = true;

                continue;
            }
            $strings = array_values(array_filter($list, 'is_string'));
            $dropped = $dropped || count($strings) !== count($list);
            $out[$key] = $strings;
        }

        return [$out, $dropped ? ['header entries that were not addresses dropped'] : []];
    }

    /**
     * The legacy body (editor JSON) for the mail preset: rank badges and links go through the hooks first (null removes
     * the image or the link, the text stays), then the strict validator. Rejected (or no JSON): paragraphs of the plain
     * text of the legacy body_html (drafts: of the JSON's text), never invalid JSON and never HTML.
     *
     * @param  Closure(string): ?string  $href
     * @param  Closure(string): ?string  $src
     * @return array{0: array{type: 'doc', content: list<array<string, mixed>>}, 1: list<string>} body, problems
     */
    public static function body(mixed $json, ?string $html, Closure $href, Closure $src): array
    {
        $problems = [];
        $doc = is_string($json) ? json_decode($json, true) : $json;
        if (is_array($doc)) {
            $doc = self::rewriteNodes($doc, $href, $src, $problems) ?? [];
            try {
                return [MailContentValidator::validate($doc), array_values(array_unique($problems))];
            } catch (InvalidArgumentException) {
            }
        }
        $lines = trim((string) $html) !== '' ? self::htmlLines((string) $html) : self::jsonLines($doc);
        $problems = ['content rejected by the mail validator, imported as plain text paragraphs'];
        $content = [];
        $bytes = 0;
        foreach ($lines as $line) {
            $bytes += strlen($line);
            if ($bytes > self::FALLBACK_MAX_BYTES) {
                $problems[] = 'plain text over '.self::FALLBACK_MAX_BYTES.' bytes, cut';
                break;
            }
            $content[] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $line]]];
        }
        try {
            return [MailContentValidator::validate(['type' => 'doc', 'content' => $content]), $problems];
        } catch (InvalidArgumentException) {
            return [['type' => 'doc', 'content' => []], [...$problems, 'plain text unusable, imported empty']];
        }
    }

    /**
     * @param  array<mixed>  $node
     * @param  list<string>  $problems
     * @return array<mixed>|null
     */
    private static function rewriteNodes(array $node, Closure $href, Closure $src, array &$problems): ?array
    {
        if (($node['type'] ?? null) === 'image') {
            $new = is_array($node['attrs'] ?? null) && is_string($node['attrs']['src'] ?? null) ? $src($node['attrs']['src']) : null;
            if ($new === null) {
                $problems[] = 'image that is no rank badge of this instance removed';

                return null;
            }
            $node['attrs']['src'] = $new;
        }
        if (is_array($node['marks'] ?? null)) {
            $marks = [];
            foreach ($node['marks'] as $mark) {
                if (is_array($mark) && ($mark['type'] ?? null) === 'link' && is_array($mark['attrs'] ?? null) && is_string($mark['attrs']['href'] ?? null)) {
                    $new = $href($mark['attrs']['href']);
                    if ($new === null) {
                        $problems[] = 'link without a usable target removed, text kept';

                        continue;
                    }
                    $mark['attrs'] = ['href' => $new];
                }
                $marks[] = $mark;
            }
            $node['marks'] = $marks;
        }
        if (is_array($node['content'] ?? null) && array_is_list($node['content'])) {
            $children = [];
            foreach ($node['content'] as $child) {
                $child = is_array($child) ? self::rewriteNodes($child, $href, $src, $problems) : $child;
                if ($child !== null) {
                    $children[] = $child;
                }
            }
            $node['content'] = $children;
        }

        return $node;
    }

    /** @return list<string> the legacy body_html as lines of plain text (block ends and breaks end a line) */
    private static function htmlLines(string $html): array
    {
        $spaced = (string) preg_replace('~<br\s*/?>|</(?:p|li|h[1-6]|blockquote|div|td|th|pre|tr)>~i', "\n", $html);
        $text = html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return self::lines(explode("\n", $text));
    }

    /** @return list<string> the text of each block of an editor document */
    private static function jsonLines(mixed $doc): array
    {
        $texts = [];
        foreach (is_array($doc) && is_array($doc['content'] ?? null) ? $doc['content'] : [] as $child) {
            $texts[] = self::texts($child);
        }

        return self::lines($texts);
    }

    private static function texts(mixed $node): string
    {
        if (! is_array($node)) {
            return '';
        }
        $text = is_string($node['text'] ?? null) ? $node['text'] : '';
        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            $text .= ' '.self::texts($child);
        }

        return $text;
    }

    /**
     * @param  list<string>  $raw
     * @return list<string> single-spaced, valid UTF-8, without empty lines
     */
    private static function lines(array $raw): array
    {
        $lines = [];
        foreach ($raw as $line) {
            $line = trim((string) preg_replace('/[\p{Z}\s]+/u', ' ', mb_scrub($line, 'UTF-8')));
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function href(string $href): ?string
    {
        $path = preg_match('~^https?://~i', $href) === 1 ? LegacyLinkRewriter::localPath($href, $this->legacyHost)
            : (str_starts_with($href, '/') && ! str_starts_with($href, '//') ? $href : null);
        if ($path === null) {
            return $href;
        }
        [$link, $outcome] = $this->links->notificationLink($path);
        if ($link === null) {
            return null;
        }
        if ($outcome === 'unmapped') {
            $this->hookProblems[] = 'links to records that were not imported now open the list page';
        } elseif ($outcome === 'kept') {
            $this->hookProblems[] = 'links to legacy pages kept unchanged (they may carry legacy record ids)';
        }

        return rtrim((string) config('app.url'), '/').$link;
    }

    private function imageSrc(string $src): ?string
    {
        $path = $src;
        if (preg_match('~^https?://~i', $src) === 1) {
            $path = LegacyLinkRewriter::localPath($src, $this->legacyHost);
        } elseif (str_starts_with($src, '//')) {
            $path = null;
        }
        if ($path === null) {
            return null;
        }
        $path = explode('#', explode('?', $path, 2)[0], 2)[0];
        $path = LegacyLinkRewriter::withoutBasePath(str_starts_with($path, '/') ? $path : '/'.$path);

        return $this->badges[self::badgeKey($path)] ?? null;
    }

    private static function badgeKey(string $path): string
    {
        $path = ltrim((string) preg_replace('~\?v=\d{1,12}$~D', '', $path), '/');

        return str_starts_with($path, 'public/') ? substr($path, 7) : $path;
    }

    // ---------------------------------------------------------------- deliveries

    private function deliveries(): void
    {
        $query = $this->ctx->legacy()->tableWithInstants('intra_mail_deliveries', ['created_at', 'read_at', 'deleted_at']);
        $this->each('mail_delivery', $query, function (object $row, int $id, array &$problems, array $messages) {
            $message = $messages[(string) $row->message_id] ?? null;
            $mailbox = $this->mailboxes[(string) $row->mailbox_id] ?? null;
            if ($message === null || $mailbox === null) {
                return 'message or mailbox not imported';
            }
            foreach (['role' => self::ROLES, 'folder' => self::FOLDERS] as $column => $allowed) {
                if (! in_array((string) $row->{$column}, $allowed, true)) {
                    $this->warn["mail_delivery(s): unknown {$column}, row not imported"][] = $id;

                    throw new RuntimeException("unknown {$column}");
                }
            }
            $created = LegacyClock::instant($row, 'created_at') ?? now()->toDateTimeString();
            DB::table('mail_deliveries')->upsert([[
                'message_id' => $message->id,
                'mailbox_id' => $mailbox,
                'role' => (string) $row->role,
                'folder' => (string) $row->folder,
                'read_at' => $this->wall($row, 'read_at'),
                'is_flagged' => (bool) $row->flagged,
                'deleted_at' => $this->wall($row, 'deleted_at'),
                'sorted_at' => $message->sent_at ?? $message->updated_at ?? $message->created_at,
                'created_at' => $created,
                'updated_at' => $created,
            ]], ['message_id', 'mailbox_id', 'role'], ['folder', 'read_at', 'is_flagged', 'deleted_at', 'sorted_at', 'created_at', 'updated_at']);

            return null;
        }, fn (Collection $rows) => $this->messagesOf($rows));
    }

    /**
     * The imported messages of a block (legacy id => id and times), one query.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @return array<string, object>
     */
    private function messagesOf(Collection $rows): array
    {
        $ids = $rows->pluck('message_id')->map(fn ($v) => (string) $v)->unique()->values()->all();

        return DB::table('legacy_id_map')->join('mail_messages', 'mail_messages.id', '=', 'legacy_id_map.new_id')
            ->where('legacy_id_map.entity', 'mail_message')->whereIn('legacy_id_map.legacy_id', $ids)
            ->get(['legacy_id_map.legacy_id', 'mail_messages.id', 'mail_messages.sent_at', 'mail_messages.updated_at', 'mail_messages.created_at'])
            ->keyBy(fn ($m) => (string) $m->legacy_id)->all();
    }

    // ---------------------------------------------------------------- attachments

    private function attachments(): void
    {
        $uploads = $this->ctx->uploadsDir();
        $query = $this->ctx->legacy()->tableWithInstants('intra_mail_attachments', ['created_at']);
        if ($uploads === null) {
            $count = $this->ctx->legacy()->table('intra_mail_attachments')->count();
            if ($count > 0) {
                $this->ctx->report()->read('mail_attachment', $count);
                $this->ctx->report()->skippedCount('mail_attachment', $count);
                $this->ctx->report()->note(self::ENTITY.": {$count} attachment(s) not imported (only with --uploads).");
            }

            return;
        }
        $base = realpath($uploads.'/'.self::LEGACY_DIR);
        if ($base === false) {
            $this->ctx->report()->warn('mail attachments: folder '.self::LEGACY_DIR.' missing below --uploads, every attachment is imported as missing.');
        }
        $disk = Storage::disk(AttachmentStorage::DISK);
        $named = [];

        $this->each('mail_attachment', $query, function (object $row, int $id, array &$problems, array $messages) use ($base, $disk, &$named) {
            $message = $messages[(string) $row->message_id] ?? null;
            if ($message === null) {
                return 'message not imported';
            }
            $legacyMime = (string) $row->mime;
            $values = [
                'message_id' => $message->id,
                'disk' => AttachmentStorage::DISK,
                'original_name' => AttachmentStorage::sanitizeName((string) $row->original_name),
                'size' => (int) $row->size,
                'created_at' => LegacyClock::instant($row, 'created_at') ?? now()->toDateTimeString(),
            ];
            $values['updated_at'] = $values['created_at'];

            $name = preg_match(self::LEGACY_FILE, (string) $row->path, $m) === 1 ? $m[1] : null;
            if ($name !== null) {
                $named[$name] = true;
            }
            [$file, $why] = $this->legacyFile($base, $name);
            $mime = null;
            if ($file !== null) {
                $size = (int) filesize($file);
                $mime = @(new finfo(FILEINFO_MIME_TYPE))->file($file);
                if ($size > AttachmentStorage::MAX_FILE_BYTES) {
                    [$file, $why] = [null, 'file over 5 MB'];
                } elseif (! is_string($mime) || ! isset(AttachmentStorage::ALLOWED[$mime])) {
                    [$file, $why] = [null, 'file type (magic bytes) not allowed'];
                } else {
                    $values['size'] = $size;
                    if ($mime !== $legacyMime) {
                        $problems[] = 'stored type differs from the file content, the detected type is used';
                    }
                }
            }
            if ($file === null) {
                $earlier = DB::table('mail_attachments')->where('id', $this->ctx->ids()->get('mail_attachment', $id) ?? 0)
                    ->where('missing', false)->where('disk', AttachmentStorage::DISK)->value('path');
                if (is_string($earlier) && is_file($disk->path(basename($earlier)))) {
                    $problems[] = "{$why}: the copy of an earlier run is kept";

                    return null;
                }
                $problems[] = "{$why}: imported as missing (download 404)";

                $this->ctx->ids()->write('mail_attachment', $id, 'mail_attachments', $values + [
                    'path' => $name ?? 'missing-'.$id,
                    'mime' => isset(AttachmentStorage::ALLOWED[$legacyMime]) ? $legacyMime : 'application/octet-stream',
                    'missing' => true,
                ]);

                return null;
            }
            /** @var string $mime */
            $values['original_name'] = AttachmentStorage::nameFor($values['original_name'], $mime);
            $values['mime'] = $mime;
            $values['missing'] = false;
            $values['path'] = $this->copy($id, (int) $message->id, $file, $mime, $disk);

            try {
                $this->ctx->ids()->write('mail_attachment', $id, 'mail_attachments', $values);
            } catch (Throwable $e) {
                if (! $this->ctx->dryRun() && ! $this->sameFile($disk, $values['path'], $file)) {
                    $disk->delete($values['path']);
                }
                throw $e;
            }

            return null;
        }, fn (Collection $rows) => $this->messagesOf($rows));

        if ($base !== false) {
            $orphans = 0;
            foreach (scandir($base) ?: [] as $entry) {
                if (preg_match('/^mail-\d{1,18}-[0-9a-f]{16}\.[a-z0-9]{1,10}$/D', $entry) === 1 && ! isset($named[$entry])) {
                    $orphans++;
                }
            }
            if ($orphans > 0) {
                $this->ctx->report()->warn("mail attachments: {$orphans} file(s) in ".self::LEGACY_DIR.' without a row, not copied.');
            }
        }
    }

    /**
     * The legacy file of an attachment, or null with the reason: our naming pattern, a regular file whose real path lies
     * directly inside the legacy folder (no symlink out, no traversal).
     *
     * @return array{0: ?string, 1: string}
     */
    private function legacyFile(string|false $base, ?string $name): array
    {
        if ($name === null) {
            return [null, 'path does not follow the pattern mail-<id>-<16 hex>.<ext>'];
        }
        if ($base === false) {
            return [null, 'legacy folder missing'];
        }
        $file = realpath($base.DIRECTORY_SEPARATOR.$name);
        if ($file === false || ! is_file($file)) {
            return [null, 'file missing'];
        }
        if (dirname($file) !== $base) {
            return [null, 'file outside the legacy folder'];
        }

        return [$file, ''];
    }

    private function copy(int $legacyId, int $messageId, string $file, string $mime, Filesystem $disk): string
    {
        $previous = DB::table('mail_attachments')->where('id', $this->ctx->ids()->get('mail_attachment', $legacyId) ?? 0)
            ->where('missing', false)->where('disk', AttachmentStorage::DISK)->value('path');
        if (is_string($previous) && $this->sameFile($disk, $previous, $file)) {
            return $previous;
        }
        $path = AttachmentStorage::fileName($messageId, AttachmentStorage::ALLOWED[$mime]);
        if ($this->ctx->dryRun()) {
            return $path;
        }
        File::ensureDirectoryExists($disk->path(''));
        if (! @copy($file, $disk->path($path))) {
            throw new RuntimeException('copy failed');
        }

        return $path;
    }

    private function sameFile(Filesystem $disk, string $path, string $file): bool
    {
        $target = $disk->path(basename($path));

        return is_file($target) && filesize($target) === filesize($file) && hash_file('sha256', $target) === hash_file('sha256', $file);
    }

    // ---------------------------------------------------------------- signatures

    private function signatures(): void
    {
        $this->each('mail_signature', $this->ctx->legacy()->tableWithInstants('intra_mail_signatures', ['created_at', 'updated_at']), function (object $row, int $id, array &$problems) {
            $mailbox = $this->mailboxes[(string) $row->mailbox_id] ?? null;
            if ($mailbox === null) {
                return 'mailbox not imported';
            }
            $doc = json_decode((string) $row->body_json, true);
            [$body, $signatureProblems] = $this->signature(is_array($doc) ? $doc : ['type' => 'doc', 'content' => []]);
            array_push($problems, ...$signatureProblems);
            $created = LegacyClock::instant($row, 'created_at') ?? now()->toDateTimeString();
            DB::table('mail_signatures')->upsert([['mailbox_id' => $mailbox, 'body' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $created, 'updated_at' => $this->wall($row, 'updated_at') ?? $created]], ['mailbox_id'], ['body', 'created_at', 'updated_at']);

            return null;
        });
    }

    /**
     * A legacy signature template: links to the legacy instance rewritten like in message bodies (href()), then
     * SignatureTemplate::parse(); refused => one paragraph of plain text per block.
     *
     * @param  array<mixed>  $doc
     * @return array{0: array{type: 'doc', content: list<array<string, mixed>>}, 1: list<string>}
     */
    private function signature(array $doc): array
    {
        $this->hookProblems = [];
        $linkProblems = [];
        $doc = self::rewriteNodes($doc, $this->href(...), fn (string $src) => null, $linkProblems) ?? [];
        $linkProblems = array_values(array_unique([...$linkProblems, ...$this->hookProblems]));
        try {
            return [SignatureTemplate::parse((string) json_encode($doc)), $linkProblems];
        } catch (InvalidArgumentException) {
            $content = array_map(fn (string $line) => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => mb_strcut($line, 0, 4000)]]],
                array_slice(self::jsonLines($doc), 0, 20));

            return [SignatureTemplate::parse((string) json_encode(['type' => 'doc', 'content' => $content])), [...$linkProblems, 'signature refused by the template rules, imported as plain text']];
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Imports a legacy table by id in blocks of CHUNK: one transaction per block, a savepoint per row. $write returns
     * null (written) or why the row was skipped; it may throw (the row fails alone). Its problems count only when written.
     * $prepare loads what a block needs in one query and is handed to $write.
     *
     * @param  Closure(object, int, list<string>&, array<mixed>): ?string  $write
     * @param  ?Closure(Collection<int, \stdClass>): array<mixed>  $prepare
     */
    private function each(string $entity, Builder $query, Closure $write, ?Closure $prepare = null): void
    {
        $report = $this->ctx->report();
        $query->chunkById(self::CHUNK, function (Collection $rows) use ($entity, $write, $prepare, $report) {
            $context = $prepare === null ? [] : $prepare($rows);
            DB::transaction(function () use ($rows, $entity, $write, $context, $report) {
                foreach ($rows as $row) {
                    $report->read($entity);
                    $id = (int) $row->id;
                    $problems = [];
                    try {
                        $skip = DB::transaction(function () use ($write, $row, $id, &$problems, $context) {
                            return $write($row, $id, $problems, $context);
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
     * legacy id => new id of $entity, only rows that still exist.
     *
     * @return array<int|string, int>
     */
    private function existing(string $entity, string $table): array
    {
        return DB::table('legacy_id_map')->join($table, "{$table}.id", '=', 'legacy_id_map.new_id')->where('legacy_id_map.entity', $entity)
            ->pluck("{$table}.id", 'legacy_id_map.legacy_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * The new account for a legacy account id; null (and a warning) when it was not imported or its mapped account no
     * longer exists (stale legacy_id_map entry).
     *
     * @param  list<string>  $problems
     */
    private function user(mixed $legacy, string $what, array &$problems): ?int
    {
        if ($legacy === null || (int) $legacy <= 0) {
            return null;
        }
        $user = $this->users[(string) $legacy] ?? null;
        if ($user === null) {
            $problems[] = "{$what} not imported, left empty";
        }

        return $user;
    }

    private function wall(object $row, string $column): ?string
    {
        $seconds = $row->{$column.'__unix'} ?? null;
        if ($seconds === null || (float) $seconds <= 0) {
            return null;
        }

        return LegacyClock::toApp(Carbon::createFromTimestamp((int) $seconds, $this->db->zone)->format('Y-m-d H:i:s'));
    }
}
