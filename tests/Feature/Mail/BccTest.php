<?php

use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Mail\Models\MailDelivery;
use Modules\Mail\Support\HeaderVisibility;

require_once __DIR__.'/mailHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    app(Settings::class)->set('mail.send_cooldown', 0);
    $this->alice = ml_member('Alice Absender');
    $this->bob = ml_member('Bob Empfang');
    $this->carla = ml_member('Carla Kopie');
    $this->dora = ml_member('Dora Blind');
    $this->erik = ml_member('Erik Blind');
    $this->actingAs($this->alice['user']);
    $this->id = ml_send($this, [
        'to' => [$this->bob['mailbox']->address, $this->alice['mailbox']->address], 'cc' => [$this->carla['mailbox']->address],
        'bcc' => [$this->dora['mailbox']->address, $this->erik['mailbox']->address],
    ])->json('messageId');
});

function bcc_header(int $messageId, array $member, string $role): array
{
    return HeaderVisibility::for(MailDelivery::query()->where('message_id', $messageId)->where('mailbox_id', $member['mailbox']->id)->where('role', $role)->sole());
}

it('shows the sender copy the whole header', function () {
    expect(bcc_header($this->id, $this->alice, 'sender'))->toBe([
        'to' => [$this->bob['mailbox']->address, $this->alice['mailbox']->address],
        'cc' => [$this->carla['mailbox']->address],
        'bcc' => [$this->dora['mailbox']->address, $this->erik['mailbox']->address],
    ]);
});

it('shows a bcc recipient only itself', function () {
    expect(bcc_header($this->id, $this->dora, 'bcc')['bcc'])->toBe([$this->dora['mailbox']->address])
        ->and(bcc_header($this->id, $this->erik, 'bcc')['bcc'])->toBe([$this->erik['mailbox']->address])
        ->and(bcc_header($this->id, $this->dora, 'bcc')['to'])->toBe([$this->bob['mailbox']->address, $this->alice['mailbox']->address]);
});

it('shows to and cc recipients no bcc, also the sender in its own inbox copy', function () {
    foreach ([[$this->bob, 'to'], [$this->carla, 'cc'], [$this->alice, 'to']] as [$member, $role]) {
        expect(bcc_header($this->id, $member, $role))->toBe([
            'to' => [$this->bob['mailbox']->address, $this->alice['mailbox']->address],
            'cc' => [$this->carla['mailbox']->address],
            'bcc' => [],
        ]);
    }
});

it('never hands out the raw header in a send response', function () {
    expect(json_encode(ml_send($this, ['to' => [$this->bob['mailbox']->address], 'bcc' => [$this->dora['mailbox']->address]])->json()))
        ->not->toContain($this->dora['mailbox']->address);
});
