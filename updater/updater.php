<?php

/*
 * The ignis updater service (Weg B, App\Update\DockerUpdateService). Runs in its own container, the only one with the Docker
 * socket, on the internal network `update` (no published port).
 *
 *   php -S 0.0.0.0:8099 updater/updater.php   answers the signed requests of the app (App\Update\DockerUpdateEndpoint)
 *   php updater/updater.php run <version> <0|1>   one update (started detached by the endpoint)
 *   php updater/updater.php rollback              back to the newest matching backup
 *   php updater/updater.php recover               on container start: rolls back a run a killed updater left behind
 *   php updater/updater.php resolve --yes         after a failed rollback repaired by hand: mark it resolved
 *
 * It boots this repository's Laravel application (config, translations, HTTP client) but registers no routes: the
 * endpoint above is the whole surface. No database connection is opened here.
 */

use App\Update\DockerUpdateEndpoint;
use App\Update\DockerUpdateService;
use App\Update\UpdateException;
use App\Update\UpdateLock;
use App\Update\UpdateState;
use Illuminate\Contracts\Console\Kernel;

$base = dirname(__DIR__);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (PHP_SAPI === 'cli-server') {
    try {
        $endpoint = new DockerUpdateEndpoint((string) config('ignis.update.updater_secret'), app(DockerUpdateService::class), new UpdateLock, sys_get_temp_dir().'/ignis-updater-seen.json');
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $body = (string) file_get_contents('php://input', false, null, 0, DockerUpdateEndpoint::MAX_BODY + 1);
        [$status, $data] = $endpoint->handle($method, $path, $_SERVER['HTTP_X_IGNIS_TIMESTAMP'] ?? null, $_SERVER['HTTP_X_IGNIS_NONCE'] ?? null, $_SERVER['HTTP_X_IGNIS_SIGNATURE'] ?? null, $body);
    } catch (Throwable $e) {
        error_log('updater: '.$e::class); // no message: it could carry foreign text
        [$status, $data] = [500, ['reason' => 'error']];
    }
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data);

    return true;
}

$updater = app(DockerUpdateService::class);
$command = $argv[1] ?? '';
try {
    if ($command === 'run' && isset($argv[2], $argv[3])) {
        $updater->run($argv[2], $argv[3] === '1');
        $s = app(UpdateState::class)->read();
        fwrite(STDOUT, "{$s['status']}\n");
        exit($s['status'] === 'done' ? 0 : 1);
    }
    if ($command === 'rollback') {
        exit($updater->rollbackToLatest() ? 0 : 1);
    }
    if ($command === 'resolve') {
        if (($argv[2] ?? '') !== '--yes') {
            fwrite(STDERR, "Only after code, database and image were restored by hand (docs/dev/update-recovery.md): resolve --yes\n");
            exit(1);
        }
        fwrite(STDOUT, $updater->resolveFailedRollback()."\n");
        exit(0);
    }
    if ($command === 'recover') {
        fwrite(STDOUT, $updater->recover()."\n");
        exit(0);
    }
} catch (UpdateException $e) {
    fwrite(STDERR, $e->reason.': '.$e->getMessage()."\n");
    exit(1);
}
fwrite(STDERR, "usage: updater.php run <version> <0|1> | rollback | recover | resolve --yes\n");
exit(2);
