<?php

use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\LegacyDocument;
use App\Models\User;
use Database\Seeders\DocumentTemplatesSeeder;
use Database\Seeders\PersonnelDefaultsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DocJson;
use Tests\Support\LegacyDatabase;
use Tests\Support\LegacySeed;

uses(RefreshDatabase::class)->group('legacy');

const DOC_SHARED_DISCORD = '555555555555555555';

beforeEach(function () {
    if (! LegacySeed::boot()) {
        $this->markTestSkipped('LEGACY_TEST_DB_HOST not set');
    }
    $this->seed(RoleSeeder::class);
    $this->seed(PersonnelDefaultsSeeder::class);
    $this->seed(DocumentTemplatesSeeder::class);
    Storage::fake('documents');
    $this->uploads = sys_get_temp_dir().'/ignis-legacy-docs-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->uploads.'/storage/documents');
    File::ensureDirectoryExists($this->uploads.'/storage/private/editor-documents');
});

afterEach(fn () => isset($this->uploads) && File::deleteDirectory($this->uploads));

/** @return array<string, mixed> a valid editor document with one locked section */
function docSectionDoc(string $text, string $id = 's1'): array
{
    return DocJson::doc(DocJson::section([DocJson::para([DocJson::text($text, 'bold'), DocJson::variable('mitarbeiter.name')])], 'locked', $id));
}

function seedDocumentCases(string $uploads): void
{
    LegacySeed::users([['id' => 1], ['id' => 2, 'discord_id' => DOC_SHARED_DISCORD], ['id' => 3, 'discord_id' => DOC_SHARED_DISCORD]]);
    LegacySeed::personnel([['id' => 1, 'dienstnr' => '100'], ['id' => 2, 'dienstnr' => '200'], ['id' => 5, 'dienstnr' => '500', 'dienstgrad' => 999]]);

    LegacySeed::documentTemplates([
        ['id' => 1, 'name' => 'BEFÖRDERUNGSURKUNDE', 'category' => 'Urkunde alt', 'content' => json_encode(docSectionDoc('Alt')),
            'created_by' => 1, 'created_at' => '2026-09-15 12:00:00', 'updated_at' => '2026-10-01 08:30:00'],
        ['id' => 2, 'name' => 'Beförderungsurkunde', 'content' => json_encode(docSectionDoc('Zweite', 's9')), 'is_active' => 0, 'created_by' => 3],
        ['id' => 3, 'name' => 'Kaputt', 'content' => json_encode(DocJson::doc(DocJson::para([DocJson::text('oben')])))],
    ]);

    $valid = json_encode(docSectionDoc('Inhalt'));
    LegacySeed::documents([
        ['id' => 1, 'docid' => '0000123', 'template_id' => 1, 'mitarbeiter_id' => 1, 'title' => 'Urkunde Anna', 'content' => json_encode(docSectionDoc('Anna')),
            'status' => 'ausgestellt', 'frozen_values' => '{"mitarbeiter.name":"Anna","datum":"20.09.2026"}', 'created_by' => 1, 'issued_by' => 3,
            'issued_at' => '2026-09-20 14:00:00', 'created_at' => '2026-09-20 12:00:00', 'updated_at' => '2026-09-20 14:00:00',
            'pdf_path' => 'storage/private/editor-documents/0000123.pdf'],
        ['id' => 2, 'docid' => '0000456', 'template_id' => 3, 'mitarbeiter_id' => 2, 'content' => $valid, 'created_by' => 99],
        ['id' => 3, 'docid' => '0000789', 'mitarbeiter_id' => 1, 'content' => $valid, 'status' => 'ausgestellt'],
        ['id' => 4, 'docid' => '0000999', 'mitarbeiter_id' => 1, 'content' => $valid, 'status' => 'ausgestellt'],
        ['id' => 5, 'docid' => '1111111', 'mitarbeiter_id' => 5, 'content' => $valid],
        ['id' => 6, 'docid' => '2222222', 'mitarbeiter_id' => 1, 'content' => json_encode(DocJson::doc(['type' => 'script', 'text' => 'alert(1)']))],
        ['id' => 7, 'docid' => '3333333', 'mitarbeiter_id' => 1, 'content' => json_encode(DocJson::doc(DocJson::para([DocJson::text('ohne Abschnitt')])))],
        ['id' => 8, 'docid' => '4444444', 'mitarbeiter_id' => 1, 'content' => $valid, 'frozen_values' => '[1,2]'],
        ['id' => 9, 'docid' => '5555555', 'mitarbeiter_id' => 1, 'content' => $valid, 'status' => 'storniert'],
        ['id' => 10, 'docid' => '7777777', 'mitarbeiter_id' => 1, 'content' => $valid],
    ]);

    LegacySeed::legacyDocuments([
        ['id' => 1, 'docid' => '1234567', 'type' => 1, 'profileid' => 1, 'erhalter' => 'Person 1', 'anrede' => 1, 'inhalt' => '<p>Hallo</p>',
            'suspendtime' => '2025-07-01', 'erhalter_gebdat' => '1990-01-01', 'erhalter_rang' => 3, 'erhalter_rang_rd' => 2, 'erhalter_quali' => 1,
            'aussteller_rang' => 5, 'ausstellungsdatum' => '2025-06-10', 'ausstellerid' => '100001', 'aussteller_user_id' => 1, 'aussteller_name' => 'Chef',
            'discordid' => '123456789012345678', 'timestamp' => '2025-06-10 12:00:00'],
        ['id' => 2, 'docid' => 'ABCD-EFGH-JKLM', 'type' => 99, 'profileid' => 2, 'template_id' => 2, 'custom_data' => '{"grund":"Test"}',
            'pdf_path' => 'ABCD-EFGH-JKLM.pdf', 'pdf_generated_at' => '2025-12-01 09:00:00', 'is_archived' => 1, 'timestamp' => '2025-12-01 09:00:00'],
        ['id' => 3, 'docid' => 'NPQR-STUV-WXYZ', 'profileid' => 1, 'ausstellungsdatum' => '0000-00-00', 'erhalter_gebdat' => '0000-00-00',
            'custom_data' => '{kaputt', 'ausstellerid' => DOC_SHARED_DISCORD],
        ['id' => 4, 'docid' => '2345-6789-ABCD', 'profileid' => 5, 'pdf_path' => '../../secret.pdf'],
        ['id' => 5, 'docid' => 'EFGH-JKLM-NPQR', 'profileid' => 1, 'ausstellerid' => DOC_SHARED_DISCORD, 'aussteller_user_id' => 3],
        ['id' => 6, 'docid' => 'abc', 'profileid' => 1],
        ['id' => 7, 'docid' => 'DUPE-2222-3333', 'profileid' => 1],
    ]);

    $put = fn (string $path, string $content) => file_put_contents($uploads.'/'.$path, $content);
    $put('storage/private/editor-documents/0000123.pdf', '%PDF-1.7 anna');
    $put('storage/private/editor-documents/9999999.pdf', '%PDF-1.7 orphan');
    $put('storage/documents/0000789.pdf', '%PDF-1.4 older editor');
    $put('storage/documents/1234567.pdf', '%PDF-1.4 t0');
    $put('storage/documents/ABCD-EFGH-JKLM.pdf', '%PDF-1.4 t1');
    $put('storage/documents/EFGH-JKLM-NPQR.pdf', '<html>no pdf</html>');
    $put('storage/documents/ZZZZ-ZZZZ-ZZZZ.pdf', '%PDF-1.4 orphan');
    $put('secret.pdf', '%PDF-1.4 secret');

    LegacyDocument::factory()->create(['public_id' => 'DUPE-2222-3333']);
    Document::factory()->create(['public_id' => '7777777']);
}

/** @return array<string, int> */
function documentCounts(): array
{
    return collect(['document_templates', 'documents', 'legacy_documents', 'personnel', 'users', 'legacy_id_map'])
        ->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all();
}

/** @return array<string, string> path => sha256 of the documents disk */
function documentFiles(): array
{
    $disk = Storage::disk('documents');

    return collect($disk->allFiles())->sort()->values()->mapWithKeys(fn (string $f) => [$f => hash('sha256', (string) $disk->get($f))])->all();
}

function docMapped(string $entity, int $legacyId): ?int
{
    $id = DB::table('legacy_id_map')->where(['entity' => $entity, 'legacy_id' => (string) $legacyId])->value('new_id');

    return $id === null ? null : (int) $id;
}

it('imports templates, adopting a system template by name and failing invalid content', function () {
    seedDocumentCases($this->uploads);
    $seeded = DocumentTemplate::where('name', 'Beförderungsurkunde')->value('id');
    $templates = DocumentTemplate::count();

    $json = LegacySeed::run(['document_template']);

    $adopted = DocumentTemplate::find(docMapped('document_template', 1));
    $second = DocumentTemplate::find(docMapped('document_template', 2));
    expect($json['entities']['document_template'])->toBe(['read' => 3, 'written' => 2, 'skipped' => 0, 'failed' => 1])
        ->and($adopted->id)->toBe($seeded)
        ->and($adopted->name)->toBe('BEFÖRDERUNGSURKUNDE')->and($adopted->category)->toBe('Urkunde alt')
        ->and($adopted->created_by)->toBe(docMapped('user', 1))
        ->and(DB::table('document_templates')->where('id', $adopted->id)->value('created_at'))->toBe('2026-09-15 10:00:00')
        ->and(DB::table('document_templates')->where('id', $adopted->id)->value('updated_at'))->toBe('2026-10-01 06:30:00')
        ->and($second->id)->not->toBe($seeded)->and($second->is_active)->toBeFalse()->and($second->created_by)->toBeNull()
        ->and(DocumentTemplate::count())->toBe($templates + 1)
        ->and(implode("\n", $json['notes']))->toContain('adopted existing row')
        ->and(implode("\n", $json['warnings']))->toContain('1 document template(s): creator account not imported, left empty (legacy ids: 2)')
        ->and(implode("\n", $json['failures']))->toContain('legacy intra_document_templates 3')->toContain('content rejected by the validator');
    $this->assertEquals(docSectionDoc('Alt'), $adopted->content);
});

it('imports editor documents with mappings, validation, status and pdf copies', function () {
    seedDocumentCases($this->uploads);

    $json = LegacySeed::run(['document'], uploads: $this->uploads);

    $one = Document::find(docMapped('document', 1));
    $two = Document::find(docMapped('document', 2));
    $three = Document::find(docMapped('document', 3));
    $four = Document::find(docMapped('document', 4));
    $warnings = implode("\n", $json['warnings']);
    $failures = implode("\n", $json['failures']);
    expect($json['entities']['document'])->toBe(['read' => 10, 'written' => 5, 'skipped' => 1, 'failed' => 4])
        ->and($one->public_id)->toBe('0000123')->and($one->status)->toBe(Document::ISSUED)
        ->and($one->personnel_id)->toBe(docMapped('personnel', 1))
        ->and($one->template_id)->toBe(docMapped('document_template', 1))
        ->and($one->title)->toBe('Urkunde Anna')
        ->and($one->created_by)->toBe(docMapped('user', 1))->and($one->issued_by)->toBeNull()
        ->and(DB::table('documents')->where('id', $one->id)->value('issued_at'))->toBe('2026-09-20 12:00:00')
        ->and(DB::table('documents')->where('id', $one->id)->value('created_at'))->toBe('2026-09-20 10:00:00')
        ->and($one->pdf_path)->toBe('editor/0000123.pdf')
        ->and(Storage::disk('documents')->get('editor/0000123.pdf'))->toBe('%PDF-1.7 anna')
        ->and($two->status)->toBe(Document::DRAFT)->and($two->template_id)->toBeNull()->and($two->created_by)->toBeNull()->and($two->pdf_path)->toBeNull()
        ->and(Storage::disk('documents')->get('editor/0000789.pdf'))->toBe('%PDF-1.4 older editor')->and($three->pdf_path)->toBe('editor/0000789.pdf')
        ->and($four->pdf_path)->toBeNull()
        ->and(Document::where('public_id', '3333333')->exists())->toBeTrue()
        ->and(Storage::disk('documents')->allFiles())->toHaveCount(2)
        ->and($warnings)->toContain('1 document(s) of 1 personnel record(s) that were not imported were skipped (mitarbeiter_id: 5)')
        ->toContain('1 document(s): template not imported, imported without template (legacy ids: 2)')
        ->toContain('created_by account not imported, left empty (legacy ids: 2)')
        ->toContain('issued_by account not imported, left empty (legacy ids: 1)')
        ->toContain('would not open in the editor (top level is not only sections), imported as is (legacy ids: 7)')
        ->toContain('1 issued document(s) imported without PDF: file missing (docid: 0000999)')
        ->toContain('1 PDF file(s) in storage/private/editor-documents without a document row')
        ->and($failures)->toContain('legacy document 6: content rejected by the validator')
        ->toContain('legacy document 8: frozen_values is not a JSON object')
        ->toContain('legacy document 9: unknown status')
        ->toContain('legacy document 10: public id 7777777 is already used');
    $this->assertEquals(docSectionDoc('Anna'), $one->content);
    $this->assertEquals(['mitarbeiter.name' => 'Anna', 'datum' => '20.09.2026'], $one->frozen_values);
    $this->actingAs(User::factory()->superAdmin()->create())->get("/documents/{$one->id}/pdf")->assertOk();
});

it('keeps every legacy document with its payload, issuer, dates and pdf', function () {
    seedDocumentCases($this->uploads);

    $json = LegacySeed::run(['legacy_document'], uploads: $this->uploads);

    $doc = fn (int $id) => LegacyDocument::where('legacy_id', $id)->first();
    $warnings = implode("\n", $json['warnings']);
    expect($json['entities']['legacy_document'])->toBe(['read' => 7, 'written' => 6, 'skipped' => 0, 'failed' => 1])
        ->and($doc(1)->public_id)->toBe('1234567')->and($doc(1)->type)->toBe(1)
        ->and($doc(1)->personnel_id)->toBe(docMapped('personnel', 1))
        ->and($doc(1)->recipient_name)->toBe('Person 1')->and($doc(1)->recipient_discord_id)->toBe('123456789012345678')
        ->and($doc(1)->salutation)->toBe(1)
        ->and($doc(1)->recipient_birthdate->format('Y-m-d'))->toBe('1990-01-01')->and($doc(1)->issued_on->format('Y-m-d'))->toBe('2025-06-10')
        ->and($doc(1)->issuer_user_id)->toBe(docMapped('user', 1))->and($doc(1)->issuer_discord_id)->toBe('100001')->and($doc(1)->issuer_name)->toBe('Chef')
        ->and(DB::table('legacy_documents')->where('legacy_id', 1)->value('created_at'))->toBe('2025-06-10 10:00:00')
        ->and($doc(1)->pdf_path)->toBe('1234567.pdf')->and(Storage::disk('documents')->get('legacy/1234567.pdf'))->toBe('%PDF-1.4 t0')
        ->and($doc(2)->is_archived)->toBeTrue()->and($doc(2)->type)->toBe(99)
        ->and(DB::table('legacy_documents')->where('legacy_id', 2)->value('pdf_generated_at'))->toBe('2025-12-01 08:00:00')
        ->and($doc(2)->pdf_path)->toBe('ABCD-EFGH-JKLM.pdf')
        ->and($doc(3)->issued_on)->toBeNull()->and($doc(3)->recipient_birthdate)->toBeNull()
        ->and($doc(3)->legacy_payload['ausstellungsdatum'])->toBe('0000-00-00')->and($doc(3)->legacy_payload['erhalter_gebdat'])->toBe('0000-00-00')
        ->and($doc(3)->legacy_payload['custom_data'])->toBe('{kaputt')
        ->and($doc(3)->issuer_user_id)->toBeNull()->and($doc(3)->issuer_discord_id)->toBe(DOC_SHARED_DISCORD)->and($doc(3)->pdf_path)->toBeNull()
        ->and($doc(4)->personnel_id)->toBeNull()->and($doc(4)->pdf_path)->toBeNull()
        ->and($doc(5)->issuer_user_id)->toBeNull()->and($doc(5)->issuer_discord_id)->toBe(DOC_SHARED_DISCORD)->and($doc(5)->pdf_path)->toBeNull()
        ->and($doc(6)->public_id)->toBe('abc')->and($doc(6)->pdf_path)->toBeNull()
        ->and(LegacyDocument::where('public_id', 'DUPE-2222-3333')->whereNull('legacy_id')->count())->toBe(1)
        ->and(Storage::disk('documents')->allFiles())->toEqualCanonicalizing(['legacy/1234567.pdf', 'legacy/ABCD-EFGH-JKLM.pdf'])
        ->and($warnings)->toContain('1 legacy document(s): custom_data is not valid JSON, kept as text in legacy_payload (legacy ids: 3)')
        ->toContain('1 legacy document(s): zero or invalid date, kept in legacy_payload (legacy ids: 3)')
        ->toContain('1 legacy document(s): personnel record not imported or none, imported without record (legacy ids: 4)')
        ->toContain('profileid: 5')
        ->toContain('1 legacy document(s): issuing account not imported, Discord ID kept (legacy ids: 5)')
        ->toContain('1 legacy document(s): issuer without account (unknown or ambiguous Discord ID), Discord ID kept (legacy ids: 3)')
        ->toContain('1 legacy document(s): docid in an unknown form, imported without PDF (legacy ids: 6)')
        ->toContain('imported without PDF: file missing (docid: NPQR-STUV-WXYZ')
        ->toContain('imported without PDF: unsafe file name (docid: 2345-6789-ABCD)')
        ->toContain('imported without PDF: not a PDF (docid: EFGH-JKLM-NPQR)')
        ->toContain('1 PDF file(s) in storage/documents without a document row')
        ->and(implode("\n", $json['failures']))->toContain('legacy document 7: public id DUPE-2222-3333 is already used');
    $this->assertEquals([
        'inhalt' => '<p>Hallo</p>', 'suspendtime' => '2025-07-01', 'erhalter_rang' => 3, 'erhalter_rang_rd' => 2, 'erhalter_quali' => 1,
        'aussteller_rang' => 5, 'template_id' => null, 'custom_data' => null,
    ], $doc(1)->legacy_payload);
    expect($doc(2)->legacy_payload['template_id'])->toBe(2)->and($doc(2)->legacy_payload['custom_data'])->toBe('{"grund":"Test"}');
    $this->get('/storage/documents/ABCD-EFGH-JKLM.pdf')->assertOk();
    $this->get('/storage/documents/1234567.pdf')->assertNotFound();
});

it('runs twice with identical counts and copies no file again', function () {
    seedDocumentCases($this->uploads);

    $first = LegacySeed::run(uploads: $this->uploads);
    $counts = documentCounts();
    $files = documentFiles();
    $second = LegacySeed::run(uploads: $this->uploads);

    unset($first['entities']['auth_settings'], $second['entities']['auth_settings']);
    expect($second['entities'])->toBe($first['entities'])
        ->and(documentCounts())->toBe($counts)
        ->and(documentFiles())->toBe($files)->and($files)->toHaveCount(4)
        ->and($first['notes'])->toContain('document: 2 PDF(s) copied, 0 unchanged')->toContain('legacy_document: 2 PDF(s) copied, 0 unchanged')
        ->and($second['notes'])->toContain('document: 0 PDF(s) copied, 2 unchanged')->toContain('legacy_document: 0 PDF(s) copied, 2 unchanged');
});

it('keeps copied pdfs on a run without --uploads and counts them', function () {
    seedDocumentCases($this->uploads);
    LegacySeed::run(['document', 'legacy_document'], uploads: $this->uploads);

    $json = LegacySeed::run(['document', 'legacy_document']);

    expect(Document::where('public_id', '0000123')->value('pdf_path'))->toBe('editor/0000123.pdf')
        ->and(LegacyDocument::where('legacy_id', 2)->value('pdf_path'))->toBe('ABCD-EFGH-JKLM.pdf')
        ->and(implode("\n", $json['notes']))->toContain('3 Dokument-PDFs nicht kopiert')->toContain('5 Altdokument-PDFs nicht kopiert');
});

it('dry run leaves no rows and copies no files', function () {
    seedDocumentCases($this->uploads);
    $before = documentCounts();

    $json = LegacySeed::run(dry: true, uploads: $this->uploads);

    expect($json['entities']['document']['written'])->toBe(5)->and($json['entities']['legacy_document']['written'])->toBe(6)
        ->and(documentCounts())->toBe($before)
        ->and(Storage::disk('documents')->allFiles())->toBe([]);
});

it('imports legacy documents in blocks', function () {
    LegacySeed::personnel([['id' => 1, 'dienstnr' => '100']]);
    $rows = [];
    for ($i = 1; $i <= 2500; $i++) {
        $rows[] = ['id' => $i, 'docid' => sprintf('%07d', 3000000 + $i), 'profileid' => 1, 'timestamp' => '2026-01-15 12:00:00'];
    }
    LegacySeed::legacyDocuments($rows);

    $json = LegacySeed::run(['legacy_document']);
    $again = LegacySeed::run(['legacy_document']);

    expect($json['entities']['legacy_document'])->toBe(['read' => 2500, 'written' => 2500, 'skipped' => 0, 'failed' => 0])
        ->and($json['notes'])->toContain('legacy_document: 3 chunks of up to 1000 rows')
        ->and($again['entities']['legacy_document'])->toBe($json['entities']['legacy_document'])
        ->and(LegacyDocument::count())->toBe(2500)
        ->and(DB::table('legacy_documents')->where('legacy_id', 2500)->value('created_at'))->toBe('2026-01-15 11:00:00');
});

it('refuses a legacy system that is not in the editor state', function () {
    seedDocumentCases($this->uploads);
    $before = documentCounts();
    $legacy = LegacyDatabase::connection();

    $legacy->table('phinxlog')->delete();
    $noVersion = Artisan::call('ignis:import-legacy', ['--only' => ['document']]);
    $noVersionOutput = Artisan::output();
    LegacySeed::editorState();
    $legacy->statement('CREATE TABLE intra_dokument_templates (id INT PRIMARY KEY)');
    $oldTable = Artisan::call('ignis:import-legacy', ['--only' => ['legacy_document']]);
    $oldTableOutput = Artisan::output();
    LegacySeed::editorState();

    expect($noVersion)->toBe(1)->and($noVersionOutput)->toContain('20261016100001')
        ->and($oldTable)->toBe(1)->and($oldTableOutput)->toContain('intra_dokument_templates')
        ->and(documentCounts())->toBe($before);
});

it('keeps imported pdfs and public links when --uploads lacks the document folders', function () {
    seedDocumentCases($this->uploads);
    LegacySeed::run(['document', 'legacy_document'], uploads: $this->uploads);
    $picturesOnly = $this->uploads.'/pictures-only';
    File::ensureDirectoryExists($picturesOnly.'/storage/profile-pictures');

    $json = LegacySeed::run(['document', 'legacy_document'], uploads: $picturesOnly);

    $warnings = implode("\n", $json['warnings']);
    expect(Document::where('public_id', '0000123')->value('pdf_path'))->toBe('editor/0000123.pdf')
        ->and(LegacyDocument::where('legacy_id', 2)->value('pdf_path'))->toBe('ABCD-EFGH-JKLM.pdf')
        ->and($warnings)->toContain('folder storage/documents missing below --uploads')
        ->toContain('folder storage/private/editor-documents missing below --uploads')
        ->and(substr_count($warnings, 'folder storage/documents missing'))->toBe(1)
        ->and($warnings)->not->toContain('file missing (docid: 1234567');
    $this->get('/storage/documents/ABCD-EFGH-JKLM.pdf')->assertOk();

    unlink($this->uploads.'/storage/documents/ABCD-EFGH-JKLM.pdf');
    LegacySeed::run(['legacy_document'], uploads: $this->uploads);
    expect(LegacyDocument::where('legacy_id', 2)->value('pdf_path'))->toBeNull();
});

it('reports what a dry run would copy', function () {
    seedDocumentCases($this->uploads);

    $json = LegacySeed::run(['document', 'legacy_document'], dry: true, uploads: $this->uploads);

    expect($json['notes'])->toContain('document: 2 PDF(s) would be copied, 0 unchanged')
        ->toContain('legacy_document: 2 PDF(s) would be copied, 0 unchanged');
});

it('follows the legacy pdf_path of an editor document and skips colliding 7-digit legacy pdfs', function () {
    LegacySeed::personnel([['id' => 1, 'dienstnr' => '100']]);
    $valid = json_encode(docSectionDoc('Inhalt'));
    LegacySeed::documents([
        ['id' => 1, 'docid' => '0000001', 'mitarbeiter_id' => 1, 'content' => $valid, 'status' => 'ausgestellt', 'pdf_path' => 'storage/documents/0000001.pdf'],
        ['id' => 2, 'docid' => '0000002', 'mitarbeiter_id' => 1, 'content' => $valid, 'status' => 'ausgestellt', 'pdf_path' => '/etc/0000002.pdf'],
    ]);
    LegacySeed::legacyDocuments([['id' => 1, 'docid' => '0000001', 'profileid' => 1]]);
    $put = fn (string $path, string $content) => file_put_contents($this->uploads.'/'.$path, $content);
    $put('storage/private/editor-documents/0000001.pdf', '%PDF-1.7 newer folder');
    $put('storage/documents/0000001.pdf', '%PDF-1.4 recorded');
    $put('storage/private/editor-documents/0000002.pdf', '%PDF-1.7 two');

    $json = LegacySeed::run(['document', 'legacy_document'], uploads: $this->uploads);

    $warnings = implode("\n", $json['warnings']);
    expect(Storage::disk('documents')->get('editor/0000001.pdf'))->toBe('%PDF-1.4 recorded')
        ->and(Document::where('public_id', '0000002')->value('pdf_path'))->toBeNull()
        ->and($warnings)->toContain('imported without PDF: pdf_path is not a legacy PDF location (docid: 0000002)')
        ->and(LegacyDocument::where('legacy_id', 1)->value('pdf_path'))->toBeNull()
        ->and(Storage::disk('documents')->exists('legacy/0000001.pdf'))->toBeFalse()
        ->and($warnings)->toContain('1 legacy document(s): docid is also an editor document id, imported without PDF (legacy ids: 1)');
});

it('never turns an issued document back into a draft and bumps lock_version on update', function () {
    seedDocumentCases($this->uploads);
    LegacySeed::run(['document'], uploads: $this->uploads);
    $lock = Document::where('public_id', '0000456')->value('lock_version');
    LegacyDatabase::connection()->table('intra_documents')->where('id', 1)->update(['status' => 'entwurf', 'title' => 'Neu']);

    $json = LegacySeed::run(['document'], uploads: $this->uploads);

    $one = Document::where('public_id', '0000123')->first();
    expect($one->status)->toBe(Document::ISSUED)->and($one->pdf_path)->toBe('editor/0000123.pdf')->and($one->issued_at)->not->toBeNull()
        ->and(implode("\n", $json['warnings']))->toContain('1 document(s): issued in the new instance but a draft in the legacy system, kept as issued (legacy ids: 1)')
        ->and(Document::where('public_id', '0000456')->value('lock_version'))->toBe($lock + 1);
});

it('names --only in the abort message for a legacy system without the editor state', function () {
    LegacyDatabase::connection()->table('phinxlog')->delete();

    Artisan::call('ignis:import-legacy');
    $output = Artisan::output();
    LegacySeed::editorState();

    expect($output)->toContain('--only=');
});
