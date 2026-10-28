<?php

use App\Http\LegacyUrlMap;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('redirects the old application pages', function () {
    foreach ([
        ['/forms/select', '/forms'],
        ['/forms/select.php', '/forms'],
        ['/forms/create?typ=3', '/forms'],
        ['/forms/view?antrag=123456', '/forms/123456'],
        ['/forms/view.php?antrag=123456', '/forms/123456'],
        ['/antrag/view?antrag=123456', '/forms/123456'],
        ['/antraege/view?antrag=123456', '/forms/123456'],
        ['/forms/view?antrag=ABC', '/forms'],
        ['/forms/view?antrag=1234567', '/forms'],
        ['/forms/view?antrag[]=123456', '/forms'],
        ['/forms/view', '/forms'],
        ['/forms/admin/list', '/forms/admin'],
        ['/forms/admin/list?status=1', '/forms/admin'],
        ['/forms/admin/view?antrag=654321', '/forms/admin/654321'],
        ['/forms/admin/view?antrag=//evil.example', '/forms/admin'],
        ['/settings/forms/list', '/settings/forms'],
        ['/settings/antrag/list', '/settings/forms'],
        ['/settings/forms/edit?id=4', '/settings/forms'],
    ] as [$from, $to]) {
        $this->get($from)->assertStatus(301)->assertRedirect($to);
    }
});

it('keeps the new pages that share the old paths', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($admin)->get('/settings/forms/create')->assertOk();
    $this->actingAs($admin)->get('/me/applications')->assertOk();
    expect(LegacyUrlMap::redirectFor('/settings/forms/create'))->toBeNull()
        ->and(LegacyUrlMap::redirectFor('/me/applications'))->toBeNull();
});

it('has no legacy POST endpoints any more', function () {
    foreach ([
        ['/forms/create?typ=1', 404],
        ['/forms/admin/view?antrag=123456', 404],
        ['/settings/forms/create', 405],
        ['/settings/forms/edit', 404],
        ['/settings/forms/toggle', 404],
        ['/settings/forms/delete', 404],
        ['/settings/forms/sort', 404],
        ['/settings/forms/fields/delete', 404],
    ] as [$uri, $status]) {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]))
            ->post($uri, ['id' => 1, 'antrag' => '123456', 'csrf_token' => 'x'])->assertStatus($status);
    }
});

it('follows an imported legacy number to the public id it got (also a regenerated one)', function () {
    $type = DB::table('form_types')->value('id');
    $id = DB::table('form_submissions')->insertGetId(['public_id' => '777777', 'form_type_id' => $type, 'applicant_name' => 'X',
        'status' => 'new', 'answers' => '[]', 'submitted_at' => now()]);
    DB::table('legacy_id_map')->insert([['entity' => 'form_uniqueid', 'legacy_id' => '123456', 'new_id' => $id],
        ['entity' => 'form_uniqueid', 'legacy_id' => 'ABC', 'new_id' => $id]]);

    $this->get('/forms/view?antrag=123456')->assertStatus(301)->assertRedirect('/forms/777777');
    $this->get('/forms/admin/view?antrag=ABC')->assertStatus(301)->assertRedirect('/forms/admin/777777');
    $this->get('/forms/view?antrag=234567')->assertStatus(301)->assertRedirect('/forms/234567');
});
