<?php

use App\Import\ProfilePictureCopier;
use App\Models\Personnel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('profile-pictures');
    $this->root = sys_get_temp_dir().'/ignis-uploads-'.bin2hex(random_bytes(6));
    $this->pictures = $this->root.'/storage/profile-pictures';
    File::ensureDirectoryExists($this->pictures);
    $this->copier = new ProfilePictureCopier;
});

afterEach(fn () => File::deleteDirectory($this->root));

function copierImage(string $path, string $type = 'png'): void
{
    $image = imagecreatetruecolor(3, 2);
    $type === 'png' ? imagepng($image, $path) : imagejpeg($image, $path);
    imagedestroy($image);
}

/** @return list<string> */
function copiedFiles(): array
{
    return Storage::disk('profile-pictures')->allFiles();
}

it('copies a valid png under a new random name and leaves the source untouched', function () {
    copierImage($this->pictures.'/a.png');
    $before = hash_file('sha256', $this->pictures.'/a.png');

    $name = $this->copier->copy($this->root, 'a.png', false);

    expect($name)->toMatch(Personnel::PICTURE_PATTERN)->not->toBe('a.png')
        ->and(copiedFiles())->toBe([$name])
        ->and(array_slice(getimagesizefromstring((string) Storage::disk('profile-pictures')->get($name)), 0, 2))->toBe([3, 2])
        ->and(hash_file('sha256', $this->pictures.'/a.png'))->toBe($before);
});

it('stores a legacy .jpeg as .jpg', function () {
    copierImage($this->pictures.'/0123abcd.jpeg', 'jpeg');

    $name = $this->copier->copy($this->root, '0123abcd.jpeg', false);

    expect($name)->toEndWith('.jpg')->toMatch(Personnel::PICTURE_PATTERN);
});

it('checks but copies nothing on a dry run', function () {
    copierImage($this->pictures.'/a.png');

    expect($this->copier->copy($this->root, 'a.png', true))->toMatch(Personnel::PICTURE_PATTERN)
        ->and(copiedFiles())->toBe([]);
});

it('keeps the existing picture when the content is unchanged', function () {
    copierImage($this->pictures.'/a.png');
    $first = $this->copier->copy($this->root, 'a.png', false);

    $again = $this->copier->copy($this->root, 'a.png', false, $first);

    expect($again)->toBe($first)->and(copiedFiles())->toBe([$first]);
});

it('refuses unsafe names, missing files and bad files without copying', function (string $name, ?Closure $prepare = null) {
    copierImage($this->root.'/secret.png');
    if ($prepare) {
        $prepare($this->pictures, $this->root);
    }

    expect($this->copier->copy($this->root, $name, false))->toBeNull()
        ->and($this->copier->error)->not->toBeNull()
        ->and(copiedFiles())->toBe([]);
})->with([
    'dot dot' => ['../../secret.png'],
    'dot dot without slash' => ['..secret.png'],
    'absolute unix' => ['/etc/passwd'],
    'absolute windows' => ['C:\\Windows\\win.ini'],
    'subdirectory' => ['sub/a.png'],
    'empty' => [''],
    'missing' => ['nope.png'],
    'directory' => ['dir.png', fn ($dir) => mkdir($dir.'/dir.png')],
    'wrong type' => ['text.png', fn ($dir) => file_put_contents($dir.'/text.png', 'not an image')],
    'svg' => ['x.png', fn ($dir) => file_put_contents($dir.'/x.png', '<svg xmlns="http://www.w3.org/2000/svg"/>')],
    'too large' => ['big.png', fn ($dir) => file_put_contents($dir.'/big.png', str_repeat('x', 2 * 1024 * 1024 + 1))],
]);

it('refuses a symlink that points out of the pictures folder', function () {
    copierImage($this->root.'/secret.png');
    if (! @symlink($this->root.'/secret.png', $this->pictures.'/link.png')) {
        $this->markTestSkipped('symlinks are not available here');
    }

    expect($this->copier->copy($this->root, 'link.png', false))->toBeNull()
        ->and(copiedFiles())->toBe([]);
});

it('returns null when the uploads folder has no pictures folder', function () {
    expect($this->copier->copy($this->root.'/missing', 'a.png', false))->toBeNull()
        ->and(copiedFiles())->toBe([]);
});
