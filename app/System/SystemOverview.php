<?php

namespace App\System;

use App\Console\Commands\SchedulerHeartbeatCommand;
use App\Models\Document;
use App\Models\LegacyDocument;
use App\Models\Personnel;
use App\Models\Vehicle;
use App\Support\HealthChecks;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The numbers of the system overview page. Every probe is wrapped on its own: a table or driver that is missing gives
 * null for that value, never a 500. Only counts, sizes and short fixed German messages leave here - no DSN, host,
 * paths or exception text.
 */
class SystemOverview
{
    public const HEARTBEAT_STALE = 300;

    public function __construct(private PendingMigrations $migrations) {}

    /** @return array<string, mixed> */
    public function build(Request $request): array
    {
        return [
            'runtime' => [
                'php' => PHP_VERSION,
                'laravel' => Application::VERSION,
                'db_driver' => self::probe(fn () => DB::connection()->getDriverName()),
                'db_version' => self::probe(fn () => $this->dbVersion()),
            ],
            'health' => self::probe(fn () => $this->health($request)),
            'scheduler' => $this->scheduler(),
            'queue' => [
                'pending' => self::probe(fn () => $this->jobsCount()),
                'failed' => self::probe(fn () => $this->failedJobsCount()),
            ],
            'counts' => [
                'personnel' => self::probe(fn () => $this->personnelCount()),
                'documents' => self::probe(fn () => $this->documentsCount()),
                'vehicles' => self::probe(fn () => $this->vehiclesCount()),
                'sessions_24h' => self::probe(fn () => $this->sessionsCount()),
            ],
            'database' => [
                'size_bytes' => self::probe(fn () => $this->dbSize()),
                'tables' => self::probe(fn () => $this->topTables()),
            ],
            'migrations' => self::probe(fn () => $this->migrations->names()),
        ];
    }

    /** @return array{status: string, last_run: ?string, age_seconds: ?int} */
    public function scheduler(): array
    {
        $stamp = self::probe(fn () => Cache::get(SchedulerHeartbeatCommand::CACHE_KEY));
        $last = is_string($stamp) ? self::probe(fn () => Carbon::parse($stamp)) : null;
        if (! $last instanceof Carbon) {
            return ['status' => 'unknown', 'last_run' => null, 'age_seconds' => null];
        }
        $age = max(0, (int) $last->diffInSeconds(now(), true));

        return ['status' => $age < self::HEARTBEAT_STALE ? 'ok' : 'warn', 'last_run' => $last->toIso8601String(), 'age_seconds' => $age];
    }

    /**
     * Status and a short German message per check; the raw check output (error texts, versions, paths) stays behind.
     *
     * @return array{status: string, checks: list<array{key: string, status: string, message: string}>}
     */
    protected function health(Request $request): array
    {
        $report = HealthChecks::run($request);
        $checks = [];
        foreach ($report['checks'] as $key => $check) {
            $status = (string) ($check['status'] ?? 'down');
            $checks[] = ['key' => $key, 'status' => $status, 'message' => __('settings.overview.health.'.$key.'.'.($status === 'ok' ? 'ok' : 'bad'))];
        }

        return ['status' => $report['status'], 'checks' => $checks];
    }

    protected function jobsCount(): int
    {
        return DB::table('jobs')->count();
    }

    protected function failedJobsCount(): int
    {
        return DB::table('failed_jobs')->count();
    }

    protected function personnelCount(): int
    {
        return Personnel::query()->count();
    }

    protected function documentsCount(): int
    {
        return Document::query()->count() + LegacyDocument::query()->count();
    }

    protected function vehiclesCount(): int
    {
        return Vehicle::query()->count();
    }

    protected function sessionsCount(): ?int
    {
        return config('session.driver') === 'database'
            ? DB::table('sessions')->where('last_activity', '>=', now()->subDay()->timestamp)->count()
            : null;
    }

    protected function dbVersion(): string
    {
        $sql = DB::connection()->getDriverName() === 'sqlite' ? 'select sqlite_version() as v' : 'select version() as v';

        return (string) DB::selectOne($sql)->v;
    }

    protected function dbSize(): int
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return (int) DB::selectOne('select page_count * page_size as s from pragma_page_count, pragma_page_size')->s;
        }

        return (int) DB::selectOne('select sum(data_length + index_length) as s from information_schema.tables where table_schema = database()')->s;
    }

    /** @return list<array{name: string, bytes: int}> top 10 by size; the probe gives null where the engine cannot say (SQLite without dbstat) */
    protected function topTables(): array
    {
        $rows = DB::connection()->getDriverName() === 'sqlite'
            ? DB::select("select s.name as name, sum(s.pgsize) as bytes from dbstat s join sqlite_master m on m.name = s.name and m.type = 'table' group by s.name order by bytes desc limit 10")
            : DB::select('select table_name as name, data_length + index_length as bytes from information_schema.tables where table_schema = database() order by bytes desc limit 10');

        return array_map(fn ($r) => ['name' => (string) $r->name, 'bytes' => (int) $r->bytes], $rows);
    }

    private static function probe(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (Throwable) {
            return null;
        }
    }
}
