<?php

use App\Models\Document;
use App\Models\LegacyDocument;
use App\Models\Personnel;
use App\Support\Settings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['documents', 'branding', 'profile-pictures'] as $d) {
        Storage::fake($d);
    }
});

function stCleanupPut(string $disk, string $path, int $ageHours = 48): void
{
    Storage::disk($disk)->put($path, 'x');
    touch(Storage::disk($disk)->path($path), time() - $ageHours * 3600);
}

const STCLEANUP_PIC = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png';
const STCLEANUP_PIC2 = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.png';

it('deletes old unreferenced files, keeps referenced, young and foreign ones', function () {
    $doc = Document::factory()->create(['pdf_path' => 'editor/1234567.pdf']);
    $legacy = LegacyDocument::factory()->create(['pdf_path' => 'ABCD-EFGH-JKLM.pdf']);
    Personnel::factory()->create(['profile_picture' => STCLEANUP_PIC]);
    app(Settings::class)->set('system.logo', STCLEANUP_PIC);

    stCleanupPut('documents', 'editor/1234567.pdf');
    stCleanupPut('documents', 'editor/7654321.pdf');
    stCleanupPut('documents', 'editor/7654322.pdf', 1);
    stCleanupPut('documents', 'editor/.7654323.0123456789ab.tmp');
    stCleanupPut('documents', 'editor/notes.txt');
    stCleanupPut('documents', 'legacy/ABCD-EFGH-JKLM.pdf');
    stCleanupPut('documents', 'legacy/WXYZ-WXYZ-WXYZ.pdf');
    stCleanupPut('branding', STCLEANUP_PIC);
    stCleanupPut('branding', STCLEANUP_PIC2);
    stCleanupPut('profile-pictures', STCLEANUP_PIC);
    stCleanupPut('profile-pictures', STCLEANUP_PIC2);

    $this->artisan('ignis:storage:cleanup')->assertSuccessful();

    expect(Storage::disk('documents')->allFiles())->toEqualCanonicalizing([
        'editor/1234567.pdf', 'editor/7654322.pdf', 'editor/notes.txt', 'legacy/ABCD-EFGH-JKLM.pdf',
    ])->and(Storage::disk('branding')->allFiles())->toBe([STCLEANUP_PIC])
        ->and(Storage::disk('profile-pictures')->allFiles())->toBe([STCLEANUP_PIC]);
});

it('deletes nothing on dry run and lists the candidates', function () {
    stCleanupPut('documents', 'editor/7654321.pdf');
    stCleanupPut('branding', STCLEANUP_PIC2);

    $this->artisan('ignis:storage:cleanup --dry-run')
        ->expectsOutputToContain('documents: editor/7654321.pdf')
        ->expectsOutputToContain('branding: '.STCLEANUP_PIC2)
        ->expectsOutputToContain('2 orphaned')
        ->assertSuccessful();

    expect(Storage::disk('documents')->exists('editor/7654321.pdf'))->toBeTrue()
        ->and(Storage::disk('branding')->exists(STCLEANUP_PIC2))->toBeTrue();
});

it('ignores symlinks and never touches their targets', function () {
    $outside = sys_get_temp_dir().'/sc_outside_'.uniqid();
    mkdir($outside);
    file_put_contents($outside.'/7654321.pdf', 'x');
    touch($outside.'/7654321.pdf', time() - 99 * 3600);
    $root = Storage::disk('documents')->path('');
    @mkdir($root.'editor', 0775, true);
    $link = @symlink($outside.'/7654321.pdf', $root.'editor/7654321.pdf');
    $dirLink = @symlink($outside, $root.'legacy');
    if (! $link && ! $dirLink) {
        $this->markTestSkipped('symlinks not available');
    }

    $this->artisan('ignis:storage:cleanup')->assertSuccessful();

    expect(file_exists($outside.'/7654321.pdf'))->toBeTrue()
        ->and(is_link($root.'editor/7654321.pdf') || ! $link)->toBeTrue();
    @unlink($root.'editor/7654321.pdf');
    @unlink($root.'legacy');
    @unlink($outside.'/7654321.pdf');
    @rmdir($outside);
});

it('schedules pruning and cleanup but not updates:check', function () {
    $events = collect(app(Schedule::class)->events())->mapWithKeys(fn ($e) => [$e->command => $e->expression]);
    $find = fn (string $needle) => $events->first(fn ($x, $c) => str_contains($c, $needle));

    expect($find('queue:prune-failed --hours=720'))->toBe('0 0 * * *')
        ->and($find('model:prune'))->toBe('0 0 * * *')
        ->and($find('ignis:storage:cleanup'))->toBe('0 0 * * 0')
        ->and($events->keys()->contains(fn ($c) => str_contains($c, 'updates:check')))->toBeFalse();
});

it('aborts a disk without deleting when its reference lookup fails', function () {
    stCleanupPut('branding', STCLEANUP_PIC);
    stCleanupPut('documents', 'legacy/1234567.pdf');
    DB::beforeExecuting(function ($query) {
        if (str_contains($query, 'settings')) {
            throw new RuntimeException('db down');
        }
    });

    $this->artisan('ignis:storage:cleanup')->assertFailed();

    expect(Storage::disk('branding')->exists(STCLEANUP_PIC))->toBeTrue()
        ->and(Storage::disk('documents')->exists('legacy/1234567.pdf'))->toBeFalse();
});
