<?php

namespace Gtapps\LaravelAgentic\Surfaces\Cli;

use Gtapps\LaravelAgentic\Enums\Surface;
use Gtapps\LaravelAgentic\Kernel\ActionDefinition;
use Gtapps\LaravelAgentic\Kernel\GateResolver;
use Gtapps\LaravelAgentic\Kernel\Registry;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Collection;
use ReflectionClass;

class ListCommand extends Command
{
    protected $signature = 'agentic:list';

    protected $description = 'List registered agentic actions';

    public function handle(Registry $registry, Repository $config, GateResolver $gates): int
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
                $this->gate($d, $gates),
            ])->values()->all()
        );

        return self::SUCCESS;
    }

    /**
     * Which of the surfaces the action is exposed on are actually gated: `all`
     * or `none` when they agree, otherwise the holes by name — `open` for a
     * surface no method gates, `broken` for a gate the container cannot call,
     * which throws on every call rather than running.
     *
     * It asks GateResolver the same question Authorize asks instead of
     * counting method names, because a surface method *replaces* the generic
     * one. Counting made an override read as wider coverage than the gate it
     * narrowed, and reported gates for surfaces the action isn't exposed on.
     *
     * Reflected from the handler rather than stored on the definition: a new
     * definition field would change every definitionHash and void every
     * outstanding approval grant.
     */
    protected function gate(ActionDefinition $definition, GateResolver $gates): string
    {
        if (! class_exists($definition->handler)) {
            // A cached manifest can name a class this process cannot autoload;
            // the registry only requires scanned files when it builds fresh.
            return '?';
        }

        $reflection = new ReflectionClass($definition->handler);

        $states = collect($definition->surfaces)->mapWithKeys(function (Surface $surface) use ($gates, $reflection) {
            $method = $gates->methodFor($reflection, $surface);

            return [$surface->value => match (true) {
                $method === null => 'open',
                $method->isPublic() => 'gated',
                default => 'broken',
            }];
        });

        foreach (['gated' => 'all', 'open' => 'none'] as $state => $label) {
            if ($states->every(fn (string $s) => $s === $state)) {
                return $label;
            }
        }

        return collect(['open', 'broken'])
            ->mapWithKeys(fn (string $state) => [
                $state => $states->filter(fn (string $s) => $s === $state)->keys(),
            ])
            ->reject(fn (Collection $surfaces) => $surfaces->isEmpty())
            ->map(fn (Collection $surfaces, string $state) => $state.': '.$surfaces->implode(', '))
            ->implode('; ');
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
