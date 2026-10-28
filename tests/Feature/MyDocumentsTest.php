<?php

use App\Documents\DocumentListing;
use App\Models\Document;
use App\Models\LegacyDocument;
use App\Models\Personnel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->person = Personnel::factory()->create();
    $this->me = User::factory()->create(['personnel_id' => $this->person->id]);
    $this->other = Personnel::factory()->create();
});

it('lists legacy rows and issued editor documents of the own record, never drafts or foreign ones', function () {
    LegacyDocument::factory()->create(['personnel_id' => $this->person->id, 'public_id' => 'ABCD-EFGH-JKLM', 'type' => 1, 'issuer_name' => 'Chef', 'issued_on' => '2024-05-01']);
    LegacyDocument::factory()->create(['personnel_id' => $this->other->id]);
    Document::factory()->issued()->create(['personnel_id' => $this->person->id, 'public_id' => '0000001', 'title' => 'Urkunde', 'issued_at' => '2025-01-02 10:00:00']);
    Document::factory()->create(['personnel_id' => $this->person->id, 'public_id' => '0000002']);
    Document::factory()->issued()->create(['personnel_id' => $this->other->id]);

    $this->actingAs($this->me)->get('/me/documents')->assertInertia(fn (Assert $p) => $p
        ->component('Documents/Mine')
        ->has('documents.data', 2)
        ->where('documents.data.0.kind', 'editor')
        ->where('documents.data.0.public_id', '0000001')
        ->where('documents.data.0.url', fn ($u) => str_starts_with($u, '/documents/'))
        ->where('documents.data.1.kind', 'legacy')
        ->where('documents.data.1.url', '/personnel/document-view?docid=ABCD-EFGH-JKLM')
        ->where('documents.data.1.issuer', 'Chef'));
});

it('is empty without a linked record and requires login', function () {
    LegacyDocument::factory()->create(['personnel_id' => null]);
    $this->get('/me/documents')->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->get('/me/documents')->assertInertia(fn (Assert $p) => $p->has('documents.data', 0));
});

it('searches by number or issuer and sorts', function () {
    LegacyDocument::factory()->create(['personnel_id' => $this->person->id, 'public_id' => '1111111', 'type' => 5, 'issuer_name' => 'Anna', 'issued_on' => '2024-01-01']);
    LegacyDocument::factory()->create(['personnel_id' => $this->person->id, 'public_id' => '2222222', 'type' => 0, 'issuer_name' => 'Zoe', 'issued_on' => '2024-02-01']);

    $ids = fn (string $q) => collect($this->actingAs($this->me)->get('/me/documents?'.$q)->viewData('page')['props']['documents']['data'])->pluck('public_id')->all();

    expect($ids('q=Zoe'))->toBe(['2222222'])
        ->and($ids('q=111'))->toBe(['1111111'])
        ->and($ids('q=%25'))->toBe([])
        ->and($ids('sort=typ&dir=asc'))->toBe(['2222222', '1111111'])
        ->and($ids('sort=ersteller&dir=asc'))->toBe(['1111111', '2222222'])
        ->and($ids('sort=nr&dir=desc'))->toBe(['2222222', '1111111'])
        ->and($ids('sort=ausgestellt&dir=desc'))->toBe(['2222222', '1111111'])
        ->and($ids('sort=evil;drop&dir=sideways'))->toBe(['2222222', '1111111']);
});

it('pages by 25 with a constant query count', function () {
    LegacyDocument::factory()->count(30)->create(['personnel_id' => $this->person->id, 'issuer_user_id' => $this->me->id]);
    Document::factory()->issued()->count(5)->create(['personnel_id' => $this->person->id, 'issued_by' => $this->me->id]);

    $this->actingAs($this->me)->get('/me/documents')->assertOk();
    DB::enableQueryLog();
    $this->actingAs($this->me)->get('/me/documents')->assertInertia(fn (Assert $p) => $p->has('documents.data', 25)->where('documents.total', 35));
    $first = count(DB::getQueryLog());
    DB::flushQueryLog();
    $this->actingAs($this->me)->get('/me/documents?page=2')->assertInertia(fn (Assert $p) => $p->has('documents.data', 10));
    expect(count(DB::getQueryLog()))->toBe($first);
});

it('gives the dashboard the newest entries', function () {
    LegacyDocument::factory()->count(4)->create(['personnel_id' => $this->person->id, 'issued_on' => '2024-01-01']);
    Document::factory()->issued()->count(3)->create(['personnel_id' => $this->person->id, 'issued_at' => '2025-01-01 00:00:00']);

    $recent = DocumentListing::recentFor($this->me, 5);

    expect($recent)->toHaveCount(5)->and(collect($recent)->take(3)->pluck('kind')->unique()->all())->toBe(['editor']);
});

it('ignores array-valued q and sort instead of failing', function () {
    LegacyDocument::factory()->create(['personnel_id' => $this->person->id]);

    $this->actingAs($this->me)->get('/me/documents?q[]=x&sort[]=x')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->has('documents.data', 1)->where('filters.q', '')->where('filters.sort', 'ausgestellt'));
});
