<?php

namespace App\Update;

use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Starts `php artisan ignis:update:run <version>` (or the manual rollback) as a process of its own that outlives the
 * web request and PHP-FPM: Linux setsid/nohup in the background, Windows via WMI Win32_Process.Create (the process is
 * not a child of the web server, so a job object of the server cannot take it down). Returns at once.
 *
 * The launcher writes no state: the run command takes the UpdateLock and only then begin()s, so a status poll right
 * after the start may still show the previous result for a moment.
 */
class ProcessUpdateStarter implements UpdateStarter
{
    public function start(string $version, bool $skipTrial = false): void
    {
        if (! InstalledVersion::isValid($version)) {
            throw new UpdateException('bad_version');
        }
        $this->artisan(['ignis:update:run', $version, ...($skipTrial ? ['--without-trial'] : [])]);
    }

    public function rollback(): void
    {
        $this->artisan(['ignis:update:rollback', '--force']);
    }

    public function ownerAlive(): bool
    {
        return (new UpdateLock)->ownerAlive();
    }

    /** @param list<string> $args */
    private function artisan(array $args): void
    {
        if ((new UpdateLock)->ownerAlive()) {
            throw new UpdateException('busy');
        }
        $php = (new PhpExecutableFinder)->find(false) ?: throw new UpdateException('php_missing');
        $app = Installation::appPath();
        $this->spawn([$php, "$app/artisan", ...$args], $app);
    }

    /**
     * Runs $argv detached in $cwd and returns without waiting for it.
     *
     * @param  list<string>  $argv
     */
    public function spawn(array $argv, string $cwd): void
    {
        foreach ([...$argv, $cwd] as $arg) {
            if (preg_match('/["\'\r\n\0]/', $arg)) {
                throw new RuntimeException('argument not allowed');
            }
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $line = implode(' ', array_map(fn (string $a) => '"'.$a.'"', $argv));
            $ps = '$r = Invoke-CimMethod -ClassName Win32_Process -MethodName Create -Arguments @{CommandLine=\''.$line.'\'; CurrentDirectory=\''.$cwd.'\'}; exit [int]$r.ReturnValue';
            $command = ['powershell.exe', '-NoProfile', '-NonInteractive', '-EncodedCommand', base64_encode((string) mb_convert_encoding($ps, 'UTF-16LE', 'UTF-8'))];
        } else {
            $command = ['/bin/sh', '-c', 'if command -v setsid >/dev/null 2>&1; then setsid "$@"; else nohup "$@"; fi </dev/null >/dev/null 2>&1 &', 'sh', ...$argv];
        }
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open($command, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, $cwd);
        if (! is_resource($process) || proc_close($process) !== 0) {
            throw new RuntimeException('update process could not be started');
        }
    }
}
