<!DOCTYPE html>
@php
    $branding = $page['props']['branding'] ?? [];
    $siteName = $page['props']['app']['name'] ?? config('app.name');
    $metaImage = $branding['meta_image_url'] ?? null;
    $themeMode = \App\Models\User::themeOrDefault($page['props']['theme'] ?? null);
    $skin = \App\Support\LayoutSkin::for((string) ($page['component'] ?? ''));
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-skin="{{ $skin['skin'] }}" data-theme-mode="{{ $themeMode }}" data-theme="{{ $themeMode === 'light' && ! $skin['dark'] ? 'light' : 'dark' }}" @if ($skin['dark']) data-force-dark @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ $siteName }}</title>
    <meta property="og:title" content="{{ $siteName }}">
    <meta property="og:site_name" content="{{ $siteName }}">
    <meta property="og:description" content="{{ $branding['tagline'] ?? $siteName }}">
    @if (is_string($metaImage) && str_starts_with($metaImage, 'https://'))
        <meta property="og:image" content="{{ $metaImage }}">
    @endif
    <script>
        (function () {
            var d = document.documentElement, m = d.dataset.themeMode;
            if (d.hasAttribute('data-force-dark')) return;
            if (m === 'system') m = !window.matchMedia || matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            d.dataset.theme = m === 'light' ? 'light' : 'dark';
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
    {{-- After the stylesheet so it wins; always (the accent roles of the skin). Theme::css() re-validates the hex and builds the rules from digits only. --}}
    <style id="ignis-accent">{!! \App\Branding\Theme::css($branding['accent'] ?? null) !!}</style>
    @if (! empty($page['props']['noindex']))
        <meta name="robots" content="noindex">
    @endif
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
