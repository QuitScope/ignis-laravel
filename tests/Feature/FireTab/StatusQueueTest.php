<?php

use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\FireTab\Models\FireStatusQueue;
use Modules\FireTab\Support\StatusQueue;

uses(RefreshDatabase::class)->group('mariadb');

it('claims disjoint rows, skips claimed rows of a crashed consumer, and marks only its own token delivered', function () {
    $vehicle = Vehicle::factory()->create();
    foreach (range(1, 5) as $i) {
        StatusQueue::push($vehicle, (string) $i, null);
    }

    $a = StatusQueue::claim(3);
    $b = StatusQueue::claim(3);
    expect($a->pluck('status')->all())->toBe(['1', '2', '3'])
        ->and($b->pluck('status')->all())->toBe(['4', '5'])
        ->and(StatusQueue::claim())->toBeEmpty();

    StatusQueue::push($vehicle, '6', null);
    $id = FireStatusQueue::where('status', '6')->value('id');
    FireStatusQueue::whereKey($id)->update(['claim_token' => 'other', 'claimed_at' => now()]);
    expect(StatusQueue::claim())->toBeEmpty();

    StatusQueue::markDelivered($b->first()->claim_token);
    expect(FireStatusQueue::whereNotNull('delivered_at')->pluck('status')->all())->toBe(['4', '5']);
});

it('prunes delivered rows after 7 days and undelivered after 1 h, dry run deletes nothing', function () {
    $vehicle = Vehicle::factory()->create();
    $row = fn (array $a) => (new FireStatusQueue)->forceFill(['vehicle_id' => $vehicle->id, 'vehicle_name' => 'X', 'status' => '1', ...$a])->save();
    $row(['created_at' => now()->subDays(8), 'delivered_at' => now()->subDays(8)]);
    $row(['created_at' => now()->subDays(2), 'delivered_at' => now()->subDays(2)]);
    $row(['created_at' => now()->subHours(2), 'claim_token' => 'lost', 'claimed_at' => now()->subHours(2)]);
    $row(['created_at' => now()->subMinutes(5)]);

    $this->artisan('firetab:prune-status-queue --dry-run')->expectsOutputToContain('1 delivered and 1 undelivered')->assertSuccessful();
    expect(FireStatusQueue::count())->toBe(4);

    $this->artisan('firetab:prune-status-queue')->assertSuccessful();
    expect(FireStatusQueue::count())->toBe(2);
});
