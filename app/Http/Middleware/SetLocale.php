<?php

namespace App\Http\Middleware;

use App\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = app(Settings::class)->get('app.locale', config('app.locale'));

        app()->setLocale(in_array($locale, config('ignis.locales'), true) ? $locale : 'de');

        return $next($request);
    }
}
