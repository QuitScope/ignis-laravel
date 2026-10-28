<?php

namespace Modules\Enotf\Console;

use App\Modules\ModuleCommand;
use Modules\Enotf\Support\ShareService;

/** Marks pending share requests past their 30 minutes `expired` (they are already ignored when read). Hourly via schedule(). */
class ExpireSharesCommand extends ModuleCommand
{
    protected $signature = 'enotf:share-expire';

    protected $description = 'Mark expired eNOTF share requests';

    public function module(): string
    {
        return 'enotf';
    }

    public function handle(ShareService $shares): int
    {
        $this->info((string) $shares->expire());

        return self::SUCCESS;
    }
}
