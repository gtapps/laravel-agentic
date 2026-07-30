<?php

namespace Gtapps\LaravelAgentic\Surfaces\Cli;

use Gtapps\LaravelAgentic\Enums\Surface;
use Gtapps\LaravelAgentic\Kernel\ActionDefinition;
use Gtapps\LaravelAgentic\Kernel\Registry;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use ReflectionClass;

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
            ['Name', 'Surfaces', 'Read-only', 'Needs approval', 'Audit', 'Gate'],
            collect($definitions)->map(fn (ActionDefinition $d) => [
                $d->name,
                $this->surfaces($d, $httpMounted),
                $d->readOnly ? 'yes' : 'no',
                is_string($d->needsApproval) ? $d->needsApproval : ($d->needsApproval ? 'yes' : 'no'),
                $d->isAuditEffective($config) ? 'yes' : 'no',
                $this->gate($d),
            ])->values()->all()
        );

        return self::SUCCESS;
    }

    /**
     * Which authorize methods the action actually defines — `none` when it is
     * ungated everywhere, `all` for a generic authorize(), plus each surface
     * that overrides it. Reflected from the handler rather than stored on the
     * definition: a new definition field would change every definitionHash and
     * void every outstanding approval grant.
     */
    protected function gate(ActionDefinition $definition): string
    {
        if (! class_exists($definition->handler)) {
            // A cached manifest can name a class this process cannot autoload;
            // the registry only requires scanned files when it builds fresh.
            return '?';
        }

        $reflection = new ReflectionClass($definition->handler);

        $gates = collect(Surface::cases())
            ->filter(fn (Surface $s) => $reflection->hasMethod($s->authorizeMethod()))
            ->map(fn (Surface $s) => $s->value)
            ->values();

        if ($reflection->hasMethod('authorize')) {
            $gates->prepend('all');
        }

        return $gates->isEmpty() ? 'none' : $gates->implode(', ');
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
