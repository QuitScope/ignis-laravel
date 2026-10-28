<?php

use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Mail\Models\MailDelivery;
use Modules\Mail\Models\MailMessage;

require_once __DIR__.'/mailHelpers.php';

uses(RefreshDatabase::class);

function ms_hits(string $q): array
{
    return test()->getJson('/api/system/global-search?'.http_build_query(['q' => $q]))->assertOk()
        ->collect('results')->firstWhere('key', 'mails')['items'] ?? [];
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    app(Settings::class)->set('mail.send_cooldown', 0);
    $this->alice = ml_member('Alice Absender');
    $this->bob = ml_member('Bob Empfang');
    $this->dora = ml_member('Dora Blind');
});

it('finds subject, sender and plain text of the own copy and links to the real copy', function () {
    $this->actingAs($this->alice['user']);
    $id = ml_send($this, ['subject' => 'Funkprobe Dienstag', 'body_json' => ml_doc('Treffpunkt Fahrzeughalle'),
        'to' => [$this->bob['mailbox']->address], 'bcc' => [$this->dora['mailbox']->address]])->json('messageId');

    $this->actingAs($this->bob['user']);
    $hits = ms_hits('Funkprobe');
    expect($hits)->toHaveCount(1)
        ->and($hits[0]['label'])->toBe('Funkprobe Dienstag')
        ->and($hits[0]['href'])->toBe("/mail/inbox/$id?mailbox={$this->bob['mailbox']->id}")
        ->and(ms_hits('Fahrzeughalle'))->toHaveCount(1)
        ->and(ms_hits('Alice'))->toHaveCount(1)
        ->and(ms_hits('<p>'))->toBe([])
        ->and(ms_hits('Dora'))->toBe([])
        ->and(ms_hits('%'))->toBe([])
        ->and(ms_hits('Funk_robe'))->toBe([]);

    $this->actingAs($this->dora['user']);
    expect(ms_hits('Funkprobe'))->toHaveCount(1)->and(ms_hits('bob.empfang'))->toBe([]);
});

it('never shows foreign mails, not even to admins or super admins, and not deleted copies', function () {
    $this->actingAs($this->alice['user']);
    $id = ml_send($this, ['subject' => 'Geheimsache', 'to' => [$this->bob['mailbox']->address]])->json('messageId');

    $this->actingAs(ml_member('Fremd Eins', ['mail.use', 'mail.admin', 'admin'])['user']);
    expect(ms_hits('Geheimsache'))->toBe([]);
    $super = ml_member('Fremd Zwei')['user'];
    $super->forceFill(['is_super_admin' => true])->save();
    $this->actingAs($super);
    expect(ms_hits('Geheimsache'))->toBe([]);

    $this->actingAs($this->bob['user'])->deleteJson("/mail/messages/$id")->assertOk();
    expect(ms_hits('Geheimsache'))->toBe([]);
});

it('matches drafts by subject only', function () {
    $this->actingAs($this->alice['user']);
    ml_draft($this, ['subject' => 'Entwurf Plan', 'body_json' => ml_doc('Geheimtext')]);

    expect(ms_hits('Entwurf'))->toHaveCount(1)->and(ms_hits('Geheimtext'))->toBe([])->and(ms_hits('Alice'))->toBe([]);
});

it('searches the group mailboxes the account opens and nothing of a locked one', function () {
    $group = ml_group('Leitstelle', 'leitstelle', [$this->bob['user']]);
    $this->actingAs($this->alice['user']);
    $id = ml_send($this, ['subject' => 'Gruppenlage', 'to' => [$group->address]])->json('messageId');

    $this->actingAs($this->bob['user']);
    expect(ms_hits('Gruppenlage')[0]['href'])->toBe("/mail/inbox/$id?mailbox={$group->id}")
        ->and(MailDelivery::query()->where('mailbox_id', $group->id)->count())->toBe(1);

    $group->forceFill(['is_locked' => true])->save();
    expect(ms_hits('Gruppenlage'))->toBe([]);
});

it('is not offered without mail.use or a mailbox', function () {
    $this->actingAs(ml_user(['mail.use']));
    $this->getJson('/api/system/global-search?q=xyz')->assertOk()->assertJsonMissing(['key' => 'mails']);
    $this->actingAs(ml_member('Ohne Recht', [])['user']);
    $this->getJson('/api/system/global-search?q=xyz')->assertOk()->assertJsonMissing(['key' => 'mails']);
});

it('dates a hit in the organisation time zone, not in UTC', function () {
    config(['ignis.timezone' => 'Europe/Berlin']);
    $this->actingAs($this->alice['user']);
    $id = ml_send($this, ['subject' => 'Nachtschicht', 'body_json' => ml_doc('x'), 'to' => [$this->bob['mailbox']->address]])->json('messageId');
    MailMessage::query()->whereKey($id)->update(['sent_at' => '2026-01-14 23:30:00']);

    $this->actingAs($this->bob['user']);
    expect(ms_hits('Nachtschicht')[0]['sub'])->toEndWith('15.01.2026');
});
