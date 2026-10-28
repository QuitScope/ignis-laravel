<?php

use App\Import\AccountLinkImporter;
use App\Import\MailImporter;
use App\Import\ManvImporter;
use App\Models\Personnel;
use App\Models\User;
use Database\Seeders\PersonnelDefaultsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LegacyDatabase;
use Tests\Support\LegacySeed;

uses(RefreshDatabase::class)->group('legacy');

beforeEach(function () {
    if (! LegacySeed::boot()) {
        $this->markTestSkipped('LEGACY_TEST_DB_HOST not set');
    }
    $this->seed(RoleSeeder::class);
    $this->seed(PersonnelDefaultsSeeder::class);
    $db = LegacyDatabase::connection();
    if ($db->select("SHOW INDEX FROM intra_users WHERE Key_name = 'uniq_users_aktenid'") !== []) {
        $db->statement('ALTER TABLE intra_users ADD INDEX test_aktenid (aktenid), DROP INDEX uniq_users_aktenid');
    }
    LegacySeed::personnel([
        ['id' => 1, 'fullname' => 'Anna Alpha'],
        ['id' => 3, 'fullname' => 'Carl Charlie'],
        ['id' => 5, 'dienstgrad' => 999],
    ]);
});

afterEach(function () {
    if (! LegacyDatabase::available()) {
        return;
    }
    $db = LegacyDatabase::connection();
    if ($db->select("SHOW INDEX FROM intra_users WHERE Key_name = 'test_aktenid'") !== []) {
        $db->statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            $db->table('intra_users')->delete();
            $db->statement('ALTER TABLE intra_users ADD UNIQUE INDEX uniq_users_aktenid (aktenid), DROP INDEX test_aktenid');
        } finally {
            $db->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
    expect($db->select("SHOW INDEX FROM intra_users WHERE Key_name = 'uniq_users_aktenid'"))->not->toBe([]);
});

function newPersonnelId(int $legacyId): int
{
    return (int) DB::table('legacy_id_map')->where(['entity' => 'personnel', 'legacy_id' => (string) $legacyId])->value('new_id');
}

it('links accounts and invitations to records, first account wins, names follow the record', function () {
    LegacySeed::users([
        ['id' => 1, 'aktenid' => 1, 'fullname' => 'Old Name'],
        ['id' => 2, 'aktenid' => 1],
        ['id' => 3, 'aktenid' => 3],
        ['id' => 4],
        ['id' => 5, 'aktenid' => 5],
    ]);
    LegacySeed::codes([['id' => 1, 'mitarbeiter_id' => 3], ['id' => 2]]);

    $json = LegacySeed::run(['account_link']);

    $user = fn (int $legacy) => User::where('discord_id', (string) (100000 + $legacy))->first();
    expect($json['entities']['account_link'])->toBe(['read' => 5, 'written' => 3, 'skipped' => 2, 'failed' => 0])
        ->and($user(1)->personnel_id)->toBe(newPersonnelId(1))->and($user(1)->name)->toBe('Anna Alpha')
        ->and($user(2)->personnel_id)->toBeNull()
        ->and($user(3)->personnel_id)->toBe(newPersonnelId(3))->and($user(3)->name)->toBe('Carl Charlie')
        ->and($user(4)->personnel_id)->toBeNull()->and($user(4)->name)->toBe('Full 4')
        ->and($user(5)->personnel_id)->toBeNull()
        ->and(DB::table('registration_codes')->where('code', 'CODE1')->value('personnel_id'))->toBe(newPersonnelId(3))
        ->and(DB::table('registration_codes')->where('code', 'CODE2')->value('personnel_id'))->toBeNull()
        ->and(implode("\n", $json['warnings']))->toContain('already linked to another account')
        ->and(implode("\n", $json['notes']))->not->toContain('warten auf den Personal-Import');

    $again = LegacySeed::run(['account_link']);
    expect($again['entities']['account_link'])->toBe($json['entities']['account_link']);
});

it('does not move a link that exists in the new instance', function () {
    LegacySeed::users([['id' => 1, 'aktenid' => 1]]);
    LegacySeed::run(['personnel']);
    $other = User::factory()->create(['personnel_id' => newPersonnelId(1)]);

    $json = LegacySeed::run(['account_link']);

    expect($other->fresh()->personnel_id)->toBe(newPersonnelId(1))
        ->and(User::where('discord_id', '100001')->value('personnel_id'))->toBeNull()
        ->and($json['entities']['account_link']['skipped'])->toBe(1);
});

it('keeps the notice only when the account link importer is not registered', function () {
    LegacySeed::users([['id' => 1, 'aktenid' => 1]]);

    $with = LegacySeed::run(['user']);
    config(['ignis.importers' => array_values(array_diff(config('ignis.importers'), [AccountLinkImporter::class, ManvImporter::class, MailImporter::class]))]);
    $without = LegacySeed::run(['user']);

    expect(implode("\n", $with['notes']))->not->toContain('warten auf den Personal-Import')
        ->and(implode("\n", $without['notes']))->toContain('1 Konten mit Aktenverknüpfung warten auf den Personal-Import');
});

it('dry run links nothing', function () {
    LegacySeed::users([['id' => 1, 'aktenid' => 1]]);

    LegacySeed::run(['account_link'], dry: true);

    expect(Personnel::count())->toBe(0)->and(User::whereNotNull('personnel_id')->count())->toBe(0);
});
