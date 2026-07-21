<?php

namespace Gtapps\LaravelAgentic\Surfaces\Cli;

use Gtapps\LaravelAgentic\Kernel\Registry;
use Illuminate\Console\Command;

class CacheCommand extends Command
{
    protected $signature = 'agentic:cache {--allow-empty : Cache even when no actions are found}';

    protected $description = 'Cache the compiled agentic action manifest (like route:cache)';

    public function handle(Registry $registry): int
    {
        $allowEmpty = (bool) $this->option('allow-empty');
        $count = $registry->cache($allowEmpty);

        if ($count === 0 && ! $allowEmpty) {
            $this->line('No agentic actions were found to cache. If your actions are registered at '
                .'runtime via Agentic::register() from a service provider, that provider must be loaded '
                .'in the console context. Fix that and re-run, or pass --allow-empty to cache an '
                .'intentionally empty manifest.');

            $this->fail('agentic:cache found 0 actions — refusing to write an empty manifest.');
        }

        $this->info("Cached {$count} agentic ".str('action')->plural($count).' at '.$registry->cachePath());

        return self::SUCCESS;
    }
}
