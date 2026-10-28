<?php

use Illuminate\Support\Facades\Route;

Route::get('/api/demo/ping', fn () => ['pong' => true]);
