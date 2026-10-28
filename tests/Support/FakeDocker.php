<?php

namespace Tests\Support;

use App\Update\DockerUpdateService;

/**
 * A pretend Docker host for DockerUpdateService: records every argv and answers like docker / docker compose would for a
 * stack whose app runs version 1.0.0 (image sha256:aaa…) and is updated to the image of the fake release 2.0.0. The
 * app's version follows the image in the override file (`up` makes it current). Failures are switched on by name.
 */
final class FakeDocker
{
    public const NEW = 'ghcr.io/ignis-test/ignis@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public const OLD_ID = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @var list<list<string>> */
    public array $calls = [];

    /** @var list<string> contents fed to the db client (drop statements, restores) */
    public array $dbInput = [];

    /** @var array<string, bool> pull, pull_previous, up, up_new, health_new, health_old, worker_after_rollback, trial_create, trial_migrate, restore, dump, probe */
    public array $fail = [];

    /** @var list<string> images the fake daemon has (ids, tags, digests) */
    public array $images = [self::OLD_ID];

    /** @var list<string> RepoDigests of the original image (empty: built locally) */
    public array $oldDigests = [];

    /** @var list<string> contents of the trial compose file each trial run got */
    public array $trialFiles = [];

    public bool $down = false;

    public ?string $running = null;

    public string $installed = '1.0.0';

    public int $ups = 0;

    public bool $reverb = false;

    public function __construct(private DockerUpdateService $updater) {}

    /**
     * @param  list<string>  $argv
     * @return array{0: int, 1: string, 2: string}
     */
    public function __invoke(array $argv, ?string $stdin, ?string $stdout): array
    {
        $this->calls[] = $argv;
        $line = implode(' ', $argv);
        $args = $argv[1] === 'compose' ? array_slice($argv, $this->afterFiles($argv)) : array_slice($argv, 1);
        $cmd = implode(' ', $args);

        return match (true) {
            $cmd === 'ps -q app' => [0, "0123456789ab\n", ''],
            $cmd === 'ps -a -q reverb' => [0, $this->reverb ? "fedcba987654\n" : '', ''],
            $cmd === 'ps --status running --services' => [0, ($this->running !== null && $this->running !== self::NEW && ($this->fail['worker_after_rollback'] ?? false)) ? "db\napp\nscheduler\n" : "db\napp\nworker\nscheduler\n", ''],
            str_starts_with($cmd, 'inspect --format {{.Image}}') => [0, self::OLD_ID."\n", ''],
            str_starts_with($cmd, 'tag ') => $this->tag($args[1], $args[2]),
            str_starts_with($cmd, 'pull ') => $this->pull($args[1]),
            str_starts_with($cmd, 'image inspect --format {{.Id}}') => [in_array(end($args), $this->images, true) ? 0 : 1, '', ''],
            str_starts_with($cmd, 'image inspect') => [0, json_encode(end($args) === self::NEW ? [self::NEW] : $this->oldDigests)."\n", ''],
            str_starts_with($cmd, 'exec -T app test -f') => [$this->down ? 0 : 1, '', ''],
            str_starts_with($cmd, 'exec -T app sh -c test -f') => ($this->fail['app_down'] ?? false) ? [1, '', 'service "app" is not running'] : [0, $this->down ? "on\n" : "off\n", ''],
            $cmd === 'exec -T db mariadb --help' => [0, ($this->fail['no_sandbox'] ?? false) ? "  --safe-updates   Only allow UPDATE ...\n" : "  --sandbox   Disallow commands that access the file system\n", ''],
            str_starts_with($cmd, 'exec -T app php artisan down') => $this->maintenance(true),
            $cmd === 'exec -T app php artisan up' => $this->maintenance(false),
            str_starts_with($cmd, 'exec -T app php artisan ignis:update:health') => $this->probe(),
            str_starts_with($cmd, 'exec -T db sh -c') => $this->db((string) end($argv), $stdin, $stdout),
            str_starts_with($cmd, 'run --rm') => $this->trialRun($argv),
            str_starts_with($cmd, 'stop ') => [0, '', ''],
            str_starts_with($cmd, 'up -d') => $this->up(),
            default => [99, '', "unexpected command: $line"],
        };
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function tag(string $from, string $to): array
    {
        if (! in_array($from, $this->images, true)) {
            return [1, '', 'No such image'];
        }
        $this->images[] = $to;

        return [0, '', ''];
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function pull(string $ref): array
    {
        if (($ref === self::NEW && ($this->fail['pull'] ?? false)) || ($ref !== self::NEW && ($this->fail['pull_previous'] ?? false))) {
            return [1, '', 'pull access denied'];
        }
        $this->images[] = $ref;

        return [0, '', ''];
    }

    /**
     * @param  list<string>  $argv
     * @return array{0: int, 1: string, 2: string}
     */
    private function trialRun(array $argv): array
    {
        $files = array_keys($argv, '-f', true);
        $this->trialFiles[] = (string) @file_get_contents($argv[end($files) + 1]);

        return ($this->fail['trial_migrate'] ?? false) ? [1, '', 'SQLSTATE migration failed'] : [0, 'migrated', ''];
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function maintenance(bool $down): array
    {
        $this->down = $down;

        return [0, '', ''];
    }

    /** @param list<string> $argv */
    private function afterFiles(array $argv): int
    {
        $k = 2;
        while (isset($argv[$k]) && in_array($argv[$k], ['--project-directory', '-f'], true)) {
            $k += 2;
        }

        return $k;
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function up(): array
    {
        $this->ups++;
        $override = (string) @file_get_contents($this->updater->overridePath());
        $image = preg_match('/image: "([^"]+)"/', $override, $m) === 1 ? $m[1] : null;
        if (($this->fail['up'] ?? false) || ($image === self::NEW && ($this->fail['up_new'] ?? false))) {
            return [1, '', 'Error response from daemon'];
        }
        $this->running = $image;

        return [0, '', ''];
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function probe(): array
    {
        if ($this->fail['probe'] ?? false) {
            return [1, '', 'service "app" is not running'];
        }
        $new = $this->running === self::NEW;
        $version = $new ? '2.0.0' : $this->installed;
        $down = ($new && ($this->fail['health_new'] ?? false)) || (! $new && $this->running !== null && ($this->fail['health_old'] ?? false)) ? ['db'] : [];

        return [0, "boot noise\n".json_encode(['version' => $version, 'down' => $down, 'queue' => 'ok', 'pending' => 0])."\n", ''];
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function db(string $script, ?string $stdin, ?string $stdout): array
    {
        if (str_contains($script, 'mariadb-dump')) {
            if ($this->fail['dump'] ?? false) {
                return [2, '', 'Access denied'];
            }
            file_put_contents((string) $stdout, "CREATE TABLE users (id int);\n-- Dump completed on 2026-10-20\n");

            return [0, '', ''];
        }
        if (str_contains($script, 'CREATE DATABASE ignis_trial')) {
            return ($this->fail['trial_create'] ?? false) ? [1, '', "ERROR 1044 (42000): Access denied for user 'ignis'"] : [0, '', ''];
        }
        if (str_contains($script, 'information_schema')) {
            return [0, "users\tBASE TABLE\nnew_table\tBASE TABLE\nsome_view\tVIEW\n", ''];
        }
        if ($stdin !== null && ! str_contains($script, 'ignis_trial')) {
            $this->dbInput[] = (string) file_get_contents($stdin);
            if (($this->fail['restore'] ?? false) && ! str_starts_with(end($this->dbInput), 'SET FOREIGN_KEY_CHECKS')) {
                return [1, '', 'ERROR at line 1'];
            }
        }

        return [0, '', ''];
    }
}
