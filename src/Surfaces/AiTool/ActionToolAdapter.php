<?php

namespace Gtapps\LaravelAgentic\Surfaces\AiTool;

use Gtapps\LaravelAgentic\AgenticManager;
use Gtapps\LaravelAgentic\Approvals\ApprovalRequiredException;
use Gtapps\LaravelAgentic\Enums\Surface;
use Gtapps\LaravelAgentic\Exceptions\ActionDenied;
use Gtapps\LaravelAgentic\Kernel\ActionDefinition;
use Gtapps\LaravelAgentic\Kernel\ContextFactory;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * One generic adapter implementing laravel/ai's tool contract per
 * definition; Agentic::tools() yields these for any agent's tools().
 *
 * @internal
 */
class ActionToolAdapter implements Tool
{
    /**
     * Memoized: laravel/ai's generation loop calls schema() on every step
     * of one prompt() for every tool; the Type graph is read-only
     * downstream, so rebuilding it per step is pure waste.
     *
     * @var array<string, Type>|null
     */
    protected ?array $types = null;

    public function __construct(
        protected ActionDefinition $definition,
        protected AgenticManager $agentic,
        protected ContextFactory $contexts,
        protected AuthFactory $auth,
    ) {}

    public function name(): string
    {
        return $this->definition->name;
    }

    public function description(): Stringable|string
    {
        return $this->definition->description;
    }

    /**
     * The compiled schema (compact when declared) translated to
     * illuminate/json-schema types — laravel/ai's schema dialect.
     */
    public function schema(JsonSchema $schema): array
    {
        if ($this->types !== null) {
            return $this->types;
        }

        $agentSchema = $this->definition->agentSchema();
        $required = $agentSchema['required'] ?? [];

        $types = [];

        foreach ($agentSchema['properties'] ?? [] as $property => $fragment) {
            $type = JsonSchemaFactory::fromArray($fragment);

            if (in_array($property, $required, true)) {
                $type = $type->required();
            }

            $types[$property] = $type;
        }

        return $this->types = $types;
    }

    /**
     * Context is the running agent's user. Errors are returned
     * in-band as text the model can act on.
     */
    public function handle(Request $request): Stringable|string
    {
        $context = $this->contexts->make(Surface::AiTool, $this->auth->guard()->user());

        try {
            $result = $this->agentic->run($this->definition->name, $request->toArray(), $context);
        } catch (ApprovalRequiredException|ActionDenied $e) {
            return $e->getMessage();
        } catch (ValidationException $e) {
            return 'Invalid arguments: '.json_encode($e->errors());
        }

        return json_encode($result->value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
