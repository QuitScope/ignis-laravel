<?php

use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Modules\KnowledgeBase\Http\Controllers\KbEntryController;
use Modules\KnowledgeBase\Http\Controllers\KbImageController;
use Modules\KnowledgeBase\Http\Controllers\KbTaxonomyController;

// ModuleProvider wraps this file in ['module:knowledgebase', 'web'].
//
// ORDER MATTERS: the entry routes (`/lexicon`, `/lexicon/create`, `/lexicon/{entry}` ...) below this
// group. The fixed paths `/lexicon/taxonomy`, `/lexicon/categories/*`, `/lexicon/tags/*` must stay registered BEFORE
// the `/lexicon/{entry}` wildcard (and the wildcard needs whereNumber('entry')), or `taxonomy` is read as an entry id.
Route::middleware('auth')->prefix('lexicon')->controller(KbTaxonomyController::class)->group(function () {
    Route::get('/taxonomy', 'index');

    Route::post('/categories', 'storeCategory')->middleware('throttle:60,1,kb-write');
    Route::put('/categories/{category}', 'updateCategory')->whereNumber('category')->middleware('throttle:60,1,kb-write');
    Route::delete('/categories/{category}', 'destroyCategory')->whereNumber('category')->middleware('throttle:60,1,kb-write');

    Route::post('/tags', 'storeTag')->middleware('throttle:60,1,kb-write');
    Route::put('/tags/{tag}', 'updateTag')->whereNumber('tag')->middleware('throttle:60,1,kb-write');
    Route::delete('/tags/{tag}', 'destroyTag')->whereNumber('tag')->middleware('throttle:60,1,kb-write');
});

Route::post('/lexicon/images', [KbImageController::class, 'store'])->middleware(['auth', 'throttle:30,1,kb-images-upload']);
// Without session (public answers must not carry a cookie into a shared cache); the controller starts session and
// `auth` itself while knowledgebase.public_access is off. The core route /storage/{area}/{file} excludes this area.
Route::get('/storage/kb-images/{file}', [KbImageController::class, 'show'])
    ->middleware('throttle:240,1,kb-images')
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, EnsureActiveUser::class, SetLocale::class, HandleInertiaRequests::class]);

Route::middleware('auth')->prefix('lexicon')->controller(KbEntryController::class)->group(function () {
    Route::get('/create', 'create');
    Route::get('/lookup', 'lookup')->middleware('throttle:120,1,kb-lookup');
    Route::post('/', 'store')->middleware('throttle:60,1,kb-write');
    Route::get('/{entry}/edit', 'edit')->whereNumber('entry');
    Route::put('/{entry}', 'update')->whereNumber('entry')->middleware('throttle:60,1,kb-write');
    Route::patch('/{entry}/pin', 'pin')->whereNumber('entry')->middleware('throttle:60,1,kb-write');
    Route::patch('/{entry}/archive', 'archive')->whereNumber('entry')->middleware('throttle:60,1,kb-write');
    Route::patch('/{entry}/hide-editor', 'hideEditor')->whereNumber('entry')->middleware('throttle:60,1,kb-write');
});

// Readable without login while knowledgebase.public_access is on: no `auth` here, the controller sends guests to the
// login (switch off) or answers 404 (archived). Guests are throttled (limiter `kb-read`, see the provider).
Route::middleware('throttle:kb-read')->prefix('lexicon')->controller(KbEntryController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('/{entry}', 'show')->whereNumber('entry');
});
