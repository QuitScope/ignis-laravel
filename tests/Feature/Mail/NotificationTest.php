<?php

use App\Notifications\NotificationKinds;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Mail\Models\MailMessage;
use Modules\Mail\Notifications\MailType;
use Modules\Mail\Support\Mailer;

require_once __DIR__.'/mailHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    app(Settings::class)->set('mail.send_cooldown', 0);
    $this->alice = ml_member('Alice Absender');
    $this->bob = ml_member('Bob Empfang');
    $this->actingAs($this->alice['user']);
});

it('stores title, subject and the inbox link as key', function () {
    $id = ml_send($this, ['subject' => 'Wachplan KW 40', 'to' => [$this->bob['mailbox']->address]])->json('messageId');

    $row = $this->bob['user']->notifications()->sole();
    expect($row->type)->toBe('mail')
        ->and($row->data)->toBe(['title' => 'Neue Mail von Alice Absender', 'message' => 'Wachplan KW 40', 'link' => "/mail/inbox/$id"])
        ->and(MailType::link($id))->toBe("/mail/inbox/$id");
});

it('notifies only after the commit', function () {
    $draft = MailMessage::query()->findOrFail(ml_draft($this, ['to' => [$this->bob['mailbox']->address]]));

    try {
        DB::transaction(function () use ($draft) {
            app(Mailer::class)->send($draft, $this->alice['mailbox'], $this->alice['user'], 'Nie', ['type' => 'doc', 'content' => []], ['to' => [$this->bob['mailbox']->address], 'cc' => [], 'bcc' => []]);
            expect(DB::table('notifications')->count())->toBe(0);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(DB::table('notifications')->count())->toBe(0)->and($draft->fresh()->status)->toBe('draft');
});

it('shows the kind only with mail.use and an open mailbox', function () {
    $kinds = app(NotificationKinds::class);
    $noRight = ml_member('Ohne Recht', []);
    $noMailbox = ml_user(['mail.use']);

    expect($kinds->allowed($this->bob['user']))->toContain('mail')
        ->and($kinds->allowed($noRight['user']))->not->toContain('mail')
        ->and($kinds->allowed($noMailbox))->not->toContain('mail');

    $this->bob['mailbox']->forceFill(['is_locked' => true])->save();
    expect($kinds->allowed($this->bob['user']))->not->toContain('mail');
});

it('marks read and forgets the bell entry only for the own account', function () {
    $max = ml_member('Max Mitglied');
    $group = ml_group('Leitstelle', 'leitstelle', [$this->bob['user'], $max['user']]);
    $id = ml_send($this, ['to' => [$group->address]])->json('messageId');
    expect(ml_notified($id))->toBe(collect([$this->bob['user']->id, $max['user']->id])->sort()->values()->all());

    MailType::markRead($this->bob['user'], $id);
    expect($this->bob['user']->unreadNotifications()->count())->toBe(0)
        ->and($max['user']->unreadNotifications()->count())->toBe(1);

    MailType::forget($this->bob['user'], $id);
    expect(ml_notified($id))->toBe([$max['user']->id]);
});

it('sends the Discord DM without the subject, the bell keeps it', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://discord.com/api/v10/users/@me/channels' => Http::response(['id' => '666666666666666666']),
        'https://discord.com/api/v10/channels/*/messages' => Http::response(['id' => '1']),
    ]);
    $s = app(Settings::class);
    $s->set('discord.bot.enabled', true);
    $s->set('discord.bot.token', 'MAIL.token-value', encrypted: true);
    $s->set('discord.dm_kinds', ['mail', 'system']);
    $this->bob['user']->forceFill(['discord_id' => '555555555555555555'])->save();

    ml_send($this, ['subject' => 'Geheimer Betreff', 'to' => [$this->bob['mailbox']->address]]);

    $posts = Http::recorded(fn (Request $r) => str_contains($r->url(), '/messages'))->values();
    expect($posts)->toHaveCount(1);
    $embed = $posts[0][0]->data()['embeds'][0];
    expect($embed['title'])->toBe('Neue Mail von Alice Absender')
        ->and($embed)->not->toHaveKey('description')
        ->and(json_encode($posts[0][0]->data()))->not->toContain('Geheimer Betreff')
        ->and($this->bob['user']->notifications()->sole()->data['message'])->toBe('Geheimer Betreff');
});
