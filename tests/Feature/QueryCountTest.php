<?php

use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDefect;
use App\Notifications\InboxNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Mail\Models\Mailbox;

require_once __DIR__.'/Calendar/calendarHelpers.php';
require_once __DIR__.'/Mail/mailHelpers.php';

/*
 * N+1 guards for the busiest lists (small variant, no load framework). Lazy loads already throw outside production
 * (preventLazyLoading); this catches per-row queries that are not lazy loads (exists/count/find in a loop).
 * Each page is requested at 5 and at 25 rows: the query count must not grow with the rows.
 */

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function qc_queries(Closure $request): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $request();
    DB::disableQueryLog();

    return count(DB::getQueryLog());
}

/** @param Closure(User, int): void $seed */
function qc_counts(string $url, Closure $seed): array
{
    $user = User::factory()->superAdmin()->create(['personnel_id' => Personnel::factory()->create(['full_name' => 'Qc Admin'])->id]);
    $get = fn () => test()->actingAs($user)->getJson($url)->assertOk();

    $seed($user, 5);
    $get(); // warm-up: settings, permissions and other per-process caches
    $five = qc_queries($get);
    $seed($user, 20);

    return [$five, qc_queries($get)];
}

it('does not grow its query count with the rows', function (string $url, Closure $seed) {
    [$five, $twentyFive] = qc_counts($url, $seed);

    expect($twentyFive)->toBe($five, "$url: $five queries at 5 rows, $twentyFive at 25");
})->with([
    'personnel list' => ['/personnel', fn (User $u, int $n) => Personnel::factory()->count($n)->create()],
    'user list' => ['/users', fn (User $u, int $n) => User::factory()->count($n)
        ->withRole(Role::query()->firstOrFail())
        ->create(['personnel_id' => fn () => Personnel::factory()->create()->id])],
    'vehicle list' => ['/vehicles', fn (User $u, int $n) => Vehicle::factory()->count($n)->create()],
    'defect list' => ['/vehicles/defects', fn (User $u, int $n) => VehicleDefect::factory()->count($n)->create()],
    'inbox' => ['/inbox', fn (User $u, int $n) => qc_notify($u, $n)],
    'notification poll' => ['/api/notifications/poll', fn (User $u, int $n) => qc_notify($u, $n)],
    'mail inbox' => ['/mail/inbox', function (User $u, int $n) {
        $own = Mailbox::query()->where('personnel_id', $u->personnel_id)->first() ?? ml_provision($u->personnel);
        foreach (range(1, $n) as $i) {
            ml_message(ml_provision(Personnel::factory()->create()), [[$own, 'to', 'inbox']], "Betreff $i");
        }
    }],
    'calendar feed' => ['/calendar/events?from=2026-11-01&to=2026-12-01', function (User $u, int $n) {
        foreach (range(1, $n) as $i) {
            cal_event(attendees: [Personnel::factory()->create()->id => 'accepted', $u->personnel_id => 'pending']);
        }
    }],
    'eNOTF QM list' => ['/enotf/admin', function (User $u, int $n) {
        foreach (range(1, $n) as $i) {
            (new EnotfProtocol)->forceFill(['enr' => (string) random_int(100000, 999999), 'vehicle_transport_label' => "RTW $i"])->save();
        }
    }],
    'global search' => ['/api/system/global-search?q=Muster', fn (User $u, int $n) => Personnel::factory()->count($n)
        ->create(['full_name' => fn () => 'Muster '.Str::random(6)])],
]);

function qc_notify(User $user, int $n): void
{
    foreach (range(1, $n) as $i) {
        $note = new InboxNotification('system', "Hinweis $i", 'Text', '/documents/1');
        $note->id = (string) Str::uuid();
        $user->notify($note);
    }
}
