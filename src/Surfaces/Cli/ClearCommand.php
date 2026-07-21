<?php

namespace Gtapps\LaravelAgentic\Surfaces\Cli;

use Gtapps\LaravelAgentic\Kernel\Registry;
use Illuminate\Console\Command;

class ClearCommand extends Command
{
    protected $signature = 'agentic:clear';

    protected $description = 'Remove the cached agentic action manifest';

    public function handle(Registry $registry): int
    {
        $registry->clearCache();

        $this->info('Agentic action manifest cache cleared.');

        return self::SUCCESS;
    }
}
