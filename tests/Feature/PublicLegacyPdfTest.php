<?php

use App\Models\LegacyDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const PUB_NAME = 'ABCD-EFGH-JKLM.pdf';

beforeEach(function () {
    Storage::fake('documents');
    Storage::disk('documents')->put('legacy/'.PUB_NAME, '%PDF-1.4 legacy');
    LegacyDocument::factory()->create(['public_id' => 'ABCD-EFGH-JKLM', 'pdf_path' => PUB_NAME]);
});

it('serves an imported legacy pdf without login, cacheable for five minutes', function () {
    $response = $this->get('/storage/documents/'.PUB_NAME)->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Disposition'))->toContain('inline')
        ->and($response->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=300')
        ->and($response->headers->getCookies())->toBe([]);
    expect($response->baseResponse->getFile()->getContent())->toBe('%PDF-1.4 legacy');
});

it('answers 404 for everything but an imported, existing legacy pdf, with or without login', function (string $url, ?string $file = null, ?string $row = null) {
    if ($file) {
        Storage::disk('documents')->put($file, '%PDF-1.4 secret');
    }
    if ($row) {
        LegacyDocument::factory()->create(['public_id' => 'ZZZZ', 'pdf_path' => $row]);
    }

    $this->get($url)->assertNotFound();
    $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
})->with([
    'encoded dots' => ['/storage/documents/%2e%2e%2f%2e%2e%2f.env'],
    'encoded slash' => ['/storage/documents/..%2fABCD-EFGH-JKLM.pdf'],
    'encoded backslash' => ['/storage/documents/..%5cABCD-EFGH-JKLM.pdf'],
    'nul byte' => ['/storage/documents/ABCD-EFGH-JKLM.pdf%00'],
    'newline' => ['/storage/documents/ABCD-EFGH-JKLM.pdf%0A'],
    'nested editor path' => ['/storage/documents/editor/1234567.pdf', 'editor/1234567.pdf'],
    'editor name' => ['/storage/documents/1234567.pdf', 'editor/1234567.pdf'],
    '7 digits with file and row' => ['/storage/documents/1234567.pdf', 'legacy/1234567.pdf', '1234567.pdf'],
    'lowercase' => ['/storage/documents/abcd-efgh-jklm.pdf', 'legacy/abcd-efgh-jklm.pdf', 'abcd-efgh-jklm.pdf'],
    'php extension' => ['/storage/documents/ABCD-EFGH-JKLM.php', 'legacy/ABCD-EFGH-JKLM.php', 'ABCD-EFGH-JKLM.php'],
    'upper extension' => ['/storage/documents/ABCD-EFGH-JKLM.PDF', 'legacy/ABCD-EFGH-JKLM.PDF', 'ABCD-EFGH-JKLM.PDF'],
    'forbidden letter 0' => ['/storage/documents/ABCD-EFGH-JKL0.pdf', 'legacy/ABCD-EFGH-JKL0.pdf', 'ABCD-EFGH-JKL0.pdf'],
    'file in pattern but no row' => ['/storage/documents/WXYZ-2345-6789.pdf', 'legacy/WXYZ-2345-6789.pdf'],
    'row but no file' => ['/storage/documents/WXYZ-2345-6788.pdf', null, 'WXYZ-2345-6788.pdf'],
]);

it('keeps the other areas behind the login', function () {
    $this->get('/storage/profile-pictures/abc.png')->assertRedirect('/login');
    $this->get('/storage/unknown/ABCD-EFGH-JKLM.pdf')->assertRedirect('/login');
});

it('throttles anonymous requests per address without a session (429 after 120 a minute)', function () {
    foreach (range(1, 120) as $i) {
        $this->get('/storage/documents/'.PUB_NAME)->assertOk();
    }

    $res = $this->get('/storage/documents/'.PUB_NAME)->assertStatus(429);
    expect($res->headers->getCookies())->toBe([]);
});

it('needs the row public_id to match the file name, not only pdf_path', function () {
    Storage::disk('documents')->put('legacy/WXYZ-2345-6789.pdf', '%PDF-1.4');
    LegacyDocument::factory()->create(['public_id' => 'WXYZ-2345-6780', 'pdf_path' => 'WXYZ-2345-6789.pdf']);

    $this->get('/storage/documents/WXYZ-2345-6789.pdf')->assertNotFound();
});
