<?php

namespace Gtapps\LaravelAgentic\Surfaces\Cli;

use Gtapps\LaravelAgentic\Enums\Surface;
use Gtapps\LaravelAgentic\Kernel\ActionDefinition;
use Gtapps\LaravelAgentic\Kernel\Registry;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

class ListCommand extends Command
{
    protected $signature = 'agentic:list';

    protected $description = 'List registered agentic actions';

    public function handle(Registry $registry, Repository $config): int
    {
        $definitions = $registry->definitions();

        if ($definitions === []) {
            $this->info('No agentic actions registered.');

            return self::SUCCESS;
        }

        $httpMounted = (bool) $config->get('agentic.http.enabled', false);

        $this->table(
            ['Name', 'Surfaces', 'Read-only', 'Needs approval', 'Audit'],
            collect($definitions)->map(fn (ActionDefinition $d) => [
                $d->name,
                $this->surfaces($d, $httpMounted),
                $d->readOnly ? 'yes' : 'no',
                is_string($d->needsApproval) ? $d->needsApproval : ($d->needsApproval ? 'yes' : 'no'),
                $d->isAuditEffective($config) ? 'yes' : 'no',
            ])->values()->all()
        );

        return self::SUCCESS;
    }

    /**
     * Exposure as it actually is, not as declared: the HTTP surface is
     * opt-in, so an action listing `http` while no routes are mounted would
     * read as reachable when it isn't.
     */
    protected function surfaces(ActionDefinition $definition, bool $httpMounted): string
    {
        return collect(Surface::values($definition->surfaces))
            ->map(fn (string $surface) => $surface === Surface::Http->value && ! $httpMounted
                ? 'http (off)'
                : $surface)
            ->implode(', ');
    }
}
