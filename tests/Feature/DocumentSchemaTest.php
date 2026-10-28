<?php

use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\LegacyDocument;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('creates the document tables and columns', function () {
    expect(Schema::getColumnListing('document_templates'))->toEqualCanonicalizing(['id', 'name', 'category', 'content', 'is_active', 'created_by', 'created_at', 'updated_at'])
        ->and(Schema::getColumnListing('documents'))->toEqualCanonicalizing(['id', 'public_id', 'template_id', 'personnel_id', 'title', 'content', 'status', 'frozen_values', 'pdf_path', 'lock_version', 'created_by', 'issued_by', 'issued_at', 'created_at', 'updated_at'])
        ->and(Schema::getColumnListing('legacy_documents'))->toEqualCanonicalizing(['id', 'legacy_id', 'public_id', 'type', 'personnel_id', 'recipient_name', 'recipient_discord_id', 'salutation', 'recipient_birthdate', 'issued_on', 'issuer_user_id', 'issuer_discord_id', 'issuer_name', 'is_archived', 'pdf_path', 'pdf_generated_at', 'legacy_payload', 'created_at']);
});

it('declares the unique indexes', function () {
    $unique = fn (string $table) => collect(Schema::getIndexes($table))->where('unique', true)->pluck('columns')->map(fn ($c) => implode(',', $c))->all();

    expect($unique('documents'))->toContain('public_id')
        ->and($unique('legacy_documents'))->toContain('public_id')->toContain('legacy_id');
});

it('rejects a duplicate public id in either table', function (string $model) {
    $model::factory()->create(['public_id' => '1234567']);
    $model::factory()->create(['public_id' => '1234567']);
})->with([[Document::class], [LegacyDocument::class]])->throws(QueryException::class);

it('allows many legacy documents without a legacy id but not a repeated one', function () {
    LegacyDocument::factory()->count(2)->create(['legacy_id' => null]);
    LegacyDocument::factory()->create(['legacy_id' => 5]);

    expect(fn () => LegacyDocument::factory()->create(['legacy_id' => 5]))->toThrow(QueryException::class);
});

it('keeps a record that still has documents (restrict)', function (string $model) {
    $doc = $model::factory()->create();

    expect(fn () => $doc->personnel->delete())->toThrow(QueryException::class)
        ->and(Personnel::count())->toBe(1)->and($model::count())->toBe(1);
})->with([[Document::class], [LegacyDocument::class]]);

it('deletes a record once its documents are gone', function () {
    $doc = Document::factory()->create();
    $personnel = $doc->personnel;
    $doc->delete();
    $personnel->delete();

    expect(Personnel::count())->toBe(0);
});

it('nulls template_id when the template goes', function () {
    $doc = Document::factory()->create();
    $doc->template->delete();

    expect($doc->fresh()->template_id)->toBeNull();
});

it('nulls the creator, issuer and legacy issuer when a user goes', function () {
    $user = User::factory()->create();
    $doc = Document::factory()->create(['created_by' => $user->id, 'issued_by' => $user->id]);
    $legacy = LegacyDocument::factory()->create(['issuer_user_id' => $user->id]);
    $template = DocumentTemplate::factory()->create(['created_by' => $user->id]);
    $user->delete();

    expect($doc->fresh()->created_by)->toBeNull()->and($doc->fresh()->issued_by)->toBeNull()
        ->and($legacy->fresh()->issuer_user_id)->toBeNull()->and($template->fresh()->created_by)->toBeNull();
});

it('casts content, frozen values, status and dates', function () {
    $doc = Document::factory()->create(['frozen_values' => ['a' => 'b'], 'issued_at' => '2026-10-18 10:00:00'])->fresh();

    expect($doc->content)->toBeArray()->and($doc->frozen_values)->toBe(['a' => 'b'])
        ->and($doc->status)->toBe('draft')->and($doc->lock_version)->toBe(0)
        ->and($doc->issued_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and(DocumentTemplate::factory()->create()->fresh()->is_active)->toBeTrue();

    $legacy = LegacyDocument::factory()->create(['issued_on' => '2025-01-02', 'legacy_payload' => ['inhalt' => 'x']])->fresh();
    expect($legacy->issued_on->toDateString())->toBe('2025-01-02')->and($legacy->legacy_payload)->toBe(['inhalt' => 'x'])
        ->and($legacy->is_archived)->toBeFalse();
});

it('exposes the relations', function () {
    $user = User::factory()->create();
    $doc = Document::factory()->create(['created_by' => $user->id, 'issued_by' => $user->id]);
    $legacy = LegacyDocument::factory()->create(['personnel_id' => $doc->personnel_id]);

    expect($doc->personnel)->toBeInstanceOf(Personnel::class)
        ->and($doc->template)->toBeInstanceOf(DocumentTemplate::class)
        ->and($doc->creator->is($user))->toBeTrue()->and($doc->issuer->is($user))->toBeTrue()
        ->and($doc->personnel->documents->pluck('id')->all())->toBe([$doc->id])
        ->and($doc->personnel->legacyDocuments->pluck('id')->all())->toBe([$legacy->id])
        ->and($doc->template->documents->pluck('id')->all())->toBe([$doc->id]);
});

it('defines the document gates per the legacy rights', function () {
    $as = function (string ...$perms): User {
        $role = Role::create(['name' => 'T'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
        $role->syncPermissions($perms);

        return User::factory()->withRole($role)->create();
    };
    $can = fn (User $u) => [Gate::forUser($u)->allows('document.view'), Gate::forUser($u)->allows('document.manage'), Gate::forUser($u)->allows('document.viewAudit')];

    expect($can($as('personnel.view')))->toBe([false, false, false])
        ->and($can($as('personnel.documents.view')))->toBe([true, false, false])
        ->and($can($as('personnel.documents.manage')))->toBe([true, true, false])
        ->and($can($as('admin')))->toBe([true, true, true]);
});
