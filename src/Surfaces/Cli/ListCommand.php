<?php

namespace Gtapps\LaravelAgentic\Surfaces\Cli;

use Gtapps\LaravelAgentic\Enums\Surface;
use Gtapps\LaravelAgentic\Kernel\ActionDefinition;
use Gtapps\LaravelAgentic\Kernel\Registry;
use Illuminate\Console\Command;

class ListCommand extends Command
{
    protected $signature = 'agentic:list';

    protected $description = 'List registered agentic actions';

    public function handle(Registry $registry): int
    {
        $definitions = $registry->definitions();

        if ($definitions === []) {
            $this->info('No agentic actions registered.');

            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'Surfaces', 'Read-only', 'Needs approval'],
            collect($definitions)->map(fn (ActionDefinition $d) => [
                $d->name,
                implode(', ', Surface::values($d->surfaces)),
                $d->readOnly ? 'yes' : 'no',
                is_string($d->needsApproval) ? $d->needsApproval : ($d->needsApproval ? 'yes' : 'no'),
            ])->values()->all()
        );

        return self::SUCCESS;
    }
}
