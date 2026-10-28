<?php

namespace Modules\Federation\Jobs;

use App\Modules\ModuleRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Federation\Models\FederatedIncident;
use Modules\Federation\Models\FederatedPerson;
use Modules\Federation\Models\FederatedProtocol;
use Modules\Federation\Models\FederationLink;
use Modules\Federation\Models\SyncRun;
use Modules\Federation\Support\PeerHttp;
use Throwable;

/**
 * Pull sync of one link. Per consumed type, page by page along the provider's keyset cursor (all pages,
 * the legacy loop skipped rows from page 2 on); each page is one transaction: upsert on (link_id, remote_id), tombstones
 * deleted, cursor saved, so a failed run resumes and a repeated one changes nothing. Personnel is a full pull marked with
 * a run id; people not seen are deleted only after a complete run. Errors are codes only; payloads never reach a log.
 * A failed run is retried after 1/5/15 min (3 attempts).
 */
final class SyncLink implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const MAX_PAGES = 100;

    public const LIMIT = 100;

    public const TYPES = ['personnel' => 'personnel', 'enotf' => 'enotf', 'fire' => 'fire-incidents'];

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(public int $linkId) {}

    public function uniqueId(): string
    {
        return (string) $this->linkId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        $link = ModuleRegistry::active('federation') ? FederationLink::query()->find($this->linkId) : null;
        if ($link === null || ! $link->is_active) {
            return;
        }
        $types = array_keys(array_filter([
            'personnel' => $link->consume_personnel,
            'enotf' => $link->consume_enotf && ModuleRegistry::active('enotf'),
            'fire' => $link->consume_fire && ModuleRegistry::active('firetab'),
        ]));

        $error = null;
        foreach ($types as $type) {
            $start = hrtime(true);
            try {
                [$records, $code] = $type === 'personnel' ? $this->personnel($link) : $this->delta($link, $type);
            } catch (Throwable $e) {
                Log::warning('Federation sync: '.$e::class, ['link_id' => $link->id, 'type' => $type]);
                [$records, $code] = [0, 'internal'];
            }
            (new SyncRun)->forceFill([
                'link_id' => $link->id, 'type' => $type, 'status' => $code === null ? 'success' : 'error', 'records' => $records,
                'duration_ms' => intdiv(hrtime(true) - $start, 1_000_000), 'error' => $code,
            ])->save();
            $error ??= $code;
        }

        $link->forceFill(['last_sync_at' => now(), 'last_sync_status' => $error === null ? 'success' : 'error', 'last_sync_error' => $error])->save();

        if ($error !== null) {
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff()[$this->attempts() - 1]);

                return;
            }
            Log::warning('Federation sync failed', ['link_id' => $link->id, 'error' => $error]);
        }
    }

    /** @return array{0: int, 1: ?string} records, error code */
    private function personnel(FederationLink $link): array
    {
        $run = (string) Str::uuid();
        $after = null;
        $records = 0;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $res = $this->fetch($link, 'personnel', $after);
            if (is_string($res)) {
                return [$records, $res];
            }
            [$rows, $cursor, $next] = $res;
            $values = [];
            foreach ($rows as $row) {
                $name = self::text($row['full_name'] ?? null, 255);
                if ($name !== null) {
                    $values[] = [
                        'link_id' => $link->id, 'remote_id' => $row['id'], 'full_name' => $name,
                        'service_number' => self::text($row['service_number'] ?? null, 50), 'rank' => self::text($row['rank'] ?? null, 255),
                        'qualifications' => is_array($row['qualifications'] ?? null) ? json_encode($row['qualifications'], JSON_UNESCAPED_UNICODE) : null,
                        'seen_run' => $run,
                    ];
                }
            }
            FederatedPerson::query()->upsert($values, ['link_id', 'remote_id'], ['full_name', 'service_number', 'rank', 'qualifications', 'seen_run']);
            $records += count($values);
            if ($next === null) {
                FederatedPerson::query()->where('link_id', $link->id)->where(fn ($q) => $q->where('seen_run', '!=', $run)->orWhereNull('seen_run'))->delete();

                return [$records, null];
            }
            $after = $next;
        }

        return [$records, null];
    }

    /** @return array{0: int, 1: ?string} records, error code */
    private function delta(FederationLink $link, string $type): array
    {
        $model = $type === 'enotf' ? FederatedProtocol::class : FederatedIncident::class;
        $records = 0;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $after = $link->cursors[$type] ?? null;
            $res = $this->fetch($link, $type, $after);
            if (is_string($res)) {
                return [$records, $res];
            }
            [$rows, $cursor, $next] = $res;
            $values = $gone = [];
            foreach ($rows as $row) {
                if (($row['visible'] ?? true) === false) {
                    $gone[] = $row['id'];

                    continue;
                }
                $values[] = ['link_id' => $link->id, 'remote_id' => $row['id']] + ($type === 'enotf' ? [
                    'number' => self::text($row['enr'] ?? null, 100),
                    'occurred_at' => self::time($row['sendezeit'] ?? null),
                ] : [
                    'number' => self::text($row['incident_number'] ?? null, 100),
                    'keyword' => self::text($row['keyword'] ?? null, 255),
                    'location' => self::text($row['location'] ?? null, 255),
                    'status' => self::text($row['status'] ?? null, 50),
                    'occurred_at' => self::time($row['started_at'] ?? $row['created_at'] ?? null),
                ]) + ['data' => json_encode($row, JSON_UNESCAPED_UNICODE), 'remote_updated_at' => self::time($row['updated_at'] ?? null)];
            }
            DB::transaction(function () use ($link, $type, $model, $values, $gone, $cursor, $after) {
                if ($values !== []) {
                    $model::query()->upsert($values, ['link_id', 'remote_id'], array_values(array_diff(array_keys($values[0]), ['link_id', 'remote_id'])));
                }
                if ($gone !== []) {
                    $model::query()->where('link_id', $link->id)->whereIn('remote_id', $gone)->delete();
                }
                $link->forceFill(['cursors' => [$type => $cursor ?? $after] + ($link->cursors ?? [])])->save();
            });
            $records += count($rows);
            if ($next === null) {
                break;
            }
        }

        return [$records, null];
    }

    /** @return array{0: list<array<string, mixed>>, 1: ?string, 2: ?string}|string rows, cursor, next; or an error code */
    private function fetch(FederationLink $link, string $type, ?string $after): array|string
    {
        $res = PeerHttp::send($link, 'GET', '/api/federation/'.self::TYPES[$type], ['limit' => self::LIMIT] + ($after !== null ? ['after' => $after] : []), sign: $link);
        if (! $res instanceof Response) {
            return $res;
        }
        $rows = $res->json('data');
        $cursor = $res->json('cursor');
        $next = $res->json('next');
        if (! is_array($rows) || ! array_is_list($rows) || ($cursor !== null && ! is_string($cursor)) || ($next !== null && ! is_string($next))) {
            return 'bad_json';
        }
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_int($row['id'] ?? null) || $row['id'] < 1) {
                return 'bad_json';
            }
        }

        /** @var list<array<string, mixed>> $rows */
        return [$rows, $cursor, $next];
    }

    private static function text(mixed $value, int $max): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
    }

    private static function time(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return rescue(fn () => Carbon::parse($value)->utc()->format('Y-m-d H:i:s'), null, report: false);
    }
}
