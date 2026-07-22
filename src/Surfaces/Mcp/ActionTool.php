<?php

namespace Gtapps\LaravelAgentic\Surfaces\Mcp;

use Gtapps\LaravelAgentic\AgenticManager;
use Gtapps\LaravelAgentic\Approvals\ApprovalRequiredException;
use Gtapps\LaravelAgentic\Enums\Surface;
use Gtapps\LaravelAgentic\Exceptions\ActionDenied;
use Gtapps\LaravelAgentic\Exceptions\ActionNotFound;
use Gtapps\LaravelAgentic\Kernel\ActionDefinition;
use Gtapps\LaravelAgentic\Kernel\ContextFactory;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * One generic tool instantiated per registry definition — no codegen.
 *
 * @internal
 */
class ActionTool extends Tool
{
    public function __construct(protected ActionDefinition $definition)
    {
        $this->name = $definition->name;
        $this->description = $definition->description;
    }

    /**
     * Tier filter. laravel/mcp applies this to the ServerContext tool list,
     * which backs BOTH tools/list and tools/call.
     */
    public function shouldRegister(): bool
    {
        $name = $this->definition->name;

        // The hard denylist beats every allowlist.
        if (in_array($name, config('agentic.mcp.exclude', []), true)) {
            return false;
        }

        if (auth()->guest()) {
            return in_array($name, config('agentic.mcp.tiers.unauthenticated', []), true);
        }

        return true;
    }

    public function handle(Request $request, AgenticManager $agentic, ContextFactory $contexts): Response
    {
        $context = $contexts->make(Surface::Mcp, $request->user());

        try {
            $result = $agentic->run($this->definition->name, $request->all(), $context);
        } catch (ApprovalRequiredException|ActionDenied|ActionNotFound $e) {
            return Response::error($e->getMessage());
        } catch (ValidationException $e) {
            return Response::error('Invalid arguments: '.json_encode($e->errors()));
        }

        return Response::json($result->value);
    }

    /**
     * Full schema fidelity: the host sees exactly the compiled schema
     * (compact when declared), not a builder approximation.
     *
     * `properties` is coerced to a stdClass: PHP encodes an empty array as
     * JSON `[]`, which strict MCP clients reject where `{}` is required.
     */
    public function toArray(): array
    {
        $schema = $this->definition->agentSchema();
        $schema['properties'] = (object) ($schema['properties'] ?? []);

        return ['inputSchema' => $schema] + parent::toArray();
    }
}
