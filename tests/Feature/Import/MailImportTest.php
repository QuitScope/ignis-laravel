<?php

use App\Import\NotificationImporter;
use App\Models\Personnel;
use App\Models\Role;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Modules\Mail\Models\Attachment;
use Modules\Mail\Models\Mailbox;
use Modules\Mail\Models\MailList;
use Modules\Mail\Models\MailMessage;
use Modules\Mail\Models\MailSignature;
use Modules\Mail\Support\MailboxProvisioner;
use Tests\Support\LegacyDatabase;
use Tests\Support\LegacySeed;

require_once __DIR__.'/../Mail/mailHelpers.php';

uses(RefreshDatabase::class)->group('legacy');

beforeEach(function () {
    if (! LegacySeed::boot()) {
        $this->markTestSkipped('LEGACY_TEST_DB_HOST not set');
    }
    $this->seed(RoleSeeder::class);
    config(['ignis.import.legacy_timezone' => 'America/New_York', 'ignis.import.legacy_db_timezone' => 'UTC',
        'ignis.import.legacy_base_path' => '/intra', 'app.url' => 'https://neu.example']);
    Storage::fake('mail-attachments');
});

afterEach(function () {
    if (isset($this->uploads)) {
        File::deleteDirectory($this->uploads);
    }
});

function mmi_seed(): void
{
    LegacySeed::config(['SYSTEM_URL' => 'https://intra.example.com']);
    LegacySeed::personnel([['id' => 1, 'fullname' => 'Anna Arzt'], ['id' => 2, 'fullname' => 'Otto Org'], ['id' => 3, 'fullname' => 'Neu Ohne']]);
    LegacySeed::users([['id' => 1, 'aktenid' => 1], ['id' => 2, 'aktenid' => 2], ['id' => 3]]);
    LegacySeed::mail('mailboxes', [
        ['id' => 1, 'mitarbeiter_id' => 1, 'user_id' => 1, 'address' => 'anna.arzt@ignis.ef', 'display_name' => 'Anna Arzt'],
        ['id' => 2, 'mitarbeiter_id' => 2, 'user_id' => 9, 'address' => 'o.org@ignis.ef', 'display_name' => 'Otto Org', 'locked' => 1],
        ['id' => 3, 'kind' => 'group', 'address' => 'wache@ignis.ef', 'display_name' => 'Wache'],
        ['id' => 4, 'address' => 'ex.person@ignis.ef', 'display_name' => 'Ex Person'],
    ]);
    LegacySeed::mail('mailbox_members', [['id' => 1, 'mailbox_id' => 3, 'user_id' => 1], ['id' => 2, 'mailbox_id' => 3, 'user_id' => 2], ['id' => 3, 'mailbox_id' => 3, 'user_id' => 9]]);
    LegacySeed::mail('address_history', [['id' => 1, 'address' => 'anna.alt@ignis.ef', 'mailbox_id' => 1]]);
    LegacySeed::mail('lists', [
        ['id' => 1, 'address' => 'alle@ignis.ef'],
        ['id' => 2, 'address' => 'rd@ignis.ef', 'kind' => 'dynamic', 'senders' => 'managers',
            'rule' => '{"role_ids":[2],"rank_ids":[10,12],"rd_quali_ids":[31],"fw_quali_ids":[99],"fachdienst_ids":[40]}'],
        ['id' => 3, 'address' => 'leer@ignis.ef', 'kind' => 'dynamic', 'rule' => '{"rank_ids":[77]}'],
    ]);
    LegacySeed::mail('list_members', [['id' => 1, 'list_id' => 1, 'mailbox_id' => 1], ['id' => 2, 'list_id' => 1, 'mailbox_id' => 2], ['id' => 3, 'list_id' => 1, 'mailbox_id' => 99]]);
    $doc = fn (array $inline) => json_encode(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => $inline]]]);
    LegacySeed::mail('messages', [
        ['id' => 1, 'sender_mailbox_id' => 1, 'sent_by_user_id' => 1, 'subject' => 'Lage', 'sent_at' => '2026-01-15 10:00:00',
            'header_json' => '{"to":["o.org@ignis.ef"],"cc":[],"bcc":["wache@ignis.ef"]}', 'thread_id' => str_repeat('a', 32),
            'body_json' => $doc([
                ['type' => 'image', 'attrs' => ['src' => '/intra/b.png?v=123', 'alt' => '']],
                ['type' => 'text', 'text' => 'Akte', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://intra.example.com/intra/personnel/profile?id=2']]]],
                ['type' => 'image', 'attrs' => ['src' => 'https://fremd.example/x.png', 'alt' => '']],
                ['type' => 'text', 'text' => ' bitte lesen'],
            ])],
        ['id' => 2, 'sender_mailbox_id' => 3, 'sent_by_user_id' => 2, 'in_reply_to' => 1, 'thread_id' => str_repeat('a', 32),
            'body_json' => '{"type":"doc","content":[{"type":"heading","content":[{"type":"text","text":"Plan"}]}]}', 'body_html' => '<h2>Plan</h2><p>Zeile &amp; mehr</p>'],
        ['id' => 3, 'sender_mailbox_id' => 2, 'sent_by_user_id' => 50, 'in_reply_to' => 4, 'thread_id' => str_repeat('x', 40)],
        ['id' => 4, 'sender_mailbox_id' => 1, 'status' => 'draft', 'sent_at' => null, 'body_html' => null],
        ['id' => 5, 'sender_mailbox_id' => 99],
    ]);
    LegacySeed::mail('deliveries', [
        ['id' => 1, 'message_id' => 1, 'mailbox_id' => 1, 'role' => 'sender', 'folder' => 'sent', 'read_at' => '2026-07-15 11:00:00'],
        ['id' => 2, 'message_id' => 1, 'mailbox_id' => 2, 'role' => 'to', 'folder' => 'archive', 'flagged' => 1, 'read_at' => '2026-07-15 11:00:00'],
        ['id' => 3, 'message_id' => 1, 'mailbox_id' => 3, 'role' => 'bcc', 'folder' => 'trash', 'deleted_at' => '2026-07-16 09:00:00'],
        ['id' => 4, 'message_id' => 2, 'mailbox_id' => 1, 'role' => 'to', 'folder' => 'inbox'],
        ['id' => 5, 'message_id' => 3, 'mailbox_id' => 1, 'role' => 'xx', 'folder' => 'inbox'],
        ['id' => 6, 'message_id' => 5, 'mailbox_id' => 1],
        ['id' => 7, 'message_id' => 4, 'mailbox_id' => 1, 'role' => 'sender', 'folder' => 'drafts'],
        ['id' => 8, 'message_id' => 3, 'mailbox_id' => 1, 'role' => 'to', 'folder' => 'spam'],
    ]);
    LegacySeed::mail('signatures', [
        ['id' => 1, 'mailbox_id' => 1, 'body_json' => '{"type":"doc","content":[{"type":"paragraph","content":[{"type":"docVariable","attrs":{"name":"absender.name"}}]}]}'],
        ['id' => 2, 'mailbox_id' => 3, 'body_json' => '{"type":"doc","content":[{"type":"heading","content":[{"type":"text","text":"Die Wache"}]}]}'],
    ]);
    LegacySeed::config(['MAIL_DOMAIN' => ' Ignis.EF ', 'MAIL_ADDRESS_PATTERN' => 'first_dot_last', 'MAIL_ALLOWED_DOMAINS' => 'wache.ef, kaputt domain ,extra.ef',
        'MAIL_SEND_COOLDOWN' => '5000', 'MAIL_DEFAULT_SIGNATURE' => "Mit Gruß\nDie Wache"]);
}

function mmi_id(string $entity, int $legacyId): ?int
{
    $id = DB::table('legacy_id_map')->where(['entity' => $entity, 'legacy_id' => (string) $legacyId])->value('new_id');

    return $id === null ? null : (int) $id;
}

function mmi_box(int $legacyId): Mailbox
{
    return Mailbox::findOrFail(mmi_id('mail_mailbox', $legacyId));
}

function mmi_msg(int $legacyId): MailMessage
{
    return MailMessage::findOrFail(mmi_id('mail_message', $legacyId));
}

/** @return array<string, mixed> */
function mmi_dump(): array
{
    return collect(['mail_mailboxes', 'mail_mailbox_members', 'mail_address_history', 'mail_lists', 'mail_list_members', 'mail_messages',
        'mail_deliveries', 'mail_attachments', 'mail_signatures', 'legacy_id_map', 'settings'])
        ->mapWithKeys(fn (string $t) => [$t => DB::table($t)->get()->map(fn ($r) => (array) $r)->sortBy(fn ($r) => json_encode($r))->values()->all()])->all();
}

/** @param array<string, mixed> $json */
function mmi_warning(array $json, string $needle): string
{
    return (string) collect($json['warnings'])->first(fn (string $w) => str_contains($w, $needle));
}

it('imports mailboxes, lists, messages, copies, signatures and settings', function () {
    mmi_seed();
    $json = LegacySeed::run(['mail']);

    expect($json['exit'])->toBe(1)
        ->and($json['entities']['mail_mailbox'])->toBe(['read' => 4, 'written' => 4, 'skipped' => 0, 'failed' => 0])
        ->and($json['entities']['mail_mailbox_member'])->toBe(['read' => 3, 'written' => 2, 'skipped' => 1, 'failed' => 0])
        ->and($json['entities']['mail_list'])->toBe(['read' => 3, 'written' => 3, 'skipped' => 0, 'failed' => 0])
        ->and($json['entities']['mail_list_member'])->toBe(['read' => 3, 'written' => 2, 'skipped' => 1, 'failed' => 0])
        ->and($json['entities']['mail_message'])->toBe(['read' => 5, 'written' => 4, 'skipped' => 1, 'failed' => 0])
        ->and($json['entities']['mail_delivery'])->toBe(['read' => 8, 'written' => 5, 'skipped' => 1, 'failed' => 2])
        ->and($json['entities']['mail_signature'])->toBe(['read' => 3, 'written' => 3, 'skipped' => 0, 'failed' => 0])
        ->and($json['entities']['mail_attachment'] ?? null)->toBeNull();

    $user = fn (int $legacy) => mmi_id('user', $legacy);
    $file = fn (int $legacy) => mmi_id('personnel', $legacy);

    expect(mmi_box(1)->only(['kind', 'personnel_id', 'user_id', 'address', 'display_name', 'domain', 'is_active', 'is_locked']))
        ->toBe(['kind' => 'personal', 'personnel_id' => $file(1), 'user_id' => $user(1), 'address' => 'anna.arzt@ignis.ef', 'display_name' => 'Anna Arzt', 'domain' => 'ignis.ef', 'is_active' => true, 'is_locked' => false])
        ->and([mmi_box(2)->user_id, mmi_box(2)->is_locked, mmi_box(2)->personnel_id])->toBe([null, true, $file(2)])
        ->and([mmi_box(3)->kind, mmi_box(3)->personnel_id, mmi_box(3)->user_id])->toBe(['group', null, null])
        ->and([mmi_box(4)->is_active, mmi_box(4)->personnel_id])->toBe([false, null])
        ->and(DB::table('mail_mailbox_members')->where('mailbox_id', mmi_box(3)->id)->orderBy('user_id')->pluck('user_id')->all())->toBe([$user(1), $user(2)])
        ->and(DB::table('mail_address_history')->where('address', 'anna.alt@ignis.ef')->value('mailbox_id'))->toBe(mmi_box(1)->id);

    $list = MailList::findOrFail(mmi_id('mail_list', 2));
    expect($list->rule)->toBe(['role' => [mmi_id('role', 2)], 'rank' => collect([mmi_id('rank', 10), mmi_id('rank', 12)])->sort()->values()->all(),
        'ems_qualification' => [mmi_id('ems_qualification', 31)], 'fire_qualification' => [], 'specialty' => [mmi_id('specialty', 40)]])
        ->and($list->senders)->toBe('managers')->and($list->kind)->toBe('dynamic')
        ->and(MailList::findOrFail(mmi_id('mail_list', 3))->rule)->toBe(['role' => [], 'rank' => [], 'ems_qualification' => [], 'fire_qualification' => [], 'specialty' => []])
        ->and(MailList::findOrFail(mmi_id('mail_list', 1))->rule)->toBeNull()
        ->and(MailList::findOrFail(mmi_id('mail_list', 1))->mailboxes()->pluck('mail_mailboxes.id')->sort()->values()->all())->toBe([mmi_box(1)->id, mmi_box(2)->id]);

    $m = mmi_msg(1);
    expect($m->body['content'][0]['content'])->toBe([
        ['type' => 'image', 'attrs' => ['src' => '/b.png', 'alt' => '']],
        ['type' => 'text', 'text' => 'Akte', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://neu.example/personnel/'.$file(2)]]]],
        ['type' => 'text', 'text' => ' bitte lesen'],
    ])->and($m->search_text)->toBe("Lage\nAkte bitte lesen")
        ->and($m->header)->toBe(['to' => ['o.org@ignis.ef'], 'cc' => [], 'bcc' => ['wache@ignis.ef']])
        ->and([$m->sender_mailbox_id, $m->sent_by_user_id, $m->status, $m->thread_id])->toBe([mmi_box(1)->id, $user(1), 'sent', str_repeat('a', 32)])
        ->and(DB::getSchemaBuilder()->hasColumn('mail_messages', 'body_html'))->toBeFalse();
    expect(mmi_msg(2)->body)->toBe(['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Plan']]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Zeile & mehr']]],
    ]])->and(mmi_msg(2)->in_reply_to)->toBe($m->id)->and(mmi_msg(2)->sender_mailbox_id)->toBe(mmi_box(3)->id)
        ->and(mmi_msg(3)->in_reply_to)->toBe(mmi_msg(4)->id)->and(mmi_msg(3)->sent_by_user_id)->toBeNull()
        ->and(mmi_msg(3)->thread_id)->toBe(md5(str_repeat('x', 40)))
        ->and([mmi_msg(4)->status, mmi_msg(4)->sent_at])->toBe(['draft', null])
        ->and(mmi_id('mail_message', 5))->toBeNull();

    $copy = fn (int $msg, int $box, string $role) => DB::table('mail_deliveries')->where(['message_id' => mmi_msg($msg)->id, 'mailbox_id' => mmi_box($box)->id, 'role' => $role])->first();
    expect([$copy(1, 2, 'to')->folder, (bool) $copy(1, 2, 'to')->is_flagged, $copy(1, 2, 'to')->read_at])->toBe(['archive', true, '2026-07-15 15:00:00'])
        ->and([$copy(1, 3, 'bcc')->folder, $copy(1, 3, 'bcc')->deleted_at])->toBe(['trash', '2026-07-16 13:00:00'])
        ->and($copy(1, 1, 'sender')->sorted_at)->toBe('2026-01-15 15:00:00')
        ->and($copy(4, 1, 'sender')->folder)->toBe('drafts')
        ->and(DB::table('mail_deliveries')->where('message_id', mmi_msg(3)->id)->exists())->toBeFalse();

    $sig = fn (int $box) => MailSignature::query()->where('mailbox_id', mmi_box($box)->id)->value('body');
    expect($sig(1))->toBe(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'docVariable', 'attrs' => ['name' => 'absender.name']]]]]])
        ->and($sig(3))->toBe(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Die Wache']]]]])
        ->and(MailSignature::query()->whereNull('mailbox_id')->count())->toBe(1)
        ->and(MailSignature::query()->whereNull('mailbox_id')->value('body'))->toBe(['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Mit Gruß']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Die Wache']]],
        ]]);

    $settings = app(Settings::class);
    $settings->refresh();
    expect([$settings->get('mail.domain'), $settings->get('mail.address_pattern'), $settings->get('mail.allowed_domains'), $settings->get('mail.send_cooldown')])
        ->toBe(['ignis.ef', 'first_dot_last', ['wache.ef', 'extra.ef'], 3600]);

    foreach ([
        'mail_mailbox(s): account not imported' => '(legacy ids: 2)',
        'mail_mailbox_member(s) skipped: member account not imported' => '(legacy ids: 3)',
        'mail_list(s): rule ids that were not imported' => '(legacy ids: 2, 3)',
        'mail_list(s): dynamic list with an empty rule kept' => '(legacy ids: 3)',
        'mail_list_member(s) skipped' => '(legacy ids: 3)',
        'mail_message(s) skipped: sender mailbox not imported' => '(legacy ids: 5)',
        'mail_message(s): image that is no rank badge' => '(legacy ids: 1)',
        'mail_message(s): content rejected by the mail validator' => '(legacy ids: 2)',
        'mail_message(s): writing account not imported' => '(legacy ids: 3)',
        'mail_message(s): thread id longer than 32' => '(legacy ids: 3)',
        'mail_delivery(s): unknown role' => '(legacy ids: 5)',
        'mail_delivery(s): unknown folder' => '(legacy ids: 8)',
        'mail_delivery(s) skipped: message or mailbox not imported' => '(legacy ids: 6)',
        'mail_signature(s): signature refused by the template rules' => '(legacy ids: 2)',
        'MAIL_ALLOWED_DOMAINS: 1 invalid domain(s) dropped' => '',
        'MAIL_SEND_COOLDOWN: over 3600 seconds' => '',
    ] as $needle => $ids) {
        expect(mmi_warning($json, $needle))->not->toBe('', $needle)->toContain($ids);
    }
});

it('reads PHP-written times as wall time of the database clock and created_at as an instant, summer and winter', function () {
    config(['ignis.import.legacy_db_timezone' => 'Europe/Berlin']);
    mmi_seed();
    LegacySeed::run(['mail']);

    expect(mmi_box(1)->getRawOriginal('created_at'))->toBe('2026-07-15 10:00:00')
        ->and(mmi_box(1)->getRawOriginal('updated_at'))->toBe('2026-07-15 16:00:00')
        ->and(mmi_msg(1)->getRawOriginal('sent_at'))->toBe('2026-01-15 16:00:00')
        ->and(mmi_msg(2)->getRawOriginal('sent_at'))->toBe('2026-07-15 16:00:00')
        ->and(mmi_msg(1)->getRawOriginal('created_at'))->toBe('2026-07-15 10:00:00')
        ->and(DB::table('mail_address_history')->where('address', 'anna.alt@ignis.ef')->value('released_at'))->toBe('2026-07-15 16:00:00');
});

it('gives the same result on a second run and leaves no traces on a dry run', function () {
    mmi_seed();
    $before = mmi_dump();
    $dry = LegacySeed::run(['mail'], dry: true);
    expect($dry['dry_run'])->toBeTrue()->and($dry['entities']['mail_message']['written'])->toBe(4)
        ->and(array_diff_key(mmi_dump(), ['legacy_id_map' => 1, 'settings' => 1]))->toBe(array_diff_key($before, ['legacy_id_map' => 1, 'settings' => 1]))
        ->and(DB::table('legacy_id_map')->where('entity', 'like', 'mail%')->exists())->toBeFalse();

    $first = LegacySeed::run(['mail']);
    $dump = mmi_dump();
    $second = LegacySeed::run(['mail']);

    expect(mmi_dump())->toBe($dump)
        ->and(array_values(preg_grep('/^\d+ mail/', $second['warnings'])))->toBe(array_values(preg_grep('/^\d+ mail/', $first['warnings'])));

    mmi_msg(2)->delete();
    LegacySeed::run(['mail']);
    expect(mmi_msg(2)->in_reply_to)->toBe(mmi_msg(1)->id)->and(DB::table('mail_deliveries')->where('message_id', mmi_msg(2)->id)->count())->toBe(1);
});

it('adopts a mailbox the new instance provisioned and moves a new mailbox off a legacy address', function () {
    mmi_seed();
    LegacySeed::run(['account_link']);
    $provisioned = app(MailboxProvisioner::class)->sync(mmi_id('personnel', 1));
    $generated = $provisioned->address;
    $own = ml_message($provisioned, []);
    $squatter = ml_group('Neue Gruppe', 'o.org');

    $json = LegacySeed::run(['mail']);

    expect(mmi_box(1)->id)->toBe($provisioned->id)
        ->and(mmi_box(1)->address)->toBe('anna.arzt@ignis.ef')
        ->and(MailMessage::query()->whereKey($own->id)->exists())->toBeTrue()
        ->and(DB::table('mail_address_history')->where('address', $generated)->value('mailbox_id'))->toBe($provisioned->id)
        ->and(mmi_box(2)->address)->toBe('o.org@ignis.ef')
        ->and($squatter->refresh()->address)->toBe('o.org2@ignis.ef')
        ->and(mmi_warning($json, 'already provisioned for the record adopted'))->toContain('(legacy ids: 1)')
        ->and(mmi_warning($json, 'which got a new address'))->toContain('(legacy ids: 2)');
});

it('treats a stale account map entry as an account that was not imported', function () {
    mmi_seed();
    DB::table('legacy_id_map')->insert(['entity' => 'user', 'legacy_id' => '50', 'new_id' => 999999]);
    $legacy = LegacyDatabase::connection();
    $legacy->statement('SET FOREIGN_KEY_CHECKS=0');
    $legacy->table('intra_mail_mailboxes')->where('id', 1)->update(['user_id' => 50]);
    $legacy->table('intra_mail_mailbox_members')->where('id', 1)->update(['user_id' => 50]);
    $legacy->statement('SET FOREIGN_KEY_CHECKS=1');

    $json = LegacySeed::run(['mail']);

    expect(mmi_box(1)->user_id)->toBeNull()->and(mmi_msg(3)->sent_by_user_id)->toBeNull()
        ->and($json['entities']['mail_mailbox']['failed'])->toBe(0)
        ->and(mmi_warning($json, 'mail_mailbox(s): account not imported'))->toContain('1')
        ->and(mmi_warning($json, 'mail_mailbox_member(s) skipped: member account not imported'))->toContain('1, 3');
});

it('copies attachments with --uploads and keeps rows of missing or refused files', function () {
    mmi_seed();
    $this->uploads = sys_get_temp_dir().'/ignis-mail-'.bin2hex(random_bytes(4));
    $dir = $this->uploads.'/storage/private/mail-attachments';
    File::ensureDirectoryExists($dir);
    file_put_contents("$dir/mail-1-0123456789abcdef.txt", 'Hallo Wache');
    file_put_contents("$dir/mail-1-aaaaaaaaaaaaaaaa.png", "%PDF-1.4\n%âãÏÓ\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");
    file_put_contents("$dir/mail-1-cccccccccccccccc.txt", str_repeat('a', 5 * 1024 * 1024 + 1));
    file_put_contents("$dir/mail-1-dddddddddddddddd.html", '<html><body><script>alert(1)</script></body></html>');
    file_put_contents("$dir/mail-9-eeeeeeeeeeeeeeee.txt", 'ohne Zeile');
    file_put_contents($this->uploads.'/storage/geheim.txt', 'nicht kopieren');
    LegacySeed::mail('attachments', [
        ['id' => 1, 'message_id' => 1, 'path' => 'storage/private/mail-attachments/mail-1-0123456789abcdef.txt', 'original_name' => 'wache.txt', 'size' => 11],
        ['id' => 2, 'message_id' => 1, 'path' => 'storage/private/mail-attachments/mail-1-aaaaaaaaaaaaaaaa.png', 'original_name' => 'bild.png', 'mime' => 'image/png'],
        ['id' => 3, 'message_id' => 1, 'path' => 'storage/private/mail-attachments/mail-1-bbbbbbbbbbbbbbbb.txt', 'original_name' => 'weg.txt', 'size' => 7],
        ['id' => 4, 'message_id' => 1, 'path' => 'storage/private/mail-attachments/mail-1-cccccccccccccccc.txt', 'original_name' => 'gross.txt'],
        ['id' => 5, 'message_id' => 1, 'path' => 'storage/private/mail-attachments/../geheim.txt', 'original_name' => 'x.txt'],
        ['id' => 6, 'message_id' => 1, 'path' => 'storage/private/mail-attachments/mail-1-dddddddddddddddd.html', 'original_name' => 'seite.html', 'mime' => 'text/html'],
        ['id' => 7, 'message_id' => 5, 'path' => 'storage/private/mail-attachments/mail-5-ffffffffffffffff.txt'],
    ]);
    $disk = Storage::disk('mail-attachments');

    $dry = LegacySeed::run(['mail'], dry: true, uploads: $this->uploads);
    expect($dry['entities']['mail_attachment'])->toBe(['read' => 7, 'written' => 6, 'skipped' => 1, 'failed' => 0])
        ->and($disk->allFiles())->toBe([])->and(Attachment::query()->count())->toBe(0);

    $json = LegacySeed::run(['mail'], uploads: $this->uploads);
    $a = fn (int $legacy) => Attachment::findOrFail(mmi_id('mail_attachment', $legacy));

    expect($json['entities']['mail_attachment'])->toBe(['read' => 7, 'written' => 6, 'skipped' => 1, 'failed' => 0])
        ->and($a(1)->only(['message_id', 'disk', 'original_name', 'mime', 'size', 'missing']))
        ->toBe(['message_id' => mmi_msg(1)->id, 'disk' => 'mail-attachments', 'original_name' => 'wache.txt', 'mime' => 'text/plain', 'size' => 11, 'missing' => false])
        ->and($a(1)->path)->toMatch('/^mail-'.mmi_msg(1)->id.'-[0-9a-f]{16}\.txt$/')->not->toBe('mail-1-0123456789abcdef.txt')
        ->and($disk->get($a(1)->path))->toBe('Hallo Wache')
        ->and([$a(2)->mime, $a(2)->original_name, $a(2)->missing])->toBe(['application/pdf', 'bild.pdf', false])
        ->and(str_ends_with($a(2)->path, '.pdf'))->toBeTrue()
        ->and([$a(3)->missing, $a(4)->missing, $a(5)->missing, $a(6)->missing])->toBe([true, true, true, true])
        ->and($a(6)->mime)->toBe('application/octet-stream')
        ->and(count($disk->allFiles()))->toBe(2);

    foreach ([
        'mail_attachment(s): stored type differs' => '(legacy ids: 2)',
        'mail_attachment(s): file missing: imported as missing' => '(legacy ids: 3)',
        'mail_attachment(s): file over 5 MB' => '(legacy ids: 4)',
        'mail_attachment(s): path does not follow the pattern' => '(legacy ids: 5)',
        'mail_attachment(s): file type (magic bytes) not allowed' => '(legacy ids: 6)',
        'mail attachments: 1 file(s) in storage/private/mail-attachments without a row' => '',
    ] as $needle => $ids) {
        expect(mmi_warning($json, $needle))->not->toBe('', $needle)->toContain($ids);
    }

    $dump = mmi_dump();
    LegacySeed::run(['mail'], uploads: $this->uploads);
    expect(mmi_dump())->toBe($dump)->and(count($disk->allFiles()))->toBe(2);

    $copied = $a(1)->path;
    unlink("$dir/mail-1-0123456789abcdef.txt");
    $gone = LegacySeed::run(['mail'], uploads: $this->uploads);
    $other = $this->uploads.'-ohne';
    File::ensureDirectoryExists($other.'/storage/profile-pictures');
    try {
        LegacySeed::run(['mail'], uploads: $other);
    } finally {
        File::deleteDirectory($other);
    }
    expect([$a(1)->missing, $a(1)->path, $a(2)->missing])->toBe([false, $copied, false])
        ->and($disk->get($copied))->toBe('Hallo Wache')
        ->and(mmi_warning($gone, 'file missing: the copy of an earlier run is kept'))->toContain('(legacy ids: 1)');
});

it('rewrites links to the legacy instance in signatures and the default signature', function () {
    mmi_seed();
    $link = fn (string $text, string $href) => ['type' => 'text', 'text' => $text, 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]];
    $doc = fn (array ...$inline) => json_encode(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => $inline]]]);
    LegacyDatabase::connection()->table('intra_mail_signatures')->where('id', 1)->update(['body_json' => $doc(
        $link('Akte', 'https://intra.example.com/intra/personnel/profile?id=1'), $link(' Wiki', 'https://intra.example.com/intra/wiki/start'),
        $link(' Extern', 'https://extern.example/x'),
    )]);
    LegacyDatabase::connection()->table('intra_config')->where('config_key', 'MAIL_DEFAULT_SIGNATURE')
        ->update(['config_value' => $doc($link('Kalender', 'https://intra.example.com/intra/calendar'))]);

    LegacySeed::run(['mail']);

    $hrefs = fn (?array $body) => collect($body['content'][0]['content'] ?? [])->map(fn ($n) => $n['marks'][0]['attrs']['href'] ?? null)->all();
    expect($hrefs(MailSignature::query()->where('mailbox_id', mmi_box(1)->id)->value('body')))
        ->toBe(['https://neu.example/personnel/'.mmi_id('personnel', 1), 'https://neu.example/wiki/start', 'https://extern.example/x'])
        ->and($hrefs(MailSignature::query()->whereNull('mailbox_id')->value('body')))->toBe(['https://neu.example/calendar']);
});

it('rewrites mail notification links to the new message ids', function () {
    mmi_seed();
    LegacySeed::notifications([
        ['id' => 1, 'type' => 'mail', 'link' => '/intra/mail/inbox/2'],
        ['id' => 2, 'type' => 'mail', 'link' => '/mail/inbox/5'],
        ['id' => 3, 'type' => 'mail', 'link' => 'mail/sent/1'],
    ]);
    LegacySeed::run(['mail', 'notification']);

    $link = fn (int $id) => json_decode((string) DB::table('notifications')->where('id', NotificationImporter::uuid($id))->value('data'), true)['link'];
    expect($link(1))->toBe('/mail/inbox/'.mmi_msg(2)->id)->and($link(2))->toBe('/mail');
});

it('knows the mail permissions when importing roles', function () {
    LegacySeed::roles([['id' => 4, 'name' => 'Mailer', 'permissions' => '["mail.use","mail.lists.manage","mail.domain.choose","mail.admin"]']]);
    $json = LegacySeed::run(['role']);

    expect(Role::findByName('Mailer')->permissions->pluck('name')->sort()->values()->all())->toBe(['mail.admin', 'mail.domain.choose', 'mail.lists.manage', 'mail.use'])
        ->and(collect($json['warnings'])->filter(fn (string $w) => str_contains($w, 'mail.'))->all())->toBe([]);
});

it('leaves only records without a mailbox to mail:backfill after the import', function () {
    mmi_seed();
    LegacySeed::run(['mail']);
    $before = Mailbox::query()->count();

    Artisan::call('mail:backfill', ['--dry-run' => true]);

    expect(Artisan::output())->toContain('3 synced, 1 created, 0 without mailbox, 0 deactivated')
        ->and(Mailbox::query()->count())->toBe($before)
        ->and(Personnel::query()->count())->toBe(3);
});

it('imports a large stock in blocks of 200 without holding it in memory', function () {
    LegacySeed::personnel([['id' => 1, 'fullname' => 'Anna Arzt']]);
    LegacySeed::mail('mailboxes', [['id' => 1, 'mitarbeiter_id' => 1, 'address' => 'anna.arzt@ignis.ef']]);
    $text = str_repeat('Lagebericht ', 1500);
    $json = json_encode(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]]);
    $rows = [];
    for ($i = 1; $i <= 1000; $i++) {
        $rows[] = ['id' => $i, 'sender_mailbox_id' => 1, 'body_json' => $json, 'body_html' => "<p>{$text}</p>"];
    }
    LegacySeed::mail('messages', $rows);
    unset($rows);

    $selects = [];
    DB::listen(function ($q) use (&$selects) {
        if ($q->connectionName === 'legacy_readonly' && str_starts_with($q->sql, 'select *') && str_contains($q->sql, 'from `intra_mail_messages`')) {
            $selects[] = $q->sql;
        }
    });
    gc_collect_cycles();
    memory_reset_peak_usage();
    $base = memory_get_usage();
    $result = LegacySeed::run(['mail']);
    $peak = memory_get_peak_usage() - $base;

    expect($result['entities']['mail_message'])->toBe(['read' => 1000, 'written' => 1000, 'skipped' => 0, 'failed' => 0])
        ->and(count($selects))->toBeGreaterThanOrEqual(5)
        ->and(collect($selects)->reject(fn (string $sql) => str_contains($sql, 'limit 200'))->values()->all())->toBe([])
        ->and($peak)->toBeLessThan(24 * 1024 * 1024);
});
