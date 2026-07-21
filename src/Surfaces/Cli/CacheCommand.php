<?php

namespace Gtapps\LaravelAgentic\Surfaces\Cli;

use Gtapps\LaravelAgentic\Kernel\Registry;
use Illuminate\Console\Command;

class CacheCommand extends Command
{
    protected $signature = 'agentic:cache';

    protected $description = 'Cache the compiled agentic action manifest (like route:cache)';

    public function handle(Registry $registry): int
    {
        $registry->cache();

        $this->info('Agentic action manifest cached at '.$registry->cachePath());

        return self::SUCCESS;
    }
}
