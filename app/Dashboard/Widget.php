<?php

namespace App\Dashboard;

use App\Models\User;

/**
 * One block of the start page (legacy Overview + assets/components/index/*). The page asks allowed() first and calls
 * data() only for widgets the viewer may see, so a widget without the right never queries anything. data() returns
 * null when there is nothing to show (the widget then disappears, a number is never invented). Modules register
 * theirs with DashboardWidgets::register(); the Vue side maps `key` to a component (lib/dashboardWidgets.ts).
 */
interface Widget
{
    public function key(): string;

    public function area(): string;

    public function order(): int;

    public function allowed(User $user): bool;

    /** @return ?array<string, mixed> */
    public function data(User $user): ?array;
}
