<?php

use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;

// Wegwerf-Router fuer php -S (aus public/ starten): wie Laravels server.php, aber mit dem gebauten Bundle (public/build)
// statt eines laufenden Vite-Dev-Servers (public/hot gehoert evtl. einer anderen Sitzung und bleibt unangetastet).
// DB_DATABASE (Name mit "visual") per Umgebung setzen.
$repo = dirname(__DIR__, 3);
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');
if ($uri !== '/' && is_file($repo.'/public'.$uri)) {
    return false;
}
require $repo.'/vendor/autoload.php';
$app = require $repo.'/bootstrap/app.php';
$app->booted(fn () => $app->make(Vite::class)->useHotFile(__DIR__.'/no-hot-file'));
$app->handleRequest(Request::capture());
