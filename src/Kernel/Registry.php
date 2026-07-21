<?php

namespace Gtapps\LaravelAgentic\Kernel;

use Gtapps\LaravelAgentic\Attributes\AgentAction;
use Gtapps\LaravelAgentic\Enums\Mismatch;
use Gtapps\LaravelAgentic\Enums\Surface;
use Gtapps\LaravelAgentic\Schema\SchemaCompiler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use RuntimeException;
use Spatie\LaravelData\Data;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * @internal
 */
class Registry
{
    /** @var list<class-string> explicitly registered (package layer — app scan overrides by name) */
    protected array $registered = [];

    /** @var array<string, ActionDefinition>|null */
    protected ?array $definitions = null;

    public function __construct(
        protected SchemaCompiler $compiler,
        protected Application $app,
    ) {}

    /**
     * @param  list<class-string>  $classes
     */
    public function register(array $classes): void
    {
        $this->registered = array_merge($this->registered, $classes);
        $this->definitions = null;
    }

    /** @return array<string, ActionDefinition> keyed by action name */
    public function definitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        if (is_file($cache = $this->cachePath())) {
            return $this->definitions = array_map(ActionDefinition::fromArray(...), require $cache);
        }

        return $this->definitions = $this->build();
    }

    public function find(string $name): ?ActionDefinition
    {
        return $this->definitions()[$name] ?? null;
    }

    public function cachePath(): string
    {
        return $this->app->bootstrapPath('cache/agentic.php');
    }

    public function cache(): void
    {
        $definitions = array_map(fn (ActionDefinition $d) => $d->toArray(), $this->build());

        file_put_contents(
            $this->cachePath(),
            '<?php return '.var_export($definitions, true).';'.PHP_EOL
        );

        $this->definitions = null;
    }

    public function clearCache(): void
    {
        if (is_file($this->cachePath())) {
            unlink($this->cachePath());
        }

        $this->definitions = null;
    }

    /** @return array<string, ActionDefinition> */
    protected function build(): array
    {
        $definitions = [];

        // Package layer first; app-scanned classes override by name.
        foreach ($this->registered as $class) {
            $this->add($definitions, $class);
        }

        foreach ($this->app['config']->get('agentic.discovery.paths', []) as $path) {
            foreach ($this->classesIn($path) as $class) {
                $this->add($definitions, $class);
            }
        }

        return $definitions;
    }

    /**
     * @param  array<string, ActionDefinition>  $definitions
     */
    protected function add(array &$definitions, string $class): void
    {
        $definition = $this->buildDefinition($class);

        if ($definition !== null) {
            $definitions[$definition->name] = $definition;
        }
    }

    /**
     * Build one definition; a broken action logs a warning and is skipped —
     * never crashes boot.
     */
    protected function buildDefinition(string $class): ?ActionDefinition
    {
        try {
            $reflection = new ReflectionClass($class);

            $attribute = $reflection->getAttributes(AgentAction::class)[0] ?? null;

            if ($attribute === null) {
                return null;
            }

            $meta = $attribute->newInstance();

            if (! $reflection->hasMethod('handle')) {
                throw new RuntimeException('action class must define a handle() method');
            }

            if ($meta->outputMismatch === Mismatch::Fallback && ! $reflection->hasMethod('outputFallback')) {
                throw new RuntimeException('outputMismatch Fallback requires an outputFallback(): mixed method');
            }

            $inputClass = $this->inputClassOf($reflection);
            $inputSchema = $inputClass
                ? $this->compiler->compile($inputClass)
                : ['type' => 'object', 'properties' => [], 'additionalProperties' => false];

            $compactSchema = $meta->agentInputSchema
                ? $this->compiler->compile($meta->agentInputSchema)
                : null;

            if ($compactSchema !== null) {
                $this->lintCoherence($inputSchema, $compactSchema);
            }

            $attributes = [
                'name' => $meta->name,
                'description' => $meta->description,
                'handler' => $class,
                'readOnly' => $meta->readOnly,
                'needsApproval' => $meta->needsApproval,
                'surfaces' => Surface::values($meta->surfaces),
                'inputClass' => $inputClass,
                'inputSchema' => $inputSchema,
                'compactInputClass' => $meta->agentInputSchema,
                'compactInputSchema' => $compactSchema,
                'outputSchema' => $meta->outputSchema,
                'outputMismatch' => $meta->outputMismatch->value,
                'audit' => $meta->audit ?? ! $meta->readOnly,
            ];

            return ActionDefinition::fromArray(
                $attributes + ['definitionHash' => ActionDefinition::hash($attributes)]
            );
        } catch (\Throwable $e) {
            Log::warning("laravel-agentic: skipping action {$class}: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * The input DTO is the first handle() parameter typed as a Data subclass.
     */
    protected function inputClassOf(ReflectionClass $reflection): ?string
    {
        foreach ($reflection->getMethod('handle')->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof \ReflectionNamedType
                && ! $type->isBuiltin()
                && is_subclass_of($type->getName(), Data::class)) {
                return $type->getName();
            }
        }

        return null;
    }

    /**
     * Every field required by the full schema must exist in the compact
     * schema's properties, else the model structurally can never make a
     * valid call.
     */
    protected function lintCoherence(array $full, array $compact): void
    {
        $missing = array_diff(
            $full['required'] ?? [],
            array_keys($compact['properties'] ?? [])
        );

        if ($missing !== []) {
            throw new RuntimeException(
                'compact agentInputSchema is missing required field(s): '.implode(', ', $missing)
            );
        }
    }

    /** @return list<class-string> */
    protected function classesIn(string $path): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $classes = [];

        foreach (Finder::create()->files()->in($path)->name('*.php') as $file) {
            $class = $this->classFromFile($file);

            if ($class === null) {
                continue;
            }

            try {
                if (! class_exists($class)) {
                    require_once $file->getPathname();
                }

                if (class_exists($class, false)) {
                    $classes[] = $class;
                }
            } catch (\Throwable $e) {
                Log::warning("laravel-agentic: skipping file {$file->getPathname()}: {$e->getMessage()}");
            }
        }

        return $classes;
    }

    /**
     * Lexes namespace + class name without executing the file, so a broken
     * file can be reported instead of fataling boot.
     */
    protected function classFromFile(SplFileInfo $file): ?string
    {
        $tokens = @token_get_all((string) file_get_contents($file->getPathname()));

        $namespace = '';

        foreach ($tokens as $i => $token) {
            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                for ($j = $i + 1; $j < count($tokens); $j++) {
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                        $namespace = $tokens[$j][1];
                        break;
                    }
                    if ($tokens[$j] === ';') {
                        break;
                    }
                }
            }

            if ($token[0] === T_CLASS) {
                // Skip `Foo::class` constants (e.g. inside attribute arguments).
                $previous = $tokens[$i - 1] ?? null;
                if (is_array($previous) && $previous[0] === T_DOUBLE_COLON) {
                    continue;
                }

                for ($j = $i + 1; $j < count($tokens); $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        return ($namespace === '' ? '' : $namespace.'\\').$tokens[$j][1];
                    }
                    if (! is_array($tokens[$j]) && $tokens[$j] !== '') {
                        break;
                    }
                }
            }
        }

        return null;
    }
}
