<?php

use App\Import\LegacyPdfCopier;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    $this->root = sys_get_temp_dir().'/ignis-pdfs-'.bin2hex(random_bytes(6));
    $this->docs = $this->root.'/storage/documents';
    $this->editor = $this->root.'/storage/private/editor-documents';
    File::ensureDirectoryExists($this->docs);
    File::ensureDirectoryExists($this->editor);
    $this->copier = new LegacyPdfCopier;
    $this->copy = fn (string $name, bool $dry = false) => $this->copier->copy($this->root, ['storage/private/editor-documents', 'storage/documents'], $name, 'legacy/x.pdf', $dry);
});

afterEach(fn () => File::deleteDirectory($this->root));

it('copies a pdf, prefers the first folder and leaves the source untouched', function () {
    file_put_contents($this->docs.'/1234567.pdf', '%PDF-1.4 old');
    file_put_contents($this->editor.'/1234567.pdf', '%PDF-1.7 new');

    expect(($this->copy)('1234567.pdf'))->toBeTrue()
        ->and($this->copier->wrote)->toBeTrue()
        ->and(Storage::disk('documents')->get('legacy/x.pdf'))->toBe('%PDF-1.7 new')
        ->and(file_get_contents($this->editor.'/1234567.pdf'))->toBe('%PDF-1.7 new');
});

it('falls back to the second folder and accepts the legacy id form', function () {
    file_put_contents($this->docs.'/ABCD-EFGH-JK23.pdf', '%PDF-1.4');

    expect(($this->copy)('ABCD-EFGH-JK23.pdf'))->toBeTrue()
        ->and(Storage::disk('documents')->exists('legacy/x.pdf'))->toBeTrue();
});

it('does not write an unchanged target again and writes nothing on a dry run', function () {
    file_put_contents($this->docs.'/1234567.pdf', '%PDF-1.4');

    expect(($this->copy)('1234567.pdf', true))->toBeTrue()
        ->and(Storage::disk('documents')->allFiles())->toBe([]);
    ($this->copy)('1234567.pdf');
    expect(($this->copy)('1234567.pdf'))->toBeTrue()->and($this->copier->wrote)->toBeFalse();
});

it('refuses unsafe names, missing and bad files without copying', function (string $name, ?Closure $prepare = null) {
    file_put_contents($this->root.'/1234567.pdf', '%PDF-1.4 secret');
    if ($prepare) {
        $prepare($this->docs);
    }

    expect(($this->copy)($name))->toBeFalse()
        ->and($this->copier->error)->not->toBeNull()
        ->and(Storage::disk('documents')->allFiles())->toBe([]);
})->with([
    'dot dot' => ['../../1234567.pdf'],
    'subdirectory' => ['sub/1234567.pdf'],
    'absolute' => ['/etc/passwd'],
    'lowercase id' => ['abcd-efgh-jklm.pdf', fn ($dir) => file_put_contents($dir.'/abcd-efgh-jklm.pdf', '%PDF-1.4')],
    'other extension' => ['1234567.PDF', fn ($dir) => file_put_contents($dir.'/1234567.PDF', '%PDF-1.4')],
    'six digits' => ['123456.pdf', fn ($dir) => file_put_contents($dir.'/123456.pdf', '%PDF-1.4')],
    'missing' => ['7654321.pdf'],
    'directory' => ['7654321.pdf', fn ($dir) => mkdir($dir.'/7654321.pdf')],
    'wrong type' => ['7654321.pdf', fn ($dir) => file_put_contents($dir.'/7654321.pdf', '<html>not a pdf</html>')],
    'too large' => ['7654321.pdf', function ($dir) {
        $f = fopen($dir.'/7654321.pdf', 'wb');
        fwrite($f, '%PDF-1.4');
        ftruncate($f, LegacyPdfCopier::MAX_BYTES + 1);
        fclose($f);
    }],
]);

it('refuses a symlink that points out of the documents folder', function () {
    file_put_contents($this->root.'/secret.pdf', '%PDF-1.4 secret');
    if (! @symlink($this->root.'/secret.pdf', $this->docs.'/7654321.pdf')) {
        $this->markTestSkipped('symlinks are not available here');
    }

    expect(($this->copy)('7654321.pdf'))->toBeFalse()
        ->and($this->copier->error)->toBe('file outside the documents folder')
        ->and(Storage::disk('documents')->allFiles())->toBe([]);
});
