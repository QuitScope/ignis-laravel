<?php

use Illuminate\Support\Facades\Route;

// ModuleProvider wraps this file in ['module:demo', 'web']; the module gate runs before auth.
Route::middleware('auth')->get('/demo/ping', fn () => 'pong');
