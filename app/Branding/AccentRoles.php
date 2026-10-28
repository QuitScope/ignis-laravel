<?php

namespace App\Branding;

/**
 * The accent roles of the legacy skin `core`, computed once on the server instead of at runtime: a port of d() in
 * ignis/public/assets/js/ui/preferences.js (contrast search in sRGB, hue shift in OKLab). Pure arithmetic on #rrggbb.
 */
final class AccentRoles
{
    private const HUE = 40;

    private const SHIFT = 32;

    /**
     * @param  array{canvas: string, surface: string, overlay: string, control: string, rim: string, track: string, card: string, well: string, danger: string}  $surfaces  #rrggbb of the theme
     * @return array{on: string, press: string, fill: string, fill2: string, text: string, focus: string, bar: string, dangerFill: string}
     */
    public static function for(string $hex, array $surfaces): array
    {
        $s = array_map(self::rgb(...), $surfaces);
        $e = self::rgb($hex);
        $white = [255, 255, 255];
        $dark = self::fromOklch(0.2, 0.03, self::HUE);
        $on = self::distance(self::accessible($e, $dark), $e) < self::distance(self::accessible($e, $white), $e) ? $dark : $white;
        $fill = self::accessible($e, $on);

        return array_map(self::hex(...), [
            'on' => $on,
            'press' => $on === $white ? [0, 0, 0] : $white,
            'fill' => $fill,
            'fill2' => self::accessible(self::rotate($e, self::SHIFT), $on),
            'text' => self::chain($e, [$s['surface'], $s['overlay'], $s['control'], $s['rim']], 4.5),
            'focus' => self::chain($e, [$s['canvas'], $s['surface'], $s['overlay'], $s['control'], $s['rim']], 3),
            'bar' => self::chain($fill, [$s['track'], $s['card'], $s['well']], 3),
            'dangerFill' => self::accessible($s['danger'], $white),
        ]);
    }

    /** @return list<int> */
    public static function rgb(string $hex): array
    {
        return array_map('hexdec', str_split(substr($hex, 1), 2));
    }

    /** @param list<int> $c */
    public static function hex(array $c): string
    {
        return vsprintf('#%02x%02x%02x', $c);
    }

    /**
     * Moves $c towards white or black (whichever contrasts more with $bg) in 1 % steps until the contrast reaches $min.
     *
     * @param  list<int>  $c
     * @param  list<int>  $bg
     * @return list<int>
     */
    private static function accessible(array $c, array $bg, float $min = 4.5): array
    {
        $t = self::contrast([255, 255, 255], $bg) > self::contrast([0, 0, 0], $bg) ? 255 : 0;
        for ($a = 0; $a <= 100; $a++) {
            $o = array_map(fn (int $v) => (int) round($v + ($t - $v) * $a / 100), $c);
            if (self::contrast($o, $bg) >= $min) {
                return $o;
            }
        }

        return [$t, $t, $t];
    }

    /**
     * @param  list<int>  $c
     * @param  list<list<int>>  $bgs
     * @return list<int>
     */
    private static function chain(array $c, array $bgs, float $min): array
    {
        foreach ($bgs as $bg) {
            $c = self::accessible($c, $bg, $min);
        }

        return $c;
    }

    /** @param list<int> $c */
    private static function luminance(array $c): float
    {
        $l = array_map(self::linear(...), $c);

        return 0.2126 * $l[0] + 0.7152 * $l[1] + 0.0722 * $l[2];
    }

    /**
     * @param  list<int>  $a
     * @param  list<int>  $b
     */
    private static function contrast(array $a, array $b): float
    {
        [$x, $y] = [self::luminance($a), self::luminance($b)];

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    private static function linear(int $c): float
    {
        $c /= 255;

        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }

    private static function gamma(float $c): int
    {
        return (int) round(255 * min(1, max(0, $c <= 0.0031308 ? 12.92 * $c : 1.055 * $c ** (1 / 2.4) - 0.055)));
    }

    /**
     * @param  list<int>  $c
     * @return array{float, float, float}
     */
    private static function oklab(array $c): array
    {
        [$r, $g, $b] = array_map(self::linear(...), $c);
        $l = (0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b) ** (1 / 3);
        $m = (0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b) ** (1 / 3);
        $s = (0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b) ** (1 / 3);

        return [
            0.2104542553 * $l + 0.793617785 * $m - 0.0040720468 * $s,
            1.9779984951 * $l - 2.428592205 * $m + 0.4505937099 * $s,
            0.0259040371 * $l + 0.7827717662 * $m - 0.808675766 * $s,
        ];
    }

    /** @return list<int> */
    private static function fromOklab(float $L, float $a, float $b): array
    {
        $l = ($L + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($L - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($L - 0.0894841775 * $a - 1.291485548 * $b) ** 3;

        return array_map(self::gamma(...), [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.707614701 * $s,
        ]);
    }

    /** @return list<int> */
    private static function fromOklch(float $L, float $C, float $h): array
    {
        return self::fromOklab($L, $C * cos(deg2rad($h)), $C * sin(deg2rad($h)));
    }

    /**
     * @param  list<int>  $c
     * @return list<int>
     */
    private static function rotate(array $c, float $deg): array
    {
        [$L, $a, $b] = self::oklab($c);
        [$cos, $sin] = [cos(deg2rad($deg)), sin(deg2rad($deg))];

        return self::fromOklab($L, $a * $cos - $b * $sin, $a * $sin + $b * $cos);
    }

    /**
     * @param  list<int>  $a
     * @param  list<int>  $b
     */
    private static function distance(array $a, array $b): float
    {
        [$x, $y] = [self::oklab($a), self::oklab($b)];

        return hypot(hypot($x[0] - $y[0], $x[1] - $y[1]), $x[2] - $y[2]);
    }
}
