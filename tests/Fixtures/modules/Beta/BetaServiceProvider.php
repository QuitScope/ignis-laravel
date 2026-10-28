<?php

namespace Tests\Fixtures\Modules\Beta;

use App\Modules\ModuleProvider;

class BetaServiceProvider extends ModuleProvider
{
    public function moduleName(): string
    {
        return 'Beta';
    }
}
