<?php

use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Mail\Models\MailMessage;

require_once __DIR__.'/mailHelpers.php';

uses(RefreshDatabase::class)->group('mariadb');

beforeEach(fn () => $this->seed(RoleSeeder::class));

function cd_mail(string $address): array
{
    return ['subject' => 'Lagemeldung', 'body_json' => ml_doc('Text'), 'to' => [$address]];
}

it('answers a second mail within the pause with 429 and Retry-After', function () {
    app(Settings::class)->set('mail.send_cooldown', 10);
    $sender = ml_member('Schnell Schreiber');
    $to = ml_member('Geduldig Leser');
    $this->actingAs($sender['user']);

    ml_send($this, cd_mail($to['mailbox']->address));
    $draft = ml_draft($this, cd_mail($to['mailbox']->address));
    $this->putJson("/mail/drafts/$draft", cd_mail($to['mailbox']->address))->assertOk();

    $again = $this->postJson("/mail/drafts/$draft/send", cd_mail($to['mailbox']->address))->assertStatus(429);
    $wait = $again->json('retry_after');
    expect($wait)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(10)
        ->and($again->headers->get('Retry-After'))->toBe((string) $wait)
        ->and($again->json('message'))->toBe(trans_choice('mail.send.cooldown', $wait, ['seconds' => $wait]))
        ->and($again->json('message'))->toStartWith("Bitte warte noch $wait Sekunde")->toEndWith('bis zur nächsten Mail.')
        ->and(MailMessage::query()->findOrFail($draft)->status)->toBe('draft')
        ->and(MailMessage::query()->where('sender_mailbox_id', $sender['mailbox']->id)->where('status', 'sent')->count())->toBe(1);
});

it('sends again after the pause', function () {
    app(Settings::class)->set('mail.send_cooldown', 10);
    $sender = ml_member('Spaeter Schreiber');
    $to = ml_member('Spaeter Leser');
    $this->actingAs($sender['user']);

    $first = ml_send($this, cd_mail($to['mailbox']->address))->json('messageId');
    MailMessage::query()->whereKey($first)->update(['sent_at' => now()->subSeconds(11)]);

    ml_send($this, cd_mail($to['mailbox']->address));
});

it('switches the pause off with zero', function () {
    app(Settings::class)->set('mail.send_cooldown', 0);
    $sender = ml_member('Ohne Pause');
    $to = ml_member('Viel Post');
    $this->actingAs($sender['user']);

    foreach ([1, 2, 3] as $_) {
        ml_send($this, cd_mail($to['mailbox']->address));
    }
});

it('counts the pause per mailbox, a group mailbox included', function () {
    app(Settings::class)->set('mail.send_cooldown', 10);
    $first = ml_member('Erstes Postfach');
    $second = ml_member('Zweites Postfach');
    $group = ml_group('Leitstelle', 'leitstelle', [$first['user']]);

    $this->actingAs($first['user']);
    ml_send($this, cd_mail($second['mailbox']->address));
    $id = ml_draft($this, ['mailbox_id' => $group->id] + cd_mail($second['mailbox']->address));
    $this->postJson("/mail/drafts/$id/send", ['mailbox_id' => $group->id])->assertOk();
    $id = ml_draft($this, ['mailbox_id' => $group->id] + cd_mail($second['mailbox']->address));
    $this->postJson("/mail/drafts/$id/send", ['mailbox_id' => $group->id])->assertStatus(429);

    $this->actingAs($second['user']);
    ml_send($this, cd_mail($first['mailbox']->address));
});
