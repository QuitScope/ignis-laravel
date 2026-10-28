<?php

use App\Models\EmsQualification;
use App\Models\FireQualification;
use App\Models\Personnel;
use App\Models\PersonnelLogEntry;
use App\Models\PersonnelTitle;
use App\Models\Rank;
use App\Models\RegistrationCode;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the personnel tables and columns', function () {
    expect(Schema::getColumnListing('ranks'))->toEqualCanonicalizing(['id', 'priority', 'name', 'name_male', 'name_female', 'badge', 'is_archive', 'created_at', 'updated_at'])
        ->and(Schema::getColumnListing('fire_qualifications'))->toEqualCanonicalizing(['id', 'priority', 'short_name', 'name', 'name_male', 'name_female', 'is_none', 'created_at', 'updated_at'])
        ->and(Schema::getColumnListing('ems_qualifications'))->toEqualCanonicalizing(['id', 'priority', 'name', 'name_male', 'name_female', 'abbreviation', 'is_none', 'is_trainable', 'created_at', 'updated_at'])
        ->and(Schema::getColumnListing('specialties'))->toEqualCanonicalizing(['id', 'number', 'name', 'is_disabled', 'created_at', 'updated_at'])
        ->and(Schema::getColumnListing('personnel_titles'))->toEqualCanonicalizing(['id', 'name', 'priority', 'created_at', 'updated_at'])
        ->and(Schema::getColumnListing('personnel'))->toEqualCanonicalizing(['id', 'title_id', 'full_name', 'birth_date', 'character_id', 'gender', 'forum_profile', 'discord_id', 'phone', 'service_number', 'hired_at', 'rank_id', 'fire_qualification_id', 'ems_qualification_id', 'extra_qualification', 'profile_picture', 'created_at', 'updated_at'])
        ->and(Schema::getColumnListing('personnel_specialty'))->toEqualCanonicalizing(['id', 'personnel_id', 'specialty_id'])
        ->and(Schema::getColumnListing('personnel_log_entries'))->toEqualCanonicalizing(['id', 'personnel_id', 'type', 'content', 'author', 'metadata', 'created_at', 'legacy_id']);
});

it('declares the unique indexes', function () {
    $unique = fn (string $table) => collect(Schema::getIndexes($table))->where('unique', true)->pluck('columns')->map(fn ($c) => implode(',', $c))->all();

    expect($unique('personnel'))->toContain('service_number')
        ->and($unique('users'))->toContain('personnel_id');
});

it('rejects a second record with the same service number', function () {
    Personnel::factory()->create(['service_number' => 'A-1']);
    Personnel::factory()->create(['service_number' => 'A-1']);
})->throws(QueryException::class);

it('rejects two accounts on the same personnel record', function () {
    $p = Personnel::factory()->create();
    User::factory()->create(['personnel_id' => $p->id]);
    User::factory()->create(['personnel_id' => $p->id]);
})->throws(QueryException::class);

it('rejects deleting a rank in use', function () {
    $p = Personnel::factory()->create();
    $p->rank->delete();
})->throws(QueryException::class);

it('cascades log and pivot and nulls account links when a record is deleted', function () {
    $p = Personnel::factory()->create();
    $p->specialties()->attach(Specialty::create(['number' => 211, 'name' => 'Leitstelle']));
    $p->logEntries()->create(['type' => PersonnelLogEntry::NOTE, 'content' => 'x', 'author' => 'me']);
    $user = User::factory()->create(['personnel_id' => $p->id]);
    $code = RegistrationCode::generate(null, 'c', null);
    $code->forceFill(['personnel_id' => $p->id])->save();

    $p->delete();

    expect(PersonnelLogEntry::count())->toBe(0)
        ->and(DB::table('personnel_specialty')->count())->toBe(0)
        ->and($user->fresh()->personnel_id)->toBeNull()
        ->and($code->fresh()->personnel_id)->toBeNull();
});

it('keeps existing user constraints intact', function () {
    $boss = User::factory()->create();
    $u = User::factory()->create(['deactivated_by' => $boss->id]);
    $boss->delete();

    expect($u->fresh()->deactivated_by)->toBeNull();
});

it('labels ranks and qualifications by gender', function () {
    $rank = new Rank(['name' => 'Brandmeister/-in', 'name_male' => 'Brandmeister', 'name_female' => 'Brandmeisterin']);
    $fire = new FireQualification(['name' => 'A/-in', 'name_male' => 'A', 'name_female' => 'Ain']);
    $ems = new EmsQualification(['name' => 'N/-in', 'name_male' => 'N', 'name_female' => 'Nin']);

    expect($rank->labelFor(0))->toBe('Brandmeister')
        ->and($rank->labelFor(1))->toBe('Brandmeisterin')
        ->and($rank->labelFor(2))->toBe('Brandmeister/-in')
        ->and($fire->labelFor(1))->toBe('Ain')
        ->and($ems->labelFor(0))->toBe('N');
});

it('builds the display name with title', function () {
    $title = PersonnelTitle::create(['name' => 'Dr.', 'priority' => 10]);

    expect(Personnel::factory()->make(['full_name' => 'Max Muster'])->displayName())->toBe('Max Muster')
        ->and(Personnel::factory()->create(['full_name' => 'Max Muster', 'title_id' => $title->id])->displayName())->toBe('Dr. Max Muster');
});

it('hides archived ranks from active()', function () {
    $archive = Rank::factory()->archive()->create();
    $active = Personnel::factory()->create();
    $gone = Personnel::factory()->create(['rank_id' => $archive->id]);

    expect(Personnel::active()->pluck('id')->all())->toBe([$active->id])
        ->and(Personnel::archived()->pluck('id')->all())->toBe([$gone->id])
        ->and($archive->isArchive())->toBeTrue();
});

it('relates user and personnel', function () {
    $p = Personnel::factory()->create();
    $u = User::factory()->create(['personnel_id' => $p->id]);

    expect($p->user->is($u))->toBeTrue()
        ->and($u->personnel->is($p))->toBeTrue();
});
