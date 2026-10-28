<?php

namespace App\Modules;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Base of a module's Artisan command: while the module is off, handle() is skipped, the command says so and succeeds,
 * so a scheduler or cron entry does not report a failure.
 */
abstract class ModuleCommand extends Command implements BelongsToModule
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (! ModuleRegistry::active($this->module())) {
            $output->writeln('Modul '.$this->module().' ist deaktiviert');

            return self::SUCCESS;
        }

        return parent::execute($input, $output);
    }
}
