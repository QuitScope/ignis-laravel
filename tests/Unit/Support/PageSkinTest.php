<?php

use App\Support\LayoutSkin;

it('mirrors resources/js/lib/pageSkin.ts', function () {
    expect(LayoutSkin::for('Enotf/Overview'))->toBe(['skin' => 'classic', 'dark' => false])
        ->and(LayoutSkin::for('FireTab/Index')['skin'])->toBe('classic')
        ->and(LayoutSkin::for('Install/Index')['skin'])->toBe('classic')
        ->and(LayoutSkin::for('Enotf/Admin/Index')['skin'])->toBe('core')
        ->and(LayoutSkin::for('Enotf/Qm/Show')['skin'])->toBe('core')
        ->and(LayoutSkin::for('FireTab/Qm/Show')['skin'])->toBe('core')
        ->and(LayoutSkin::for('Dashboard'))->toBe(['skin' => 'core', 'dark' => false])
        ->and(LayoutSkin::for('Auth/Login'))->toBe(['skin' => 'core', 'dark' => true]);
});
