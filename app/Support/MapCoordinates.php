<?php

namespace App\Support;

/** GTA world coordinates -> position on the situation map in percent (0-100). Ported from the legacy helper. */
final class MapCoordinates
{
    private const REFERENCE_MAP_X = 45.8;

    private const REFERENCE_MAP_Y = 67.38;

    private const SCALE_X = 123.29;

    private const SCALE_Y = 123.96;

    /** @return array{x: float, y: float} */
    public static function gtaToMap(float $x, float $y): array
    {
        $mapX = self::REFERENCE_MAP_X + $x / self::SCALE_X;
        $mapY = self::REFERENCE_MAP_Y - $y / self::SCALE_Y;

        return [
            'x' => round(max(0.0, min(100.0, $mapX)), 2),
            'y' => round(max(0.0, min(100.0, $mapY)), 2),
        ];
    }

    /** @return array{x: float, y: float} */
    public static function mapToGta(float $mapX, float $mapY): array
    {
        return [
            'x' => round(($mapX - self::REFERENCE_MAP_X) * self::SCALE_X, 2),
            'y' => round(-($mapY - self::REFERENCE_MAP_Y) * self::SCALE_Y, 2),
        ];
    }
}
