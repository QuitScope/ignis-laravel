<?php

use App\Branding\LogoUpload;
use App\Support\Settings;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

it('removes a partly written file when the copy throws', function () {
    $path = tempnam(sys_get_temp_dir(), 'logo');
    $image = imagecreatetruecolor(3, 2);
    imagepng($image, $path);
    imagedestroy($image);
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->once()->andThrow(new RuntimeException('disk full'));
    $disk->shouldReceive('delete')->once()->with(Mockery::on(fn ($name) => preg_match(LogoUpload::PATTERN, $name) === 1));
    Storage::set(LogoUpload::DISK, $disk);

    try {
        expect(fn () => (new LogoUpload(app(Settings::class)))->storeFromPath($path))->toThrow(RuntimeException::class, 'disk full');
    } finally {
        unlink($path);
    }
});
