<?php

use App\Support\ImageUpload;
use App\Support\UploadRejected;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

const IU_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

beforeEach(fn () => Storage::fake('img'));

function iuImage(string $format, int $w = 4, int $h = 3): string
{
    $img = imagecreatetruecolor($w, $h);
    ob_start();
    match ($format) {
        'png' => imagepng($img),
        'jpg' => imagejpeg($img),
        'webp' => imagewebp($img),
        'gif' => imagegif($img),
    };

    return (string) ob_get_clean();
}

function iuPngHeader(int $w, int $h, string $idat = ''): string
{
    $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0)).$chunk('IDAT', $idat).$chunk('IEND', '');
}

function iuStore(string $clientName, string $bytes, int $max = 2 * 1024 * 1024): string
{
    return ImageUpload::store(UploadedFile::fake()->createWithContent($clientName, $bytes), 'img', $max, IU_TYPES);
}

it('stores png, jpeg and webp under a random name with the extension of the detected type', function (string $format) {
    $name = iuStore('evil.php', iuImage($format));

    expect($name)->toMatch('/^[a-f0-9]{32}\.'.$format.'$/D');
    Storage::disk('img')->assertExists($name);
    expect(array_slice(getimagesizefromstring((string) Storage::disk('img')->get($name)), 0, 2))->toBe([4, 3]);
})->with(fn () => array_values(array_filter(['png', 'jpg', 'webp'], fn (string $f) => iuSupports($f))));

it('re-encodes a jpeg so its metadata is gone', function () {
    $comment = 'GPS 50.1109 N 8.6821 E';
    $jpeg = substr_replace(iuImage('jpg'), "\xFF\xFE".pack('n', strlen($comment) + 2).$comment, 2, 0);

    expect(Storage::disk('img')->get(iuStore('photo.jpg', $jpeg)))->not->toContain($comment);
})->skip(fn () => ! iuSupports('jpg'), 'GD without JPEG');

function iuSupports(string $format): bool
{
    $gd = gd_info();

    return match ($format) {
        'jpg' => ! empty($gd['JPEG Support']),
        'webp' => ! empty($gd['WebP Support']),
        default => true,
    };
}

it('gives every upload its own name', function () {
    expect(iuStore('a.png', iuImage('png')))->not->toBe(iuStore('a.png', iuImage('png')));
});

it('rejects content that is not an allowed image', function (string $clientName, string $bytes, string $message) {
    expect(fn () => iuStore($clientName, $bytes))->toThrow(UploadRejected::class, $message);
    expect(Storage::disk('img')->allFiles())->toBe([]);
})->with(fn () => [
    'svg' => ['a.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'Ungültiger Dateityp'],
    'svg named png' => ['a.png', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"/>', 'Ungültiger Dateityp'],
    'html named png' => ['a.png', '<!DOCTYPE html><html><script>alert(1)</script></html>', 'Ungültiger Dateityp'],
    'plain text' => ['a.jpg', 'just some text', 'Ungültiger Dateityp'],
    'gif' => ['a.gif', iuImage('gif'), 'Ungültiger Dateityp'],
    'php behind png magic' => ['a.png', "\x89PNG\r\n\x1a\n<?php echo 'x'; ?>", 'Ungültiger Dateityp'],
    'valid png header, broken pixel data' => ['a.png', iuPngHeader(2, 2, 'garbage'), 'Bild konnte nicht gelesen werden'],
    ...(iuSupports('jpg') ? ['truncated jpeg' => ['a.jpg', substr(iuImage('jpg', 64, 64), 0, 200), 'Bild konnte nicht gelesen werden']] : []),
]);

it('rejects files over the size limit before looking at them', function () {
    expect(fn () => iuStore('a.png', iuImage('png'), 10))->toThrow(UploadRejected::class, 'Datei ist zu groß (max. 1 KB)');
    expect(fn () => iuStore('a.png', str_repeat('x', 3 * 1024 * 1024)))->toThrow(UploadRejected::class, 'Datei ist zu groß (max. 2 MB)');
});

it('rejects decompression bombs by their declared size without decoding them', function () {
    expect(fn () => iuStore('a.png', iuPngHeader(20000, 20000)))->toThrow(UploadRejected::class, 'zu viele Pixel');
    expect(fn () => iuStore('a.png', iuPngHeader(ImageUpload::MAX_PIXELS + 1, 1)))->toThrow(UploadRejected::class, 'zu viele Pixel');
    expect(Storage::disk('img')->allFiles())->toBe([]);
});

it('rejects a failed upload', function () {
    $file = new UploadedFile(tempnam(sys_get_temp_dir(), 'iu'), 'a.png', 'image/png', UPLOAD_ERR_PARTIAL, true);

    expect(fn () => ImageUpload::store($file, 'img', 1024, IU_TYPES))->toThrow(UploadRejected::class);
});
