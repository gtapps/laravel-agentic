<?php

namespace Gtapps\LaravelAgentic\Surfaces\Cli;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;

/**
 * Blank scaffold only — no route/FormRequest/Tool introspection. Extends
 * GeneratorCommand (unlike the package's other CLI commands) to delegate
 * namespace/path/stub/--force handling to the framework instead of
 * reimplementing it.
 */
class MakeActionCommand extends GeneratorCommand
{
    protected $name = 'agentic:make-action';

    protected $description = 'Create a new agentic action';

    protected $type = 'Action';

    public function handle(): ?int
    {
        // handle()'s return is cast with (int): `false`/`null` become exit
        // code 0, `true` becomes 1 — so success paths must return null, and
        // failure paths must return self::FAILURE, never a bare bool.
        if (parent::handle() === false) {
            return self::FAILURE;
        }

        $inputName = $this->qualifyClass($this->getNameInput()).'Input';
        $inputPath = $this->getPath($inputName);

        if ($this->files->exists($inputPath) && ! $this->option('force')) {
            $this->components->error('Action input already exists.');

            return self::FAILURE;
        }

        $this->makeDirectory($inputPath);

        $this->files->put($inputPath, $this->buildInputClass($inputName));

        $this->components->info(sprintf('Action input [%s] created successfully.', $inputPath));

        return null;
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Actions';
    }

    protected function getStub(): string
    {
        return $this->stubPath();
    }

    protected function buildClass($name): string
    {
        $inputClass = class_basename($name).'Input';

        return str_replace(
            ['{{ inputClass }}', '{{ actionName }}'],
            [$inputClass, Str::kebab(class_basename($name))],
            parent::buildClass($name)
        );
    }

    protected function buildInputClass(string $name): string
    {
        $stub = $this->files->get($this->getInputStub());

        return $this->replaceNamespace($stub, $name)->replaceClass($stub, $name);
    }

    protected function getInputStub(): string
    {
        return $this->stubPath('.input');
    }

    protected function stubPath(string $suffix = ''): string
    {
        $prefix = $this->option('paginated') ? 'action.paginated' : 'action';

        return __DIR__."/../../../stubs/{$prefix}{$suffix}.stub";
    }

    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite the action if it already exists'],
            ['paginated', null, InputOption::VALUE_NONE, 'Generate a paginated listing action'],
        ];
    }
}
