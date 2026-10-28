<?php

use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Mail\Http\Controllers\InboxController;
use Modules\Mail\Models\MailDelivery;
use Modules\Mail\Models\MailList;
use Modules\Mail\Models\MailMessage;

require_once __DIR__.'/mailHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    app(Settings::class)->set('mail.send_cooldown', 0);
    $this->alice = ml_member('Alice Absender');
    $this->bob = ml_member('Bob Empfang');
    $this->carla = ml_member('Carla Kopie');
    $this->dora = ml_member('Dora Blind');
});

function mi_round(object $t): int
{
    $t->actingAs($t->alice['user']);

    return ml_send($t, [
        'subject' => 'Wachplan KW 40', 'body_json' => ml_doc('Bitte bis Freitag bestätigen.'),
        'to' => [$t->bob['mailbox']->address], 'cc' => [$t->carla['mailbox']->address], 'bcc' => [$t->dora['mailbox']->address],
    ])->json('messageId');
}

it('lists the inbox with unread rows and counters and writes nothing on GET', function () {
    $id = mi_round($this);
    $this->actingAs($this->bob['user']);
    $before = DB::table('mail_deliveries')->orderBy('id')->get()->toJson().DB::table('notifications')->get()->toJson();

    $this->get('/mail')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn ($page) => $page->component('Mail/Index')->where('folder', 'inbox')->where('selected', $this->bob['mailbox']->id)
            ->where('state', 'ok')->where('counts.inbox', 1)->where('mailboxes.0.unread', 1)
            ->has('messages.data', 1)->where('messages.data.0.id', $id)->where('messages.data.0.unread', true)
            ->where('messages.data.0.subject', 'Wachplan KW 40')->where('messages.data.0.preview', 'Bitte bis Freitag bestätigen.')
            ->where('messages.data.0.from.name', 'Alice Absender')->where('message', null));

    $this->get("/mail/inbox/$id")->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn ($page) => $page->where('message.id', $id)->where('message.unread', true)
            ->where('message.html', '<p>Bitte bis Freitag bestätigen.</p>'));

    expect(DB::table('mail_deliveries')->orderBy('id')->get()->toJson().DB::table('notifications')->get()->toJson())->toBe($before);

    $this->patchJson("/mail/messages/$id/read", ['read' => true])->assertOk();
    $this->get('/mail')->assertInertia(fn ($page) => $page->where('counts', [])->where('messages.data.0.unread', false));
});

it('shows the chosen mail in the reading pane by url', function () {
    $this->actingAs($this->alice['user']);
    $older = ml_send($this, ['subject' => 'Ältere Mail', 'body_json' => ml_doc('Alt'), 'to' => [$this->bob['mailbox']->address]])->json('messageId');
    $this->travel(1)->minutes();
    $newer = ml_send($this, ['subject' => 'Neuere Mail', 'body_json' => ml_doc('Neu'), 'to' => [$this->bob['mailbox']->address]])->json('messageId');
    $this->actingAs($this->bob['user']);

    foreach ([[$newer, 'Neuere Mail', 'Neu'], [$older, 'Ältere Mail', 'Alt']] as [$id, $subject, $text]) {
        $this->get("/mail/inbox/$id")->assertInertia(fn ($page) => $page->where('message.id', $id)->where('message.subject', $subject)
            ->where('message.html', "<p>$text</p>")->where('message.from.address', $this->alice['mailbox']->address)
            ->where('messages.data.0.id', $newer)->where('messages.data.1.id', $older));
    }
    $this->get('/mail/inbox')->assertInertia(fn ($page) => $page->where('message', null));
});

it('builds the preview from the plain text, paragraphs apart, entities as characters', function () {
    $this->actingAs($this->alice['user']);
    $doc = ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hallo Anna,']]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'anbei Mo & Di.']]],
    ]];
    ml_send($this, ['subject' => 'Plan', 'body_json' => json_encode($doc), 'to' => [$this->bob['mailbox']->address]]);

    $this->actingAs($this->bob['user'])->get('/mail/inbox')
        ->assertInertia(fn ($page) => $page->where('messages.data.0.preview', 'Hallo Anna, anbei Mo & Di.'));
});

it('never puts the body, the search text or the raw header into list rows', function () {
    $id = mi_round($this);
    $this->actingAs($this->alice['user'])->get('/mail/sent')
        ->assertInertia(fn ($page) => $page->has('messages.data.0', fn ($row) => $row
            ->hasAll(['id', 'subject', 'from', 'to', 'date', 'unread', 'flagged', 'draft', 'preview'])
            ->missingAll(['body', 'header', 'search_text', 'html', 'bcc'])->etc()));

    $this->actingAs($this->bob['user']);
    $this->get('/mail/inbox')->assertDontSee($this->dora['mailbox']->address);
    $this->get("/mail/inbox/$id")->assertDontSee($this->dora['mailbox']->address);
});

it('shows bcc only to the sender and to the bcc recipient itself', function () {
    $id = mi_round($this);

    $this->get("/mail/sent/$id")->assertInertia(fn ($page) => $page->where('message.header.bcc', [$this->dora['mailbox']->address])
        ->where('message.header.to', [$this->bob['mailbox']->address])->where('message.header.cc', [$this->carla['mailbox']->address]));

    foreach (['bob', 'carla'] as $who) {
        $this->actingAs($this->{$who}['user'])->get("/mail/inbox/$id")
            ->assertInertia(fn ($page) => $page->where('message.header.bcc', []))
            ->assertDontSee($this->dora['mailbox']->address);
    }
    $this->actingAs($this->dora['user'])->get("/mail/inbox/$id")
        ->assertInertia(fn ($page) => $page->where('message.header.bcc', [$this->dora['mailbox']->address]));
});

it('has no foreign mail and no unknown folder, not for admins either', function (Closure $make) {
    $id = mi_round($this);
    $eve = $make();
    $own = ml_provision(ml_person('Eve Fremd'));
    $eve->forceFill(['personnel_id' => $own->personnel_id])->save();
    $this->actingAs($eve->fresh());

    foreach (['inbox', 'sent', 'archive', 'trash', 'drafts'] as $folder) {
        $this->get("/mail/$folder/$id")->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
    }
    $this->get('/mail/inbox')->assertOk()->assertDontSee('Wachplan');
    $this->get("/mail/inbox/$id?mailbox={$this->bob['mailbox']->id}")->assertNotFound();
    $this->get('/mail/spam')->assertNotFound();
    $this->get('/mail/inbox/abc')->assertNotFound();
})->with([
    'mail.use' => [fn () => ml_user(['mail.use'])],
    'admin' => [fn () => ml_user(['mail.use', 'admin'])],
    'super admin' => [fn () => User::factory()->superAdmin()->create()],
    'mail.admin' => [fn () => ml_user(['mail.use', 'mail.admin', 'mail.lists.manage'])],
]);

it('leads the bell link to the folder and mailbox the own copy is really in', function () {
    $id = mi_round($this);
    $this->actingAs($this->bob['user']);

    $this->get("/mail/sent/$id")->assertRedirect("/mail/inbox/$id?mailbox={$this->bob['mailbox']->id}");

    $this->patchJson("/mail/messages/$id/folder", ['folder' => 'archive'])->assertOk();
    $this->get("/mail/inbox/$id")->assertRedirect("/mail/archive/$id?mailbox={$this->bob['mailbox']->id}");
    $this->get("/mail/archive/$id?mailbox={$this->bob['mailbox']->id}")->assertOk()->assertInertia(fn ($page) => $page->where('message.id', $id));

    $mia = ml_member('Mia Mitglied');
    $group = ml_group('Leitstelle', 'leitstelle', [$mia['user']]);
    $this->actingAs($this->alice['user']);
    $toGroup = ml_send($this, ['subject' => 'An die Gruppe', 'to' => [$group->address]])->json('messageId');
    $this->actingAs($mia['user']);
    $this->get("/mail/inbox/$toGroup")->assertRedirect("/mail/inbox/$toGroup?mailbox={$group->id}");
    $this->get("/mail/inbox/$toGroup?mailbox={$group->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('selected', $group->id)->where('mailbox.kind', 'group')->where('message.id', $toGroup));
    $this->get('/mail')->assertInertia(fn ($page) => $page->where('selected', $group->id)->where('messages.data.0.id', $toGroup));
});

it('pages the folder by cursor, 50 at a time, newest first', function () {
    $from = ml_member('Viel Schreiber');
    $ids = [];
    foreach (range(1, 55) as $i) {
        $this->travel(1)->minutes();
        $ids[] = ml_message($from['mailbox'], [[$this->bob['mailbox'], 'to', 'inbox']], "Mail $i")->id;
    }
    $this->actingAs($this->bob['user']);

    $first = $this->get('/mail/inbox')->assertInertia(fn ($page) => $page->has('messages.data', InboxController::PER_PAGE)
        ->where('messages.data.0.id', $ids[54])->where('messages.data.49.id', $ids[5])->where('messages.prev', null));
    $next = $first->viewData('page')['props']['messages']['next'];
    expect($next)->toContain('cursor=');

    $this->get($next)->assertInertia(fn ($page) => $page->has('messages.data', 5)
        ->where('messages.data.0.id', $ids[4])->where('messages.data.4.id', $ids[0])->where('messages.next', null));
});

it('lists the folder with a constant number of queries', function () {
    $from = ml_member('Viel Schreiber');
    $count = function (int $n) use ($from) {
        MailDelivery::query()->where('mailbox_id', $this->bob['mailbox']->id)->delete();
        foreach (range(1, $n) as $i) {
            $m = ml_message($from['mailbox'], [[$this->bob['mailbox'], 'to', 'inbox']], "Mail $i");
            $m->forceFill(['header' => ['to' => [$this->bob['mailbox']->address], 'cc' => [], 'bcc' => []]])->save();
        }
        $this->actingAs($this->bob['user']);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/mail/inbox')->assertOk()->assertInertia(fn ($page) => $page->has('messages.data', $n));
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $count(1);
    expect($count(5))->toBe($count(50));
});

it('shows a mail to oneself once per folder', function () {
    $this->actingAs($this->alice['user']);
    $id = ml_send($this, ['subject' => 'An mich', 'to' => [$this->alice['mailbox']->address]])->json('messageId');

    $this->get('/mail/inbox')->assertInertia(fn ($page) => $page->has('messages.data', 1));
    $this->get('/mail/sent')->assertInertia(fn ($page) => $page->has('messages.data', 1));

    $this->patchJson("/mail/messages/$id/folder", ['folder' => 'archive'])->assertOk();
    $this->get('/mail/archive')->assertInertia(fn ($page) => $page->has('messages.data', 1)->where('messages.data.0.id', $id));
    $this->get("/mail/archive/$id")->assertInertia(fn ($page) => $page->where('message.role', 'to'));
});

it('lists drafts with the cleanup hint and opens them for editing', function () {
    $this->actingAs($this->alice['user']);
    $id = ml_draft($this, ['subject' => 'Halb fertig', 'to' => [$this->bob['mailbox']->address]]);

    $this->get('/mail/drafts')->assertInertia(fn ($page) => $page->where('folder', 'drafts')->where('draftDays', 30)
        ->where('messages.data.0.id', $id)->where('messages.data.0.draft', true)->where('messages.data.0.to', [$this->bob['mailbox']->address]));
    $this->get("/mail/drafts/$id")->assertInertia(fn ($page) => $page->where('message.draft', true)->where('message.subject', 'Halb fertig'));

    $this->actingAs($this->bob['user'])->get("/mail/drafts/$id")->assertNotFound();
});

it('shows every folder empty without errors', function () {
    $this->actingAs($this->alice['user']);
    foreach (InboxController::FOLDERS as $folder) {
        $this->get("/mail/$folder")->assertOk()->assertInertia(fn ($page) => $page->where('folder', $folder)->has('messages.data', 0));
    }
});

it('explains a missing or inactive mailbox', function () {
    $this->actingAs(ml_user())->get('/mail')->assertOk()
        ->assertInertia(fn ($page) => $page->where('state', 'none')->where('mailbox', null)->has('messages.data', 0));

    $this->alice['mailbox']->forceFill(['is_locked' => true])->save();
    $this->actingAs($this->alice['user'])->get('/mail')->assertInertia(fn ($page) => $page->where('state', 'inactive'));

    $this->actingAs($this->bob['user'])->get("/mail?mailbox={$this->carla['mailbox']->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('state', 'unknown')->where('selected', null));
});

it('renders a stored body the renderer refuses as a notice instead of an error', function () {
    $id = mi_round($this);
    MailMessage::query()->whereKey($id)->update(['body' => json_encode(['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => str_repeat('x', 210_000)]]],
    ]])]);

    $this->actingAs($this->bob['user'])->get("/mail/inbox/$id")->assertOk()
        ->assertInertia(fn ($page) => $page->where('message.html', '')->where('message.broken', true));
});

it('counts unread per folder and per mailbox, received copies only', function () {
    $mia = ml_member('Mia Mitglied');
    $group = ml_group('Leitstelle', 'leitstelle', [$mia['user']]);
    $this->actingAs($this->alice['user']);
    ml_send($this, ['to' => [$mia['mailbox']->address]]);
    ml_send($this, ['to' => [$group->address]]);
    $archived = ml_send($this, ['to' => [$mia['mailbox']->address]])->json('messageId');
    $this->actingAs($mia['user']);
    $this->patchJson("/mail/messages/$archived/folder", ['folder' => 'archive'])->assertOk();
    ml_send($this, ['to' => [$this->bob['mailbox']->address]]);

    $this->get('/mail')->assertInertia(fn ($page) => $page->where('counts', ['archive' => 1, 'inbox' => 1])
        ->where('mailboxes.0.id', $mia['mailbox']->id)->where('mailboxes.0.unread', 1)
        ->where('mailboxes.1.id', $group->id)->where('mailboxes.1.unread', 1));
});

it('offers the new mail form as a lazy prop for the dialog', function () {
    $this->actingAs($this->alice['user'])->get('/mail')->assertInertia(fn ($page) => $page->missing('compose'));
    $this->get('/mail')->assertInertia(fn ($page) => $page->reloadOnly('compose', fn ($reload) => $reload
        ->where('compose.mode', 'new')->where('compose.mailbox.id', $this->alice['mailbox']->id)->where('compose.draftId', null)));
    expect(MailMessage::query()->count())->toBe(0);
});

it('answers the address book with deliverable mailboxes and lists one may write to', function () {
    ml_member('Bernd Sucher');
    $gone = ml_member('Berta Gesperrt')['mailbox'];
    $gone->forceFill(['is_locked' => true])->save();
    MailList::query()->forceCreate(['address' => 'bereitschaft@ignis.ef', 'name' => 'Bereitschaft']);
    MailList::query()->forceCreate(['address' => 'berater@ignis.ef', 'name' => 'Berater', 'senders' => 'managers']);
    $this->actingAs($this->alice['user']);

    $response = $this->getJson('/mail/addressbook?q=be')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $addresses = collect($response->json('results'))->pluck('address');
    expect($addresses)->toContain('bereitschaft@ignis.ef', 'b.sucher@ignis.ef')
        ->and($addresses)->not->toContain($gone->address)
        ->and($addresses)->not->toContain('berater@ignis.ef');

    $this->getJson('/mail/addressbook?q=%25')->assertOk()->assertJsonPath('results', []);
    $this->getJson('/mail/addressbook?q[]=x')->assertOk();

    $this->actingAs(ml_member('Lena Listen', ['mail.use', 'mail.lists.manage'])['user']);
    expect(collect($this->getJson('/mail/addressbook?q=be')->json('results'))->pluck('address'))->toContain('berater@ignis.ef');
});

it('returns at most 20 mailboxes and 20 lists per query', function () {
    foreach (range(1, 22) as $i) {
        ml_group("Zug $i", "zug$i");
        MailList::query()->forceCreate(['address' => "zugliste$i@ignis.ef", 'name' => "Zugliste $i"]);
    }
    $results = collect($this->actingAs($this->alice['user'])->getJson('/mail/addressbook?q=zug')->json('results'));

    expect($results->where('kind', 'group'))->toHaveCount(20)->and($results->where('kind', 'list'))->toHaveCount(20);
});

it('guards the workspace: guests to login, mail.use or admin in, others 403', function () {
    $this->get('/mail/inbox')->assertRedirect('/login');
    $this->actingAs(ml_user(['mail.admin']))->get('/mail/inbox')->assertForbidden();
    $this->actingAs(ml_user(['mail.admin']))->getJson('/mail/addressbook')->assertForbidden();
});
