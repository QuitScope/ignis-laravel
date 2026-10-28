<?php

use App\Dashboard\DashboardWidgets;
use App\Dashboard\Widget;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

function dw_widget(string $key, string $area = 'main', int $order = 10, bool|Throwable $allowed = true, array|Throwable|null $data = ['x' => 1]): Widget
{
    return new class($key, $area, $order, $allowed, $data) implements Widget
    {
        public int $dataCalls = 0;

        public function __construct(private string $key, private string $area, private int $order, private bool|Throwable $allowed, private array|Throwable|null $data) {}

        public function key(): string
        {
            return $this->key;
        }

        public function area(): string
        {
            return $this->area;
        }

        public function order(): int
        {
            return $this->order;
        }

        public function allowed(User $user): bool
        {
            return $this->allowed instanceof Throwable ? throw $this->allowed : $this->allowed;
        }

        public function data(User $user): ?array
        {
            $this->dataCalls++;

            return $this->data instanceof Throwable ? throw $this->data : $this->data;
        }
    };
}

it('sorts the allowed widgets per area by order and keeps registration order for ties', function () {
    $r = new DashboardWidgets;
    foreach ([dw_widget('c', 'main', 20), dw_widget('a', 'main', 10), dw_widget('b', 'main', 10), dw_widget('k', 'kpi', 5)] as $w) {
        $r->register($w);
    }
    $areas = $r->allowedFor(new User);

    expect(array_map(fn ($w) => $w->key(), $areas['main']))->toBe(['a', 'b', 'c'])
        ->and(array_map(fn ($w) => $w->key(), $areas['kpi']))->toBe(['k'])
        ->and($areas['top'])->toBe([])->and($areas['side'])->toBe([]);
});

it('ignores a second widget with the same key and logs it', function () {
    Log::spy();
    $r = new DashboardWidgets;
    $r->register(dw_widget('a', 'main', 10, true, ['first' => true]));
    $r->register(dw_widget('a', 'main', 10, true, ['second' => true]));

    $list = $r->allowedFor(new User)['main'];
    expect($list)->toHaveCount(1)->and($r->data($list[0], new User))->toBe(['first' => true]);
    Log::shouldHaveReceived('warning')->once();
});

it('does not list a widget that is not allowed and never calls its data()', function () {
    $spy = dw_widget('spy', allowed: false);
    $r = new DashboardWidgets;
    $r->register($spy);

    expect($r->allowedFor(new User)['main'])->toBe([])->and($spy->dataCalls)->toBe(0);
});

it('isolates a widget whose allowed() or data() throws', function () {
    Log::spy();
    $r = new DashboardWidgets;
    $r->register(dw_widget('boom_allowed', allowed: new RuntimeException('no')));
    $r->register(dw_widget('boom_data', data: new RuntimeException('no')));
    $r->register(dw_widget('fine'));

    $list = $r->allowedFor(new User)['main'];
    expect(array_map(fn ($w) => $w->key(), $list))->toBe(['boom_data', 'fine'])
        ->and($r->data($list[0], new User))->toBeNull()
        ->and($r->data($list[1], new User))->toBe(['x' => 1]);
    Log::shouldHaveReceived('error')->twice();
});

it('drops a widget with an unknown area', function () {
    $r = new DashboardWidgets;
    $r->register(dw_widget('odd', 'footer'));

    expect(array_merge(...array_values($r->allowedFor(new User))))->toBe([]);
});
