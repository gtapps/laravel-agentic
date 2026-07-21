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
        $count = $registry->cache();

        if ($count === 0) {
            $this->line('No agentic actions were found to cache. If your actions are registered at '
                .'runtime via Agentic::register() from a service provider, that provider must be loaded '
                .'in the console context. Fix that and re-run.');

            $this->fail('agentic:cache found 0 actions — refusing to write an empty manifest.');
        }

        $this->info("Cached {$count} agentic ".str('action')->plural($count).' at '.$registry->cachePath());

        return self::SUCCESS;
    }
}
