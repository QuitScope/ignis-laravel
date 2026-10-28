<?php

namespace Tests\Fixtures\Modules\Alpha;

use App\Modules\ModuleProvider;

class AlphaServiceProvider extends ModuleProvider
{
    public function moduleName(): string
    {
        return 'Alpha';
    }

    public function core(): bool
    {
        return true;
    }
}
