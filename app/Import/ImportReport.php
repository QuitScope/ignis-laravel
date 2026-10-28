<?php

namespace App\Import;

use Illuminate\Database\QueryException;

final class ImportReport
{
    public function __construct(private readonly bool $dryRun = false) {}

    /** @var array<string, array{read: int, written: int, skipped: int, failed: int}> */
    private array $entities = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var list<string> */
    private array $notes = [];

    /** @var list<string> */
    private array $skips = [];

    /** @var list<string> */
    private array $failures = [];

    public function read(string $entity, int $n = 1): void
    {
        $this->bump($entity, 'read', $n);
    }

    public function written(string $entity, int $n = 1): void
    {
        $this->bump($entity, 'written', $n);
    }

    /** @param array<string, scalar|null> $context */
    public function skipped(string $entity, string $reason, array $context = []): void
    {
        $this->bump($entity, 'skipped');
        $this->skips[] = "{$entity}: {$reason}".($context ? ' '.json_encode($context, JSON_UNESCAPED_UNICODE) : '');
    }

    public function skippedCount(string $entity, int $n): void
    {
        $this->bump($entity, 'skipped', $n);
    }

    public function failedCount(string $entity, int $n): void
    {
        $this->bump($entity, 'failed', $n);
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function warnOnce(string $message): void
    {
        if (! in_array($message, $this->warnings, true)) {
            $this->warnings[] = $message;
        }
    }

    /**
     * One summary warning "N <what> (legacy ids: first 20, ...)" instead of a line per row; nothing without ids.
     *
     * @param  list<int|string>  $legacyIds
     */
    public function warnRows(string $what, array $legacyIds): void
    {
        if ($legacyIds !== []) {
            $this->warn(count($legacyIds)." {$what} (legacy ids: ".self::idList($legacyIds).')');
        }
    }

    /** @param list<int|string> $ids the first 20, then "..." */
    public static function idList(array $ids): string
    {
        return implode(', ', array_slice($ids, 0, 20)).(count($ids) > 20 ? ', ...' : '');
    }

    public function note(string $message): void
    {
        $this->notes[] = $message;
    }

    public function failed(string $entity, string $message): void
    {
        $this->bump($entity, 'failed');
        $this->failures[] = "{$entity}: {$message}";
    }

    public static function describe(\Throwable $e): string
    {
        if ($e instanceof QueryException) {
            return $e::class.' SQLSTATE['.($e->errorInfo[0] ?? $e->getCode()).']';
        }

        return $e::class.': '.$e->getMessage();
    }

    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }

    /** @return array{dry_run: bool, entities: array<string, array{read: int, written: int, skipped: int, failed: int}>, warnings: list<string>, notes: list<string>, skips: list<string>, failures: list<string>} */
    public function toArray(): array
    {
        return [
            'dry_run' => $this->dryRun,
            'entities' => $this->entities,
            'warnings' => $this->warnings,
            'notes' => $this->notes,
            'skips' => $this->skips,
            'failures' => $this->failures,
        ];
    }

    public function render(): string
    {
        $lines = [sprintf('%-20s %8s %8s %8s %8s', 'Entity', 'read', 'written', 'skipped', 'failed')];
        foreach ($this->entities as $entity => $c) {
            $lines[] = sprintf('%-20s %8d %8d %8d %8d', $entity, $c['read'], $c['written'], $c['skipped'], $c['failed']);
        }
        foreach (['Notes' => $this->notes, 'Skipped' => $this->skips, 'Warnings' => $this->warnings, 'Failures' => $this->failures] as $title => $items) {
            if ($items) {
                $lines[] = '';
                $lines[] = $title.':';
                array_push($lines, ...array_map(fn (string $i) => ' - '.$i, $items));
            }
        }

        return implode("\n", $lines);
    }

    private function bump(string $entity, string $key, int $n = 1): void
    {
        $this->entities[$entity] ??= ['read' => 0, 'written' => 0, 'skipped' => 0, 'failed' => 0];
        $this->entities[$entity][$key] += $n;
    }
}
