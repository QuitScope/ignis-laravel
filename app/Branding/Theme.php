<?php

namespace App\Branding;

use App\Support\Settings;

/**
 * What the pages show of the installation's look: name, logo, accent colour, legal links. Everything that
 * reaches HTML or CSS is validated again here, so a stored garbage value falls back instead of being echoed.
 */
final class Theme
{
    private const ACCENT = '/^#[0-9a-f]{6}$/D';

    private const DEFAULT_ACCENT = '#f0500a';

    private const DARK = ['canvas' => '#08090b', 'surface' => '#141517', 'overlay' => '#1f2023', 'control' => '#2d2e30', 'rim' => '#2d2e30', 'track' => '#0c0d0f', 'card' => '#1c1d1f', 'well' => '#161719', 'danger' => '#f93f4e'];

    private const LIGHT = ['canvas' => '#edeef2', 'surface' => '#f7f8fa', 'overlay' => '#ffffff', 'control' => '#edeef2', 'rim' => '#ebedf0', 'track' => '#f4f6f8', 'card' => '#ffffff', 'well' => '#f4f6f8', 'danger' => '#d01c29'];

    /** @return array{name: string, logo_url: ?string, accent: ?string, meta_image_url: ?string, tagline: ?string, org: ?string} */
    public static function branding(Settings $settings, LogoUpload $logo): array
    {
        $name = (string) ($settings->get('system.name') ?: config('app.name'));
        $logoName = $logo->current();
        $type = trim((string) $settings->get('organisation.type'));
        $city = trim((string) $settings->get('organisation.city'));

        return [
            'name' => $name,
            'logo_url' => $logoName !== null ? '/branding/'.$logoName : null,
            'accent' => self::accent($settings->get('system.accent_color')),
            'meta_image_url' => self::https($settings->get('system.meta_image_url')),
            'tagline' => $type !== '' && $city !== '' ? trans('branding.tagline', ['type' => $type, 'city' => $city]) : null,
            'org' => trim($type.' '.$city) ?: null,
        ];
    }

    /** @return array{imprint_url: ?string, privacy_url: ?string} */
    public static function legal(Settings $settings): array
    {
        return [
            'imprint_url' => self::https($settings->get('legal.imprint_url')),
            'privacy_url' => self::https($settings->get('legal.privacy_url')),
        ];
    }

    public static function accent(mixed $value): ?string
    {
        $hex = is_string($value) ? strtolower($value) : '';

        return preg_match(self::ACCENT, $hex) === 1 && $hex !== self::DEFAULT_ACCENT ? $hex : null;
    }

    public static function css(mixed $accent): string
    {
        $hex = self::accent($accent);
        $core = '';
        foreach ([':root:not([data-skin="classic"])' => self::DARK, ':root:not([data-skin="classic"])[data-theme="light"]' => self::LIGHT] as $selector => $surfaces) {
            $r = AccentRoles::for($hex ?? self::DEFAULT_ACCENT, $surfaces);
            $rgb = implode(', ', AccentRoles::rgb($r['fill']));
            $core .= "{$selector}{--accent:{$r['fill']};--accent-rgb:{$rgb};--accent-hover:".self::hover($r['fill']).";--on-accent:{$r['on']};"
                ."--accent-fill:{$r['fill']};--accent-fill-2:{$r['fill2']};--accent-text:{$r['text']};--accent-focus:{$r['focus']};--accent-press:{$r['press']}}";
        }
        if ($hex === null) {
            return $core;
        }
        [$r, $g, $b] = AccentRoles::rgb($hex);
        $on = self::luminance($r, $g, $b) > 0.179 ? '#0b0b0b' : '#ffffff';

        return $core.':root[data-skin="classic"],:root[data-skin="classic"][data-theme="light"]'
            ."{--accent:{$hex};--accent-hover:".self::hover($hex).";--accent-rgb:{$r}, {$g}, {$b};--on-accent:{$on}}";
    }

    private static function hover(string $hex): string
    {
        $c = AccentRoles::rgb($hex);
        $lum = self::luminance(...$c);

        return AccentRoles::hex(array_map(fn (int $v) => (int) round($lum < 0.05 ? $v + (255 - $v) * 0.2 : $v * 0.88), $c));
    }

    private static function luminance(int $r, int $g, int $b): float
    {
        [$r, $g, $b] = array_map(fn (int $c) => ($c /= 255) <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, [$r, $g, $b]);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    private static function https(mixed $url): ?string
    {
        return is_string($url) && str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
    }
}
