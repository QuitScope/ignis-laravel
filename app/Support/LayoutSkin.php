<?php

namespace App\Support;

/**
 * The skin of an Inertia page for the first paint (app.blade.php). Same rule as resources/js/lib/pageSkin.ts, which
 * takes over after the first visit — keep both in step: tablet pages (Enotf/*, FireTab/* except their admin, QM and
 * settings pages) and the installer keep `classic`, everything else `core`; the sign-in page is always dark.
 */
final class LayoutSkin
{
    /** @return array{skin: 'classic'|'core', dark: bool} */
    public static function for(string $component): array
    {
        $tablet = preg_match('#^(Enotf|FireTab)/#', $component) === 1
            && preg_match('#^(Enotf/(Admin|Qm|Settings)|FireTab/Qm)/#', $component) !== 1;

        return [
            'skin' => $tablet || str_starts_with($component, 'Install/') ? 'classic' : 'core',
            'dark' => $component === 'Auth/Login',
        ];
    }
}
