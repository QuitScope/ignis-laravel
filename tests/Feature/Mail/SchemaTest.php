<?php

use App\Auth\NoSuperAdminBypass;
use App\Auth\Permissions;
use App\Support\SettingDefinitions;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Modules\Mail\Models\Attachment;
use Modules\Mail\Models\Mailbox;
use Modules\Mail\Models\MailDelivery;
use Modules\Mail\Models\MailMessage;
use Modules\Mail\Models\MailSignature;

require_once __DIR__.'/mailHelpers.php';

uses(RefreshDatabase::class)->group('mariadb');

beforeEach(fn () => $this->seed(RoleSeeder::class));

/** @return list<array{columns: list<string>, unique: bool}> */
function ms_indexes(string $table): array
{
    return array_map(fn ($i) => ['columns' => $i['columns'], 'unique' => (bool) $i['unique']], Schema::getIndexes($table));
}

it('creates the nine tables with the columns of the plan', function () {
    $columns = [
        'mail_mailboxes' => ['kind', 'personnel_id', 'user_id', 'address', 'display_name', 'domain', 'is_active', 'is_locked'],
        'mail_mailbox_members' => ['mailbox_id', 'user_id'],
        'mail_address_history' => ['address', 'mailbox_id', 'released_at'],
        'mail_lists' => ['address', 'name', 'kind', 'rule', 'senders'],
        'mail_list_members' => ['mail_list_id', 'mailbox_id'],
        'mail_messages' => ['sender_mailbox_id', 'sent_by_user_id', 'subject', 'body', 'search_text', 'header', 'thread_id', 'in_reply_to', 'status', 'sent_at'],
        'mail_deliveries' => ['message_id', 'mailbox_id', 'role', 'folder', 'read_at', 'is_flagged', 'deleted_at', 'sorted_at'],
        'mail_attachments' => ['message_id', 'disk', 'path', 'original_name', 'mime', 'size', 'missing'],
        'mail_signatures' => ['mailbox_id', 'body', 'updated_at'],
    ];
    foreach ($columns as $table => $cols) {
        expect(Schema::getColumnListing($table))->toContain(...$cols);
    }
    expect(Schema::getColumnListing('mail_messages'))->not->toContain('body_html');
});

it('has the unique keys and indexes of the plan', function () {
    expect(ms_indexes('mail_mailboxes'))->toContain(['columns' => ['personnel_id'], 'unique' => true], ['columns' => ['user_id'], 'unique' => true], ['columns' => ['address'], 'unique' => true])
        ->and(ms_indexes('mail_mailbox_members'))->toContain(['columns' => ['mailbox_id', 'user_id'], 'unique' => true])
        ->and(ms_indexes('mail_address_history'))->toContain(['columns' => ['address'], 'unique' => true])
        ->and(ms_indexes('mail_lists'))->toContain(['columns' => ['address'], 'unique' => true])
        ->and(ms_indexes('mail_list_members'))->toContain(['columns' => ['mail_list_id', 'mailbox_id'], 'unique' => true])
        ->and(ms_indexes('mail_messages'))->toContain(['columns' => ['sender_mailbox_id', 'status', 'sent_at'], 'unique' => false], ['columns' => ['thread_id'], 'unique' => false])
        ->and(ms_indexes('mail_deliveries'))->toContain(
            ['columns' => ['message_id', 'mailbox_id', 'role'], 'unique' => true],
            ['columns' => ['mailbox_id', 'folder', 'deleted_at', 'sorted_at'], 'unique' => false],
            ['columns' => ['mailbox_id', 'folder', 'read_at', 'deleted_at', 'role'], 'unique' => false],
        )
        ->and(ms_indexes('mail_signatures'))->toContain(['columns' => ['mailbox_id'], 'unique' => true]);
});

it('keeps one copy per message, mailbox and role', function () {
    $a = ml_group('A', 'a');
    $b = ml_group('B', 'b');
    $message = ml_message($a, [[$b, 'to', 'inbox']]);
    MailDelivery::query()->forceCreate(['message_id' => $message->id, 'mailbox_id' => $b->id, 'role' => 'cc', 'folder' => 'inbox']);

    expect(fn () => MailDelivery::query()->forceCreate(['message_id' => $message->id, 'mailbox_id' => $b->id, 'role' => 'to', 'folder' => 'inbox']))
        ->toThrow(QueryException::class);
});

it('cascades and restricts as planned', function () {
    $sender = ml_group('Sender', 'sender');
    $to = ml_group('To', 'to');
    $message = ml_message($sender, [[$to, 'to', 'inbox']]);
    Attachment::query()->forceCreate(['message_id' => $message->id, 'disk' => 'local', 'path' => 'x', 'original_name' => 'x.pdf', 'mime' => 'application/pdf', 'size' => 1]);
    MailSignature::query()->forceCreate(['mailbox_id' => $to->id, 'body' => ['type' => 'doc']]);
    $to->members()->attach(ml_user()->id);

    expect(fn () => DB::table('mail_mailboxes')->where('id', $sender->id)->delete())->toThrow(QueryException::class);
    $to->delete();
    expect(MailDelivery::query()->where('mailbox_id', $to->id)->count())->toBe(0)
        ->and(MailSignature::query()->count())->toBe(0)
        ->and(DB::table('mail_mailbox_members')->count())->toBe(0)
        ->and(MailMessage::query()->whereKey($message->id)->exists())->toBeTrue();
});

it('deletes a message with its copies and attachments and unlinks replies', function () {
    $a = ml_group('A', 'a');
    $b = ml_group('B', 'b');
    $message = ml_message($a, [[$b, 'to', 'inbox']]);
    Attachment::query()->forceCreate(['message_id' => $message->id, 'disk' => 'local', 'path' => 'x', 'original_name' => 'x.pdf', 'mime' => 'application/pdf', 'size' => 1]);
    $reply = MailMessage::query()->forceCreate(['sender_mailbox_id' => $b->id, 'thread_id' => $message->thread_id, 'in_reply_to' => $message->id]);

    $message->delete();

    expect(MailDelivery::query()->count())->toBe(0)->and(Attachment::query()->count())->toBe(0)
        ->and($reply->refresh()->in_reply_to)->toBeNull();
});

it('registers exactly the four permissions under the module mail, none with a bypass-free prefix', function () {
    $mail = array_values(array_filter(Permissions::known(), fn (string $p) => Permissions::moduleOf($p) === 'mail'));

    expect($mail)->toEqualCanonicalizing(['mail.use', 'mail.lists.manage', 'mail.domain.choose', 'mail.admin'])
        ->and(Permissions::groups())->toHaveKey('mail')
        ->and(array_filter(Permissions::known(), fn (string $p) => str_starts_with($p, 'mail.message') || str_starts_with($p, 'mail.delivery')))->toBe([]);

    app(Settings::class)->set('modules.mail.enabled', false);
    expect(Permissions::groups())->not->toHaveKey('mail')->and(Permissions::known())->toContain('mail.use', 'mail.admin');
});

it('registers the settings outside the generic form', function () {
    $defs = app(SettingDefinitions::class);
    $expected = ['mail.domain' => ['string', 'ignis.ef'], 'mail.address_pattern' => ['enum', 'initial_dot_last'], 'mail.allowed_domains' => ['list', []], 'mail.send_cooldown' => ['int', 10]];
    foreach ($expected as $key => [$type, $default]) {
        $d = $defs->definition($key);
        expect($d)->not->toBeNull()->and($d->type)->toBe($type)->and($d->default)->toBe($default)
            ->and($d->editable)->toBeFalse()->and($d->module)->toBe('mail');
    }
    expect($defs->definition('mail.address_pattern')->options)->toBe(['initial_dot_last', 'first_dot_last'])
        ->and($defs->definition('mail.send_cooldown')->rules)->toBe(['min:0', 'max:3600']);
});

it('guards the placeholder page: guests to login, mail.use (or admin) in, others 403, 404 with the module off', function () {
    $this->get('/mail')->assertRedirect('/login');
    $this->actingAs(ml_user(['audit.view']))->get('/mail')->assertForbidden();
    $this->actingAs(ml_user(['mail.admin']))->get('/mail')->assertForbidden();
    $this->actingAs(ml_user(['mail.use']))->get('/mail')->assertOk();
    $this->actingAs(ml_user(['admin']))->get('/mail')->assertOk();

    app(Settings::class)->set('modules.mail.enabled', false);
    $this->actingAs(ml_user(['mail.use']))->get('/mail')->assertNotFound();
});

it('keeps de and en translations in parity with :name placeholders', function () {
    $de = json_decode(file_get_contents(base_path('modules/Mail/lang/de.json')), true);
    $en = json_decode(file_get_contents(base_path('modules/Mail/lang/en.json')), true);

    expect(array_keys($de))->toEqualCanonicalizing(array_keys($en));
    foreach ($de as $key => $text) {
        preg_match_all('/:([a-z_]+)/', $text, $a);
        preg_match_all('/:([a-z_]+)/', $en[$key], $b);
        expect($a[1])->toEqualCanonicalizing($b[1])->and($text.$en[$key])->not->toMatch('/\{[a-z_]+\}/');
    }
    foreach (['use', 'lists.manage', 'domain.choose', 'admin'] as $p) {
        expect($de)->toHaveKey("permissions.mail.$p");
    }
});

it('lets no content policy pass super admins (architecture)', function () {
    foreach ([MailMessage::class, MailDelivery::class, Attachment::class, MailSignature::class] as $model) {
        $policy = Gate::getPolicyFor($model);
        expect($policy === null || $policy instanceof NoSuperAdminBypass)->toBeTrue("policy of $model must implement NoSuperAdminBypass");
    }
    foreach (glob(base_path('modules/Mail/Policies/*.php')) ?: [] as $file) {
        $class = 'Modules\\Mail\\Policies\\'.basename($file, '.php');
        if (preg_match('/^(MailMessage|MailDelivery|Attachment|MailSignature|Mail(?!box|List))/', class_basename($class))) {
            expect(is_subclass_of($class, NoSuperAdminBypass::class))->toBeTrue("$class touches content and must implement NoSuperAdminBypass");
        }
    }
});

it('does not touch Laravel mail', function () {
    expect(Mailbox::query()->getModel()->getTable())->toBe('mail_mailboxes')
        ->and(file_get_contents(base_path('modules/Mail/MailServiceProvider.php')))->not->toContain('Illuminate\\Support\\Facades\\Mail')
        ->and(array_filter(glob(base_path('modules/Mail/{,*/,*/*/}*.php'), GLOB_BRACE) ?: [], fn ($f) => str_contains(file_get_contents($f), 'Facades\\Mail;')))->toBe([]);
});
