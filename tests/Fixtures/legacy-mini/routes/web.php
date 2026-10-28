<?php

declare(strict_types=1);

/**
 * Fixture routes.
 *   - new PermissionMiddleware('doc.only')
 */

$router->get('/', $rootScript('index.php'));
$router->post('/personnel/delete', [PersonnelController::class, 'delete'], [new PermissionMiddleware('personnel.edit')]);
$router->get(
    '/enotf/p/{enr:[\w._-]+}',
    [EnotfController::class, 'show'],
);
$router->any("/enotf-v2[/{path:.*}]", $enotfV2);
$router->match(['GET', 'POST'], '/login', $rootScript('login.php'));
// $router->get('/commented/line', $x);
/*
$router->get('/commented/block', $x);
*/
$router->post('/personnel/delete', $duplicate);
$router->get('/folder/' . $folders . '/{id:\d+}', [MailController::class, 'folder'], $mailAuth);
$folders = '{folder:inbox|sent}';
$router->get('/mail/' . $folders, [MailController::class, 'folder'], $mailAuth);
foreach (['/notifications', '/notifications/index'] as $notifPath) {
    $router->get($notifPath, $notifRedirect);
}
$legacyApiPaths = [
    '/settings/old'   => '/api/settings/new',
    '/assets/old-fn'  => '/api/fn/new',
];
foreach ($legacyApiPaths as $legacyPath => $target) {
    $router->match(['GET', 'DELETE'], $legacyPath, $legacyApiRedirect($target));
}
$router->get($mystery, $handler);
$router->post('/abilities', $h, [new PolicyMiddleware('personnel.viewList')]);
if (Gate::denies('vehicle.manage')) {
}
