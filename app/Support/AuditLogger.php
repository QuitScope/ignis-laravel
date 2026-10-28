<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;

/**
 * Writes the audit trail (activity_log). Mirrors the legacy AuditLogger: log_name = module,
 * description = action (German, stable: legacy rows are imported verbatim), properties =
 * {context}; `details` and `is_global` are real columns (portable queries). Only global entries appear on the audit page.
 *
 * Never put secrets, invitation codes or tokens into $details/$context: ids and labels only.
 * Never breaks the request: any failure is logged by exception class and swallowed.
 */
class AuditLogger
{
    /** @param array<string, scalar|null|array<array-key, mixed>> $context identifiers the entry refers to, e.g. ['role_id' => 3] or ID lists ['members_added' => [4, 7]] */
    public static function log(
        ?User $causer,
        string $action,
        ?string $details = null,
        string $module = 'System',
        bool $global = false,
        array $context = [],
    ): void {
        try {
            activity($module)
                ->causedBy($causer)
                ->withProperties(['context' => $context])
                ->tap(fn (Activity $a) => $a->forceFill(['is_global' => $global, 'details' => $details]))
                ->log($action);
        } catch (\Throwable $e) {
            Log::warning('Audit log failed: '.$e::class);
        }
    }
}
