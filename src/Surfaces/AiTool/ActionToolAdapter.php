<?php

namespace Gtapps\LaravelAgentic\Surfaces\AiTool;

use Gtapps\LaravelAgentic\AgenticManager;
use Gtapps\LaravelAgentic\Approvals\ApprovalRequiredException;
use Gtapps\LaravelAgentic\Approvals\ApprovalRequirement;
use Gtapps\LaravelAgentic\Contracts\ActionContext;
use Gtapps\LaravelAgentic\Enums\Surface;
use Gtapps\LaravelAgentic\Exceptions\ActionDenied;
use Gtapps\LaravelAgentic\Kernel\ActionCall;
use Gtapps\LaravelAgentic\Kernel\ActionDefinition;
use Gtapps\LaravelAgentic\Kernel\ActionPreparer;
use Gtapps\LaravelAgentic\Kernel\ContextFactory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * One generic adapter implementing laravel/ai's tool contract per
 * definition; Agentic::tools() yields these for any agent's tools().
 *
 * @internal
 */
class ActionToolAdapter implements Approvable, Tool
{
    use InteractsWithApprovals;

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
        protected ActionPreparer $preparer,
        protected ApprovalRequirement $requirement,
        protected ?Authenticatable $principal = null,
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
     * Whether laravel/ai should pause this call for a human.
     *
     * Answers from policy alone — readOnly plus the approval predicate — and
     * touches no approval state. laravel/ai asks again when a paused run
     * resumes, and the answer decides which tool calls MUST carry a decision:
     * consulting grant state here would flip the answer once a human decided,
     * either rejecting the app's decision map or, worse, quietly ungating the
     * call. Minting a knock here would be just as wrong, because a resume's
     * second ask would raise a duplicate.
     *
     * The knock itself is raised by whoever acts on the pause — the gate on the
     * execution path, or Agentic::approvalDecisions() while mapping a resume.
     */
    public function shouldRequestApproval(Request $request): ?Approval
    {
        $call = new ActionCall(
            $this->definition->name,
            $request->toArray(),
            $this->context($request),
        );

        try {
            $this->preparer->prepare($call);
        } catch (ActionDenied|ValidationException) {
            // Denials and invalid arguments are the pipeline's to report, in
            // band, when the call actually runs — not reasons to pause.
            // Anything else is a real fault and must not be mistaken for
            // "no approval needed".
            return null;
        }

        return $this->requirement->required($call)
            ? Approval::required("Approval required for action '{$this->definition->name}'.")
            : null;
    }

    /**
     * Errors are returned in-band as text the model can act on.
     */
    public function handle(Request $request): Stringable|string
    {
        $context = $this->context($request);

        try {
            $result = $this->agentic->run($this->definition->name, $request->toArray(), $context);
        } catch (ApprovalRequiredException|ActionDenied $e) {
            return $e->getMessage();
        } catch (ValidationException $e) {
            return 'Invalid arguments: '.json_encode($e->errors());
        }

        return json_encode($result->value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The explicit principal passed to Agentic::tools() when given, otherwise
     * the ambient guard's user. The provider's tool-call id rides along as the
     * idempotency key: unlike the per-context request id it is the same on the
     * pause and on the resume, which is what lets one tool call keep one
     * approval instead of collapsing with its identical-argument siblings.
     */
    protected function context(Request $request): ActionContext
    {
        return $this->contexts->make(
            Surface::AiTool,
            $this->principal ?? $this->auth->guard()->user(),
            idempotencyKey: $request->toolCallId(),
        );
    }
}
