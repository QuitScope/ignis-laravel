<?php

use App\Documents\InvalidDocument;
use App\Documents\PdfGenerator;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DocJson as J;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(fn () => Storage::fake('documents'));

/** @param array<string, mixed> $state */
function pdfDoc(array $content, array $state = []): Document
{
    return Document::factory()->create(['content' => $content, 'public_id' => '1234567'] + $state);
}

it('writes a real pdf to editor/{public_id}.pdf and returns the relative path', function () {
    $doc = pdfDoc(J::doc(J::section([J::para([J::text('Hallo Welt')])])));

    $path = (new PdfGenerator)->generate($doc);

    expect($path)->toBe('editor/1234567.pdf');
    Storage::disk('documents')->assertExists($path);
    expect(Storage::disk('documents')->get($path))->toStartWith('%PDF-')
        ->and(Storage::disk('documents')->allFiles())->toBe([$path]);
});

it('hands the rendered, escaped body to the print template', function () {
    $doc = pdfDoc(J::doc(J::section([J::para([J::text('Vorher <b>fett</b> ')])])));

    $html = (new PdfGenerator)->html($doc);

    expect($html)->toContain('Vorher &lt;b&gt;fett&lt;/b&gt; ')
        ->and($html)->not->toContain('<b>fett')
        ->and($html)->toContain('<section class="efe-section">')
        ->and($html)->toContain('DejaVu Sans')->toContain('margin: 20mm 18mm');
});

it('uses frozen values for issued documents and blanks unknown variables', function () {
    $content = J::doc(J::section([J::para([J::variable('datum'), J::text('|'), J::variable('mitarbeiter.name')])]));
    $issued = pdfDoc($content, ['status' => Document::ISSUED, 'issued_at' => now(), 'frozen_values' => ['datum' => '01.02.2020']]);

    $html = (new PdfGenerator)->html($issued);

    expect($html)->toContain('01.02.2020')->and($html)->not->toContain('{{');
});

it('pins the hardened dompdf options', function () {
    $o = (new PdfGenerator)->options();

    expect($o->getIsRemoteEnabled())->toBeFalse()
        ->and($o->getIsPhpEnabled())->toBeFalse()
        ->and($o->getIsJavascriptEnabled())->toBeFalse()
        ->and($o->getAllowedProtocols())->toBe([])
        ->and($o->getDefaultFont())->toBe('DejaVu Sans')
        ->and($o->getDefaultPaperSize())->toBe('a4')
        ->and($o->getDefaultPaperOrientation())->toBe('portrait');

    expect($o->getTempDir())->toBe(storage_path('framework/cache/dompdf'))
        ->and($o->getFontCache())->toBe(storage_path('framework/cache/dompdf'));

    $chroot = $o->getChroot();
    expect($chroot)->toHaveCount(1)->and(is_dir($chroot[0]))->toBeTrue()
        ->and(glob($chroot[0].'/*'))->toBe([]);
});

it('never lets hostile content reach dompdf as markup or resource reference', function () {
    $evil = '<img src="file:///etc/passwd"><link rel="stylesheet" href="http://x/y.css"><style>@import url(http://x/);</style>data:text/html,x';
    $doc = pdfDoc(J::doc(J::section([J::para([J::text($evil)])])));

    $html = (new PdfGenerator)->html($doc);

    expect(substr_count($html, '<img'))->toBe(0)
        ->and(substr_count($html, '<link'))->toBe(0)
        ->and(substr_count($html, '<style'))->toBe(1)
        ->and($html)->toContain('&lt;img src=&quot;file:///etc/passwd&quot;&gt;');
    expect((new PdfGenerator)->generate($doc))->toBe('editor/1234567.pdf');
});

it('rejects an invalid document before dompdf is touched', function () {
    $doc = pdfDoc(J::doc(['type' => 'image', 'attrs' => ['src' => 'file:///etc/passwd']]));

    expect(fn () => (new PdfGenerator)->generate($doc))->toThrow(InvalidDocument::class);
    expect(Storage::disk('documents')->allFiles())->toBe([]);
});

it('paginates long documents and still stamps page numbers', function () {
    $paras = array_map(fn ($i) => J::para([J::text("Absatz $i")]), range(1, 120));
    $doc = pdfDoc(J::doc(J::section($paras)));

    (new PdfGenerator)->generate($doc);

    $pdf = Storage::disk('documents')->get('editor/1234567.pdf');
    $pages = preg_match_all('~/Type /Page\b~', $pdf);
    expect($pages)->toBeGreaterThan(1);

    preg_match_all('~stream\r?\n(.*?)endstream~s', $pdf, $streams);
    $text = str_replace([' ', "\0"], '', implode("\n", array_map(fn ($s) => (string) @gzuncompress($s), $streams[1])));
    expect($text)->toContain('Absatz120')->toContain('1von'.$pages)->toContain($pages.'von'.$pages);
});

it('restores the time and memory limits it tightened', function () {
    $time = ini_get('max_execution_time');
    $mem = ini_get('memory_limit');

    (new PdfGenerator)->generate(pdfDoc(J::doc(J::section([J::para([J::text('x')])]))));

    expect(ini_get('max_execution_time'))->toBe($time)->and(ini_get('memory_limit'))->toBe($mem);
});

it('leaves no temp file when the final move fails', function () {
    $disk = Storage::disk('documents');
    $disk->makeDirectory('editor/1234567.pdf');
    $doc = pdfDoc(J::doc(J::section([J::para([J::text('x')])])));

    expect(fn () => (new PdfGenerator)->generate($doc))->toThrow(RuntimeException::class);
    expect($disk->allFiles())->toBe([]);
});

it('never fails a finished render because the memory limit cannot be restored', function () {
    $before = ini_get('memory_limit');
    $render = new ReflectionMethod(PdfGenerator::class, 'render');
    $pdf = $render->invoke(new PdfGenerator, '<html><body><p>x</p></body></html>', '1M');
    ini_set('memory_limit', $before);

    expect($pdf)->toStartWith('%PDF-');
});

it('raises a 128M limit for the render and restores it', function () {
    if (memory_get_usage() > 96 * 1024 * 1024) {
        $this->markTestSkipped('The process already uses too much memory to start from a 128M limit.');
    }

    $before = ini_get('memory_limit');
    ini_set('memory_limit', '128M');

    try {
        $path = (new PdfGenerator)->generate(pdfDoc(J::doc(J::section([J::para([J::text('x')])]))));
        $after = ini_get('memory_limit');
    } finally {
        ini_set('memory_limit', $before);
    }

    expect($path)->toBe('editor/1234567.pdf')->and($after)->toBeIn(['128M', '256M']);
});

it('renders a large but legitimate table within 256M', function () {
    $row = fn () => ['type' => 'tableRow', 'content' => array_map(fn ($c) => ['type' => 'tableCell', 'content' => [J::para([J::text("c$c")])]], range(1, 8))];
    $table = ['type' => 'table', 'content' => array_map($row, range(1, 40))];
    $doc = pdfDoc(J::doc(J::section([$table])));

    $before = ini_get('memory_limit');
    ini_set('memory_limit', '256M');
    try {
        expect((new PdfGenerator)->generate($doc))->toBe('editor/1234567.pdf');
    } finally {
        ini_set('memory_limit', $before);
    }
});

it('loads nothing for hostile raw html given to the hardened dompdf', function () {
    $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    $html = '<html><head><link rel="stylesheet" href="file:///etc/hosts"><style>@import url("file:///etc/hosts"); p { background: url('.$png.'); }</style></head>'
        .'<body><img src="'.$png.'"><img src="file:///etc/passwd"><img src="http://127.0.0.1/x.png"><p>ok</p></body></html>';

    $pdf = null;
    try {
        $dompdf = new Dompdf\Dompdf((new PdfGenerator)->options());
        $dompdf->loadHtml($html);
        $dompdf->render();
        $pdf = $dompdf->output();
    } catch (Throwable) {
    }

    expect($pdf === null || ! str_contains($pdf, '/Subtype /Image'))->toBeTrue();
});
