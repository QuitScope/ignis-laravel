<?php

use App\Models\Personnel;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Modules\Mail\Models\Attachment;
use Modules\Mail\Models\Mailbox;
use Modules\Mail\Models\MailDelivery;
use Modules\Mail\Models\MailList;
use Modules\Mail\Models\MailMessage;
use Modules\Mail\Models\MailSignature;
use Modules\Mail\Support\AttachmentStorage;
use Modules\Mail\Support\MailboxAccess;
use Modules\Mail\Support\SignatureTemplate;

require_once __DIR__.'/Mail/mailHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    app(Settings::class)->set('mail.send_cooldown', 0);
    Storage::fake(AttachmentStorage::DISK);
});

const P7B_ROUTES = [
    'GET mail', 'GET mail/addressbook', 'GET mail/{folder}', 'GET mail/{folder}/{message}',
    'PATCH mail/messages/{message}/folder', 'PATCH mail/messages/{message}/read', 'PATCH mail/messages/{message}/flag', 'DELETE mail/messages/{message}',
    'GET mail/compose', 'GET mail/compose/draft/{message}', 'GET mail/compose/{mode}/{message}',
    'GET mail/signature', 'PUT mail/signature', 'GET mail/attachments/{mailAttachment}', 'DELETE mail/attachments/{mailAttachment}',
    'POST mail/drafts', 'PUT mail/drafts/{message}', 'POST mail/drafts/{message}/send', 'POST mail/drafts/{message}/attachments',
    'GET mail/lists', 'GET mail/lists/create', 'POST mail/lists', 'GET mail/lists/{list}/edit', 'PUT mail/lists/{list}', 'DELETE mail/lists/{list}',
    'GET settings/mail', 'PUT settings/mail',
    'GET settings/mail/mailboxes', 'GET settings/mail/mailboxes/groups/create', 'POST settings/mail/mailboxes/groups',
    'GET settings/mail/mailboxes/{mailbox}/edit', 'PUT settings/mail/mailboxes/{mailbox}', 'PATCH settings/mail/mailboxes/{mailbox}/name',
    'PATCH settings/mail/mailboxes/{mailbox}/lock', 'PUT settings/mail/mailboxes/{mailbox}/account', 'DELETE settings/mail/mailboxes/{mailbox}/account',
    'POST settings/mail/mailboxes/{mailbox}/members', 'DELETE settings/mail/mailboxes/{mailbox}/members/{user}',
];

const P7B_SECRET = 'GeheimXyzFremd';

function p7b_list_body(array $over = []): array
{
    return $over + ['name' => 'Wache 1', 'local' => 'wache1', 'domain' => 'ignis.ef', 'kind' => 'static', 'senders' => 'all', 'members' => [], 'rule' => []];
}

/** @return array{user: User, person: Personnel, mailbox: Mailbox} an account with exactly these rights, record and mailbox */
function p7b_actor(string $actor): array
{
    $person = ml_person('Akteur '.$actor);
    $user = match ($actor) {
        'plain' => ml_user(['audit.view'], $person),
        'use' => ml_user(['mail.use'], $person),
        'lists' => ml_user(['mail.lists.manage'], $person),
        'mail-admin' => ml_user(['mail.admin'], $person),
        'admin' => ml_user(['admin'], $person),
        'super-admin' => User::factory()->superAdmin()->create(['personnel_id' => $person->id]),
    };

    return ['user' => $user, 'person' => $person, 'mailbox' => ml_provision($person)->refresh()];
}

function p7b_checker(?User $user, array &$bad, array &$seen): Closure
{
    return function (string $template, string $url, int|string $expected, array $data = [], array $files = []) use ($user, &$bad, &$seen) {
        [$method] = explode(' ', $template, 2);
        $seen[$template] = true;
        if (! in_array($template, P7B_ROUTES, true)) {
            $bad[] = "{$template}: not in P7B_ROUTES";
        }
        app('auth')->forgetGuards();
        $request = $user === null ? test() : test()->actingAs($user);
        $response = $request->call($method, $url, $data, [], $files);
        $got = $response->isRedirect(url('/login')) ? 'login' : $response->getStatusCode();
        if ($got !== $expected) {
            $bad[] = "{$template}: {$method} {$url} expected {$expected}, got {$got}";
        }
        $errors = $expected === 302 && $got === 302 ? $response->getSession()?->get('errors') : null;
        if ($errors !== null && $errors->any()) {
            $bad[] = "{$template}: {$method} {$url} redirected with errors: ".json_encode($errors->getBags()['default']->toArray());
        }
    };
}

function p7b_mail(Mailbox $from, array $copies, string $subject = 'Betreff'): MailMessage
{
    $message = ml_message($from, $copies, $subject);
    $message->forceFill(['body' => json_decode(ml_doc('Text zu '.$subject), true), 'search_text' => $subject."\nText zu ".$subject])->save();

    return $message;
}

function p7b_draft(Mailbox $mailbox, array $to = [], string $subject = 'Entwurf'): MailMessage
{
    $draft = MailMessage::query()->forceCreate([
        'sender_mailbox_id' => $mailbox->id, 'subject' => $subject, 'body' => json_decode(ml_doc('Entwurfstext'), true),
        'search_text' => $subject, 'header' => ['to' => $to, 'cc' => [], 'bcc' => []], 'thread_id' => bin2hex(random_bytes(16)), 'status' => 'draft',
    ]);
    MailDelivery::query()->forceCreate(['message_id' => $draft->id, 'mailbox_id' => $mailbox->id, 'role' => 'sender', 'folder' => 'drafts', 'sorted_at' => now()]);

    return $draft;
}

function p7b_file(MailMessage $message, string $name = 'dienst.txt'): Attachment
{
    $path = "mail-{$message->id}-".bin2hex(random_bytes(8)).'.txt';
    Storage::disk(AttachmentStorage::DISK)->put($path, 'Inhalt von '.$name);

    return Attachment::query()->forceCreate(['message_id' => $message->id, 'disk' => AttachmentStorage::DISK, 'path' => $path, 'original_name' => $name, 'mime' => 'text/plain', 'size' => 20, 'missing' => false]);
}

function p7b_world(): object
{
    $w = new stdClass;
    $w->rita = ml_member('Rita Empfang');
    $w->olga = ml_member('Olga Fremd');
    $w->group = ml_group('Fremdgruppe', 'fremdgruppe', [$w->olga['user']]);
    $w->foreign = p7b_mail($w->olga['mailbox'], [[$w->rita['mailbox'], 'to', 'inbox']], P7B_SECRET.' Mail');
    $w->foreignFile = p7b_file($w->foreign, 'fremd.txt');
    $w->foreignDraft = p7b_draft($w->olga['mailbox'], [], P7B_SECRET.' Entwurf');
    $w->foreignDraftFile = p7b_file($w->foreignDraft, 'fremd-entwurf.txt');
    $w->groupMail = p7b_mail($w->group, [[$w->rita['mailbox'], 'to', 'inbox']], P7B_SECRET.' Gruppe');
    $w->groupDraft = p7b_draft($w->group, [], P7B_SECRET.' Gruppenentwurf');
    $w->groupFile = p7b_file($w->groupMail, 'gruppe.txt');

    return $w;
}

it('lists exactly the Mail routes the matrix covers', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => $r->uri() === 'mail' || str_starts_with($r->uri(), 'mail/') || $r->uri() === 'settings/mail' || str_starts_with($r->uri(), 'settings/mail/'))
        ->flatMap(fn ($r) => collect($r->methods())->reject(fn ($m) => $m === 'HEAD')->map(fn ($m) => "{$m} {$r->uri()}"))
        ->sort()->values()->all();

    $expected = P7B_ROUTES;
    sort($expected);
    expect($routes)->toBe($expected);
});

it('answers every Mail route with the exact status for every account and proves the writes by their post-state', function (string $actor) {
    $w = p7b_world();
    $guest = $actor === 'guest';
    $me = $guest ? null : p7b_actor($actor);
    $user = $me['user'] ?? null;
    $in = fn (string ...$who) => in_array($actor, $who, true);
    $deny = $guest ? 'login' : 403;
    $page = $in('use', 'admin', 'super-admin');
    $lists = $in('lists', 'admin', 'super-admin');
    $admin = $in('mail-admin', 'admin', 'super-admin');
    $r = fn (int|string $ok, bool $allowed) => $allowed ? $ok : $deny;
    $notFound = $guest ? 'login' : 404;

    $mb = $me['mailbox'] ?? Mailbox::query()->forceCreate(['kind' => Mailbox::GROUP, 'address' => 'gast@ignis.ef', 'display_name' => 'Gast', 'domain' => 'ignis.ef']);
    $rita = $w->rita['mailbox'];
    $inbox = fn (string $s) => p7b_mail($rita, [[$mb, 'to', 'inbox']], $s);
    $show = $inbox('Zeigen');
    $read = $inbox('Lesen');
    $flag = $inbox('Markieren');
    $move = $inbox('Verschieben');
    $del = $inbox('Loeschen');
    $reply = $inbox('Antworten');
    $withFile = $inbox('Mit Anhang');
    $file = p7b_file($withFile);
    $d = [];
    foreach (['view', 'put', 'send', 'upload', 'file'] as $k) {
        $d[$k] = p7b_draft($mb, $k === 'send' ? [$rita->address] : [], 'Entwurf '.$k);
    }
    $draftFile = p7b_file($d['file']);
    $boxAddress = ml_member('Anna Adresse')['mailbox'];
    $boxLock = ml_member('Lars Sperre')['mailbox'];
    $boxAssign = ml_provision($assignPerson = ml_person('Paula Konto'));
    $assignUser = ml_user(['mail.use'], $assignPerson);
    $boxRelease = ml_provision(ml_person('Rolf Los'));
    $boxRelease->forceFill(['user_id' => ml_user(['mail.use'])->id])->save();
    $team = ml_group('Wache', 'wache', [ml_member('Mia Mitglied')['user']]);
    $newMember = ml_user(['mail.use']);
    $oldMember = $team->members()->first();
    $list = MailList::query()->forceCreate(['address' => 'liste@ignis.ef', 'name' => 'Liste']);
    $listGone = MailList::query()->forceCreate(['address' => 'weg@ignis.ef', 'name' => 'Weg']);
    $beforeMessages = MailMessage::count();
    $beforeLists = MailList::count();
    $beforeGroups = Mailbox::where('kind', Mailbox::GROUP)->count();

    $bad = [];
    $seen = [];
    $check = p7b_checker($user, $bad, $seen);

    $check('GET mail', '/mail', $r(200, $page));
    $check('GET mail/{folder}', '/mail/archive', $r(200, $page));
    $check('GET mail/{folder}/{message}', "/mail/inbox/{$show->id}", $r(200, $page));
    $check('GET mail/addressbook', '/mail/addressbook?q=a', $r(200, $page));
    $check('GET mail/compose', '/mail/compose', $r(200, $page));
    $check('GET mail/compose/draft/{message}', "/mail/compose/draft/{$d['view']->id}", $r(200, $page));
    $check('GET mail/compose/{mode}/{message}', "/mail/compose/reply/{$reply->id}", $r(200, $page));
    $check('GET mail/compose/{mode}/{message}', "/mail/compose/reply-all/{$reply->id}", $r(200, $page));
    $check('GET mail/compose/{mode}/{message}', "/mail/compose/forward/{$reply->id}", $r(200, $page));
    $check('GET mail/signature', "/mail/signature?mailbox={$mb->id}", $r(200, $page));
    $check('GET mail/attachments/{mailAttachment}', "/mail/attachments/{$file->id}", $guest ? 'login' : 200 /* the file needs a visible copy, no page right (deferred minor) */);

    $check('PATCH mail/messages/{message}/read', "/mail/messages/{$read->id}/read", $r(200, $page), ['read' => true]);
    $check('PATCH mail/messages/{message}/flag', "/mail/messages/{$flag->id}/flag", $r(200, $page), ['flagged' => true]);
    $check('PATCH mail/messages/{message}/folder', "/mail/messages/{$move->id}/folder", $r(200, $page), ['folder' => 'archive']);
    $check('DELETE mail/messages/{message}', "/mail/messages/{$del->id}", $r(200, $page));
    $check('POST mail/drafts', '/mail/drafts', $r(201, $page), ['mailbox_id' => $mb->id, 'subject' => 'Neuer Entwurf', 'body_json' => ml_doc('Neu')]);
    $check('PUT mail/drafts/{message}', "/mail/drafts/{$d['put']->id}", $r(200, $page), ['mailbox_id' => $mb->id, 'subject' => 'Geaendert']);
    $check('POST mail/drafts/{message}/attachments', "/mail/drafts/{$d['upload']->id}/attachments", $r(201, $page), ['mailbox_id' => $mb->id], ['file' => UploadedFile::fake()->createWithContent('neu.txt', 'Neu')]);
    $check('DELETE mail/attachments/{mailAttachment}', "/mail/attachments/{$draftFile->id}", $r(200, $page), ['mailbox_id' => $mb->id]);
    $check('PUT mail/signature', '/mail/signature', $r(302, $page), ['mailbox_id' => $mb->id, 'body_json' => json_encode(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Mit Gruss']]]]])]);
    $check('POST mail/drafts/{message}/send', "/mail/drafts/{$d['send']->id}/send", $r(200, $page), ['mailbox_id' => $mb->id, 'subject' => 'Gesendet', 'body_json' => ml_doc('Hallo'), 'to' => [$rita->address]]);

    $check('GET mail/lists', '/mail/lists', $r(200, $lists));
    $check('GET mail/lists/create', '/mail/lists/create', $r(200, $lists));
    $check('GET mail/lists/{list}/edit', "/mail/lists/{$list->id}/edit", $r(200, $lists));
    $check('POST mail/lists', '/mail/lists', $r(302, $lists), p7b_list_body(['local' => 'neu', 'name' => 'Neu']));
    $check('PUT mail/lists/{list}', "/mail/lists/{$list->id}", $r(302, $lists), p7b_list_body(['local' => 'liste', 'name' => 'Liste neu']));
    $check('DELETE mail/lists/{list}', "/mail/lists/{$listGone->id}", $r(302, $lists));

    $check('GET settings/mail', '/settings/mail', $r(200, $admin));
    $check('GET settings/mail/mailboxes', '/settings/mail/mailboxes', $r(200, $admin));
    $check('GET settings/mail/mailboxes/groups/create', '/settings/mail/mailboxes/groups/create', $r(200, $admin));
    $check('GET settings/mail/mailboxes/{mailbox}/edit', "/settings/mail/mailboxes/{$boxAddress->id}/edit", $r(200, $admin));
    $check('POST settings/mail/mailboxes/groups', '/settings/mail/mailboxes/groups', $r(302, $admin), ['name' => 'Neue Gruppe', 'local' => 'neue.gruppe']);
    $check('PUT settings/mail/mailboxes/{mailbox}', "/settings/mail/mailboxes/{$boxAddress->id}", $r(302, $admin), ['local' => 'anna.neu']);
    $check('PATCH settings/mail/mailboxes/{mailbox}/name', "/settings/mail/mailboxes/{$team->id}/name", $r(302, $admin), ['name' => 'Wache Neu']);
    $check('PATCH settings/mail/mailboxes/{mailbox}/lock', "/settings/mail/mailboxes/{$boxLock->id}/lock", $r(302, $admin), ['locked' => true]);
    $check('PUT settings/mail/mailboxes/{mailbox}/account', "/settings/mail/mailboxes/{$boxAssign->id}/account", $r(302, $admin), ['user_id' => $assignUser->id]);
    $check('DELETE settings/mail/mailboxes/{mailbox}/account', "/settings/mail/mailboxes/{$boxRelease->id}/account", $r(302, $admin));
    $check('POST settings/mail/mailboxes/{mailbox}/members', "/settings/mail/mailboxes/{$team->id}/members", $r(302, $admin), ['user_id' => $newMember->id]);
    $check('DELETE settings/mail/mailboxes/{mailbox}/members/{user}', "/settings/mail/mailboxes/{$team->id}/members/{$oldMember->id}", $r(302, $admin));
    $check('PUT settings/mail', '/settings/mail', $r(302, $admin), [
        'domain' => 'ignis.ef', 'address_pattern' => 'first_dot_last', 'allowed_domains' => '', 'send_cooldown' => '15', 'signature' => json_encode(SignatureTemplate::builtIn()),
    ]);

    expect($bad)->toBe([])->and(array_keys($seen))->toEqualCanonicalizing(P7B_ROUTES);

    $copy = fn (MailMessage $m) => MailDelivery::query()->where('message_id', $m->id)->where('mailbox_id', $mb->id)->first();
    if ($page) {
        expect($copy($read)->read_at)->not->toBeNull()
            ->and($copy($flag)->is_flagged)->toBeTrue()
            ->and($copy($move)->folder)->toBe('archive')
            ->and($copy($del)->deleted_at)->not->toBeNull()
            ->and(MailDelivery::query()->where('message_id', $del->id)->where('mailbox_id', $rita->id)->whereNull('deleted_at')->exists())->toBeTrue()
            ->and(MailMessage::where('sender_mailbox_id', $mb->id)->where('subject', 'Neuer Entwurf')->where('status', 'draft')->exists())->toBeTrue()
            ->and($d['put']->refresh()->subject)->toBe('Geaendert')
            ->and(Attachment::where('message_id', $d['upload']->id)->where('original_name', 'neu.txt')->exists())->toBeTrue()
            ->and(Attachment::whereKey($draftFile->id)->exists())->toBeFalse()
            ->and(MailSignature::where('mailbox_id', $mb->id)->exists())->toBeTrue()
            ->and($d['send']->refresh()->status)->toBe('sent')
            ->and($d['send']->subject)->toBe('Gesendet')
            ->and(MailDelivery::where('message_id', $d['send']->id)->where('mailbox_id', $rita->id)->where('folder', 'inbox')->exists())->toBeTrue();
    } else {
        expect($copy($read)->read_at)->toBeNull()->and($copy($flag)->is_flagged)->toBeFalse()->and($copy($move)->folder)->toBe('inbox')
            ->and($copy($del)->deleted_at)->toBeNull()->and(MailMessage::where('subject', 'Neuer Entwurf')->exists())->toBeFalse()
            ->and($d['put']->refresh()->subject)->toBe('Entwurf put')->and(Attachment::where('message_id', $d['upload']->id)->exists())->toBeFalse()
            ->and(Attachment::whereKey($draftFile->id)->exists())->toBeTrue()->and(MailSignature::count())->toBe(0)
            ->and($d['send']->refresh()->status)->toBe('draft');
    }
    if ($lists) {
        expect(MailList::count())->toBe($beforeLists)
            ->and(MailList::where('address', 'neu@ignis.ef')->exists())->toBeTrue()
            ->and(MailList::whereKey($listGone->id)->exists())->toBeFalse()
            ->and($list->refresh()->name)->toBe('Liste neu');
    } else {
        expect(MailList::count())->toBe($beforeLists)->and(MailList::whereKey($listGone->id)->exists())->toBeTrue()->and($list->refresh()->name)->toBe('Liste');
    }
    if ($admin) {
        expect(Mailbox::where('kind', Mailbox::GROUP)->where('address', 'neue.gruppe@ignis.ef')->exists())->toBeTrue()
            ->and(Mailbox::where('kind', Mailbox::GROUP)->count())->toBe($beforeGroups + 1)
            ->and($boxAddress->refresh()->address)->toBe('anna.neu@ignis.ef')
            ->and($team->refresh()->display_name)->toBe('Wache Neu')
            ->and($boxLock->refresh()->is_locked)->toBeTrue()
            ->and($boxAssign->refresh()->user_id)->toBe($assignUser->id)
            ->and($boxRelease->refresh()->user_id)->toBeNull()
            ->and($team->members()->pluck('users.id')->all())->toBe([$newMember->id])
            ->and(app(Settings::class)->get('mail.send_cooldown'))->toBe(15)
            ->and(app(Settings::class)->get('mail.address_pattern'))->toBe('first_dot_last');
    } else {
        expect(Mailbox::where('address', 'neue.gruppe@ignis.ef')->exists())->toBeFalse()
            ->and($boxAddress->refresh()->address)->not->toBe('anna.neu@ignis.ef')->and($team->refresh()->display_name)->toBe('Wache')
            ->and($boxLock->refresh()->is_locked)->toBeFalse()->and($boxAssign->refresh()->user_id)->toBeNull()->and($boxRelease->refresh()->user_id)->not->toBeNull()
            ->and($team->members()->count())->toBe(1)->and($team->members()->first()->id)->toBe($oldMember->id)
            ->and(app(Settings::class)->get('mail.send_cooldown'))->toBe(0);
    }
    expect($w->foreign->refresh()->subject)->toStartWith(P7B_SECRET)->and(Attachment::whereKey($w->foreignFile->id)->exists())->toBeTrue()
        ->and($beforeMessages)->toBeGreaterThan(0);
})->with(['guest', 'plain', 'use', 'lists', 'mail-admin', 'admin', 'super-admin']);

it('answers every route naming a FOREIGN message, attachment or draft with 404 for every account, admin and super admin included', function (string $actor) {
    $w = p7b_world();
    $guest = $actor === 'guest';
    $me = $guest ? null : p7b_actor($actor);
    $not = $guest ? 'login' : 404;
    $mailboxId = $w->olga['mailbox']->id;

    $bad = [];
    $seen = [];
    $check = p7b_checker($me['user'] ?? null, $bad, $seen);
    $m = $w->foreign->id;
    $own = ['folder' => 'archive'];
    foreach (['inbox', 'sent'] as $folder) {
        $check('GET mail/{folder}/{message}', "/mail/$folder/$m", $not);
        $check('GET mail/{folder}/{message}', "/mail/$folder/$m?mailbox=$mailboxId", $not);
    }
    $check('GET mail/{folder}/{message}', "/mail/inbox/{$w->groupMail->id}?mailbox={$w->group->id}", $not);
    $check('PATCH mail/messages/{message}/folder', "/mail/messages/$m/folder", $not, $own + ['mailbox_id' => $mailboxId]);
    $check('PATCH mail/messages/{message}/read', "/mail/messages/$m/read", $not, ['read' => true]);
    $check('PATCH mail/messages/{message}/flag', "/mail/messages/$m/flag", $not, ['flagged' => true]);
    $check('DELETE mail/messages/{message}', "/mail/messages/$m", $not);
    foreach (['reply', 'reply-all', 'forward'] as $mode) {
        $check('GET mail/compose/{mode}/{message}', "/mail/compose/$mode/$m", $not);
        $check('GET mail/compose/{mode}/{message}', "/mail/compose/$mode/$m?mailbox=$mailboxId", $not);
        $check('GET mail/compose/{mode}/{message}', "/mail/compose/$mode/{$w->groupMail->id}?mailbox={$w->group->id}", $not);
    }
    $check('GET mail/compose/draft/{message}', "/mail/compose/draft/{$w->foreignDraft->id}", $not);
    $check('GET mail/compose/draft/{message}', "/mail/compose/draft/{$w->groupDraft->id}?mailbox={$w->group->id}", $not);
    $check('GET mail/attachments/{mailAttachment}', "/mail/attachments/{$w->foreignFile->id}", $not);
    $check('GET mail/attachments/{mailAttachment}', "/mail/attachments/{$w->foreignDraftFile->id}", $not);
    $check('GET mail/attachments/{mailAttachment}', "/mail/attachments/{$w->groupFile->id}", $not);
    $check('DELETE mail/attachments/{mailAttachment}', "/mail/attachments/{$w->foreignDraftFile->id}", $not, ['mailbox_id' => $mailboxId]);
    $check('PUT mail/drafts/{message}', "/mail/drafts/{$w->foreignDraft->id}", $not, ['mailbox_id' => $mailboxId, 'subject' => 'Uebernommen']);
    $check('POST mail/drafts/{message}/send', "/mail/drafts/{$w->foreignDraft->id}/send", $not, ['mailbox_id' => $mailboxId, 'to' => [$w->rita['mailbox']->address]]);
    $check('POST mail/drafts/{message}/attachments', "/mail/drafts/{$w->foreignDraft->id}/attachments", $not, ['mailbox_id' => $mailboxId], ['file' => UploadedFile::fake()->createWithContent('x.txt', 'x')]);
    $check('POST mail/drafts/{message}/attachments', "/mail/drafts/{$w->groupDraft->id}/attachments", $not, ['mailbox_id' => $w->group->id], ['file' => UploadedFile::fake()->createWithContent('x.txt', 'x')]);

    expect($bad)->toBe([]);
    expect($w->foreignDraft->refresh()->subject)->toStartWith(P7B_SECRET)->and($w->foreignDraft->status)->toBe('draft')
        ->and(Attachment::where('message_id', $w->foreignDraft->id)->count())->toBe(1)->and(Attachment::where('message_id', $w->groupDraft->id)->count())->toBe(0)
        ->and(MailDelivery::where('message_id', $m)->whereNotNull('deleted_at')->count())->toBe(0)
        ->and(MailDelivery::where('message_id', $m)->where('mailbox_id', $w->rita['mailbox']->id)->value('read_at'))->toBeNull()
        ->and(MailDelivery::where('message_id', $m)->where('mailbox_id', $w->rita['mailbox']->id)->value('is_flagged'))->toBeFalse();
})->with(['guest', 'plain', 'use', 'lists', 'mail-admin', 'admin', 'super-admin']);

it('shows every account only its own mailbox content and never a foreign subject, not even on the administration pages', function (string $actor) {
    $w = p7b_world();
    $me = p7b_actor($actor);
    $user = $me['user'];
    $mb = $me['mailbox'];
    $page = in_array($actor, ['use', 'admin', 'super-admin'], true);
    $mine = p7b_mail($w->rita['mailbox'], [[$mb, 'to', 'inbox']], 'Eigene Post');
    $this->actingAs($user);

    if ($page) {
        $this->get('/mail')->assertOk()->assertDontSee(P7B_SECRET)
            ->assertInertia(fn ($p) => $p->where('selected', $mb->id)->where('mailboxes', fn ($rows) => collect($rows)->pluck('id')->all() === [$mb->id])
                ->where('messages.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$mine->id]));
        foreach ([$w->olga['mailbox']->id, $w->group->id, $w->rita['mailbox']->id] as $other) {
            $this->get("/mail?mailbox=$other")->assertOk()->assertDontSee(P7B_SECRET)
                ->assertInertia(fn ($p) => $p->where('state', 'unknown')->where('messages.data', []));
            $this->get("/mail/sent?mailbox=$other")->assertOk()->assertDontSee(P7B_SECRET);
        }
        $this->getJson('/mail/addressbook?q=Fremd')->assertOk()->assertDontSee(P7B_SECRET);
        $this->getJson('/api/system/global-search?'.http_build_query(['q' => P7B_SECRET]))->assertOk()->assertExactJson(['q' => P7B_SECRET, 'results' => [], 'scopes' => $this->getJson('/api/system/global-search')->json('scopes')]);
        $own = $this->getJson('/api/system/global-search?'.http_build_query(['q' => 'Eigene Post']))->assertOk()->collect('results')->firstWhere('key', 'mails');
        expect($own['items'] ?? [])->toHaveCount(1);
    }
    foreach (['/mail/lists', '/settings/mail', '/settings/mail/mailboxes', "/settings/mail/mailboxes/{$w->olga['mailbox']->id}/edit", "/settings/mail/mailboxes/{$w->group->id}/edit"] as $url) {
        $response = $this->get($url);
        if ($response->getStatusCode() === 200) {
            $response->assertDontSee(P7B_SECRET)->assertDontSee('fremd.txt')->assertDontSee('fremd-entwurf.txt');
        }
    }
})->with(['plain', 'use', 'lists', 'mail-admin', 'admin', 'super-admin']);

it('lets a group member open the group mailbox but nobody else, super admin and admin included', function (string $actor) {
    $w = p7b_world();
    $me = p7b_actor($actor);
    $this->actingAs($me['user']);
    $this->get("/mail/sent/{$w->groupMail->id}?mailbox={$w->group->id}")->assertNotFound();
    $this->get("/mail/attachments/{$w->groupFile->id}")->assertNotFound();
    $w->group->members()->attach($me['user']->id);
    MailboxAccess::forget();
    $this->get("/mail/attachments/{$w->groupFile->id}")->assertOk();
    $this->get("/mail/sent/{$w->groupMail->id}?mailbox={$w->group->id}")->assertOk()->assertSee(P7B_SECRET);
})->with(['use', 'admin', 'super-admin']);
