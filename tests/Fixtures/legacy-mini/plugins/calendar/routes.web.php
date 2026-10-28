<?php

$router->get('/calendar', [CalendarController::class, 'index'], [new PermissionMiddleware(['calendar.view', 'admin'])]);
$router->delete('/calendar/events/{id:\d+}', [CalendarController::class, 'destroy']);
