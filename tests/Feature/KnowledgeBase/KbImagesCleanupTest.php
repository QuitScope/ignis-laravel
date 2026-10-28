<?php

use App\Support\Settings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/kbHelpers.php';

uses(RefreshDatabase::class);

const KBC_OLD = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png';
const KBC_USED = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.jpg';
const KBC_SECTION = 'cccccccccccccccccccccccccccccccc.webp';
const KBC_ARCHIVED = 'dddddddddddddddddddddddddddddddd.gif';
const KBC_YOUNG = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee.png';

beforeEach(fn () => Storage::fake('kb-images'));

function kbc_file(string $name, int $age = 172800): void
{
    Storage::disk('kb-images')->put($name, 'x');
    touch(Storage::disk('kb-images')->path($name), time() - $age);
}

function kbc_doc(string $name): array
{
    return ['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['src' => '/storage/kb-images/'.$name]]]];
}

it('deletes old unreferenced images and keeps referenced, archived-referenced, young and foreign files', function () {
    foreach ([KBC_OLD, KBC_USED, KBC_SECTION, KBC_ARCHIVED, 'notes.txt', 'ABCDEFABCDEFABCDEFABCDEFABCDEFAB.png', str_repeat('f', 32).'.svg'] as $name) {
        kbc_file($name);
    }
    kbc_file(KBC_YOUNG, 3600);
    kbx_entry(['content' => kbc_doc(KBC_USED)]);
    kbx_entry(['type' => 'medication', 'sections' => ['med_dosage' => kbc_doc(KBC_SECTION)]]);
    kbx_entry(['is_archived' => true, 'content' => kbc_doc(KBC_ARCHIVED)]);

    $this->artisan('kb:images:cleanup')->expectsOutputToContain('1 unreferenced image(s) found, 1 deleted')->assertExitCode(0);

    $left = Storage::disk('kb-images')->allFiles();
    sort($left);
    $expected = [KBC_USED, KBC_SECTION, KBC_ARCHIVED, KBC_YOUNG, 'notes.txt', 'ABCDEFABCDEFABCDEFABCDEFABCDEFAB.png', str_repeat('f', 32).'.svg'];
    sort($expected);
    expect($left)->toBe($expected);
});

it('lists but deletes nothing on --dry-run', function () {
    kbc_file(KBC_OLD);

    $this->artisan('kb:images:cleanup', ['--dry-run' => true])->expectsOutputToContain(KBC_OLD)->expectsOutputToContain('none deleted')->assertExitCode(0);

    Storage::disk('kb-images')->assertExists(KBC_OLD);
});

it('aborts with failure and deletes nothing when the reference lookup fails', function () {
    kbc_file(KBC_OLD);
    Schema::drop('kb_entry_relations');
    Schema::drop('kb_entry_tag');
    Schema::drop('kb_entries');

    $this->artisan('kb:images:cleanup')->expectsOutputToContain('Reference lookup failed')->assertExitCode(1);

    Storage::disk('kb-images')->assertExists(KBC_OLD);
});

it('reports a broken entries table even when there are no candidate files', function () {
    Schema::drop('kb_entry_relations');
    Schema::drop('kb_entry_tag');
    Schema::drop('kb_entries');

    $this->artisan('kb:images:cleanup')->expectsOutputToContain('Reference lookup failed')->assertExitCode(1);
});

it('does nothing while the module is off', function () {
    kbc_file(KBC_OLD);
    app(Settings::class)->set('modules.knowledgebase.enabled', false);

    $this->artisan('kb:images:cleanup')->expectsOutputToContain('deaktiviert')->assertExitCode(0);

    Storage::disk('kb-images')->assertExists(KBC_OLD);
});

it('is scheduled daily at 03:45 and runs only while the module is on', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'kb:images:cleanup'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('45 3 * * *')
        ->and($event->filtersPass(app()))->toBeTrue();

    app(Settings::class)->set('modules.knowledgebase.enabled', false);
    expect($event->filtersPass(app()))->toBeFalse();
});
