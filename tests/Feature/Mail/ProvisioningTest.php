<?php

use App\Events\PersonnelDeleted;
use App\Events\PersonnelSaved;
use App\Models\EmsQualification;
use App\Models\FireQualification;
use App\Models\PersonnelTitle;
use App\Models\Rank;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Mail\Models\AddressHistory;
use Modules\Mail\Models\Mailbox;
use Modules\Mail\Models\MailList;
use Modules\Mail\Support\AddressRules;
use Modules\Mail\Support\MailboxProvisioner;

require_once __DIR__.'/mailHelpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function mp_of(int $personnelId): ?Mailbox
{
    return Mailbox::query()->where('personnel_id', $personnelId)->first();
}

it('creates the mailbox when a record is created through the form', function () {
    FireQualification::create(['priority' => 0, 'short_name' => '-', 'name' => 'Keine', 'name_male' => 'Keine', 'name_female' => 'Keine', 'is_none' => true]);
    EmsQualification::create(['priority' => 0, 'name' => 'Keine', 'name_male' => 'Keine', 'name_female' => 'Keine', 'is_none' => true]);

    $this->actingAs(ml_user(['personnel.edit']))->post('/personnel', [
        'full_name' => 'Max von der Heide', 'title_id' => null, 'birth_date' => '1990-01-01', 'gender' => 0,
        'discord_id' => '123456789012345678', 'phone' => '', 'service_number' => 'MX-1', 'hired_at' => '2024-01-01',
        'rank_id' => Rank::factory()->create()->id,
    ])->assertRedirect();

    $mailbox = Mailbox::query()->sole();
    expect($mailbox->address)->toBe('m.von-der-heide@ignis.ef')
        ->and($mailbox->display_name)->toBe('Max von der Heide')
        ->and($mailbox->domain)->toBe('ignis.ef')
        ->and($mailbox->is_active)->toBeTrue()
        ->and($mailbox->kind)->toBe('personal');
});

it('puts the title into the display name but never into the address', function () {
    $dr = PersonnelTitle::create(['name' => 'Dr.', 'priority' => 1]);
    $mailbox = ml_provision(ml_person('Max Muster', ['title_id' => $dr->id]));

    expect($mailbox->address)->toBe('m.muster@ignis.ef')->and($mailbox->display_name)->toBe('Dr. Max Muster');
});

it('follows the address pattern and counts duplicates', function () {
    app(Settings::class)->set('mail.address_pattern', 'first_dot_last');
    expect(ml_provision(ml_person('Jörg Müller'))->address)->toBe('joerg.mueller@ignis.ef')
        ->and(ml_provision(ml_person('Jörg Müller'))->address)->toBe('joerg.mueller2@ignis.ef');

    app(Settings::class)->set('mail.address_pattern', 'initial_dot_last');
    expect(ml_provision(ml_person('Anna Schmidt'))->address)->toBe('a.schmidt@ignis.ef')
        ->and(ml_provision(ml_person('Anja Schmidt'))->address)->toBe('an.schmidt@ignis.ef');
});

it('treats list addresses and former addresses as taken', function () {
    MailList::query()->forceCreate(['address' => 'k.lang@ignis.ef', 'name' => 'Liste', 'kind' => 'static']);
    $owner = ml_provision(ml_person('Karl Kurz'));
    AddressHistory::query()->forceCreate(['address' => 'k.lang2@ignis.ef', 'mailbox_id' => $owner->id]);
    expect(ml_provision(ml_person('Klaus Lang'))->address)->toBe('kl.lang@ignis.ef');

    app(Settings::class)->set('mail.address_pattern', 'first_dot_last');
    AddressHistory::query()->forceCreate(['address' => 'otto.berg@ignis.ef', 'mailbox_id' => $owner->id]);
    expect(ml_provision(ml_person('Otto Berg'))->address)->toBe('otto.berg2@ignis.ef');
});

it('deactivates in an archive rank and reactivates on return, keeping the address', function () {
    $person = ml_person('Eva Ende');
    $mailbox = ml_provision($person);

    $person->update(['rank_id' => Rank::factory()->archive()->create()->id]);
    event(new PersonnelSaved($person->id));
    expect($mailbox->refresh()->is_active)->toBeFalse();

    $person->update(['rank_id' => Rank::factory()->create()->id, 'full_name' => 'Eva Neu']);
    event(new PersonnelSaved($person->id));
    $mailbox->refresh();
    expect($mailbox->is_active)->toBeTrue()
        ->and($mailbox->display_name)->toBe('Eva Neu')
        ->and($mailbox->address)->toBe('e.ende@ignis.ef');
});

it('keeps a lock through every save', function () {
    $person = ml_person('Lena Lock');
    $mailbox = ml_provision($person);
    $mailbox->forceFill(['is_locked' => true])->save();

    event(new PersonnelSaved($person->id));
    app(MailboxProvisioner::class)->sync($person->id);

    expect($mailbox->refresh()->is_locked)->toBeTrue()->and($mailbox->is_active)->toBeTrue();
});

it('deactivates on delete and keeps the mailbox', function () {
    $person = ml_person('Gerd Weg');
    $mailbox = ml_provision($person);

    $this->actingAs(ml_user(['personnel.delete']))->delete("/personnel/{$person->id}")->assertRedirect('/personnel');

    $mailbox->refresh();
    expect($mailbox->personnel_id)->toBeNull()
        ->and($mailbox->is_active)->toBeFalse()
        ->and($mailbox->address)->toBe('g.weg@ignis.ef');
});

it('deactivates on delete only the mailbox of that record, not an orphan reactivated before', function () {
    $old = ml_provision(ml_person('Olga Alt'));
    $old->forceFill(['personnel_id' => null, 'is_active' => true])->save();
    $person = ml_person('Gerd Weg');
    $mailbox = ml_provision($person);
    $rollback = ml_person('Rolf Zurück');
    $kept = ml_provision($rollback);

    $person->delete();
    event(new PersonnelDeleted($person->id));
    expect($mailbox->refresh()->is_active)->toBeFalse()->and($old->refresh()->is_active)->toBeTrue();

    try {
        DB::transaction(function () use ($rollback) {
            $rollback->delete();
            throw new RuntimeException('abort');
        });
    } catch (RuntimeException) {
    }
    expect($kept->refresh()->is_active)->toBeTrue()->and($kept->personnel_id)->toBe($rollback->id);
});

it('backfills, is idempotent and deactivates orphans; the dry run writes nothing', function () {
    $person = ml_person('Bernd Bestand');
    $orphan = Mailbox::query()->forceCreate(['address' => 'weg@ignis.ef', 'display_name' => 'Weg', 'domain' => 'ignis.ef']);

    $this->artisan('mail:backfill --dry-run')->expectsOutputToContain('1 synced, 1 created, 0 without mailbox, 1 deactivated (record deleted) (dry run, nothing written)')->assertSuccessful();
    expect(Mailbox::query()->count())->toBe(1)->and($orphan->refresh()->is_active)->toBeTrue();

    $this->artisan('mail:backfill')->expectsOutputToContain('1 synced, 1 created, 0 without mailbox, 1 deactivated')->assertSuccessful();
    expect(mp_of($person->id)?->address)->toBe('b.bestand@ignis.ef')->and($orphan->refresh()->is_active)->toBeFalse();

    $this->artisan('mail:backfill')->expectsOutputToContain('1 synced, 0 created, 0 without mailbox, 0 deactivated')->assertSuccessful();
    $this->artisan('mail:backfill --dry-run')->expectsOutputToContain('0 created')->assertSuccessful();
    expect(Mailbox::query()->count())->toBe(2);
});

it('runs the backfill nightly at 03:15, only while the module is on', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'mail:backfill'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('15 3 * * *')->and($event->filtersPass(app()))->toBeTrue();
    app(Settings::class)->set('modules.mail.enabled', false);
    expect($event->filtersPass(app()))->toBeFalse();
});

// ---- beyond the legacy file ----

it('wires the listener to both events, and not while the module is off', function () {
    $person = ml_person('Willi Wire');
    event(new PersonnelSaved($person->id));
    expect(mp_of($person->id))->not->toBeNull();

    $mailbox = mp_of($person->id);
    $person->delete();
    event(new PersonnelDeleted($person->id));
    expect($mailbox->refresh()->is_active)->toBeFalse();

    app(Settings::class)->set('modules.mail.enabled', false);
    $other = ml_person('Otto Off');
    event(new PersonnelSaved($other->id));
    expect(mp_of($other->id))->toBeNull();
    $gone = ml_person('Gina Gone');
    app(Settings::class)->set('modules.mail.enabled', true);
    $kept = ml_provision($gone);
    app(Settings::class)->set('modules.mail.enabled', false);
    $gone->delete();
    expect($kept->refresh()->is_active)->toBeTrue();
    $this->artisan('mail:backfill')->expectsOutputToContain('Modul mail ist deaktiviert')->assertSuccessful();
    expect(mp_of($other->id))->toBeNull();
});

it('binds a new mailbox to the linked account at once', function () {
    $person = ml_person('Bea Bind');
    $user = ml_user(['mail.use'], $person);

    expect(ml_provision($person)->user_id)->toBe($user->id);
});

it('logs and skips a record whose name yields no address', function () {
    expect(app(MailboxProvisioner::class)->sync(ml_person('!!! ???')->id))->toBeNull()
        ->and(Mailbox::query()->count())->toBe(0);
});

it('validates hand-set addresses against form, domains and the shared address space', function () {
    app(Settings::class)->set('mail.allowed_domains', ['feuerwehr.example']);
    $box = ml_provision(ml_person('Max Muster'));
    MailList::query()->forceCreate(['address' => 'alle@ignis.ef', 'name' => 'Alle', 'kind' => 'static']);
    AddressRules::reserve('alt@ignis.ef', $box->id);

    expect(AddressRules::allowedDomains())->toBe(['ignis.ef', 'feuerwehr.example'])
        ->and(AddressRules::validate('neu@feuerwehr.example'))->toBeNull()
        ->and(AddressRules::validate('neu'))->toBe(__('mail.address.invalid'))
        ->and(AddressRules::validate(str_repeat('a', 65).'@ignis.ef'))->toBe(__('mail.address.too_long', ['max' => 64]))
        ->and(AddressRules::validate('Neu@ignis.ef'))->toBe(__('mail.address.local'))
        ->and(AddressRules::validate('a..b@ignis.ef'))->toBe(__('mail.address.local'))
        ->and(AddressRules::validate('neu@other.ef'))->toContain('other.ef')
        ->and(AddressRules::validate('neu@other.ef', keepDomain: 'other.ef'))->toBeNull()
        ->and(AddressRules::validate('m.muster@ignis.ef'))->toContain('m.muster@ignis.ef')
        ->and(AddressRules::validate('m.muster@ignis.ef', exceptMailboxId: $box->id))->toBeNull()
        ->and(AddressRules::validate('alle@ignis.ef'))->not->toBeNull()
        ->and(AddressRules::isTaken('ALT@ignis.ef'))->toBeTrue()
        ->and(AddressRules::isTaken('alt@ignis.ef', exceptMailboxId: $box->id))->toBeFalse()
        ->and(AddressRules::isTaken('alt@ignis.ef', exceptMailboxId: $box->id + 1))->toBeTrue();
});

it('falls back to the default domain when the setting is invalid', function () {
    app(Settings::class)->set('mail.domain', 'not a domain');
    expect(AddressRules::defaultDomain())->toBe('ignis.ef');
    app(Settings::class)->set('mail.domain', 'Wache.Example');
    expect(ml_provision(ml_person('Dora Domain'))->address)->toBe('d.domain@wache.example');
});
