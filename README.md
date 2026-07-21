# laravel-agentic

**An agent-native action layer for Laravel. Define an action once; expose it
to humans and agents everywhere — with approvals and audit built in.**

laravel/mcp and laravel/ai give your agents *ability*; laravel-agentic gives
you *control* over that ability — those packages are the wiring, this is the
breaker panel and the meter.

```
WITHOUT — raw laravel/mcp + laravel/ai
──────────────────────────────────────
dev writes "refund invoice"  ─┬─> MCP Tool class        (laravel/mcp schema dialect, authz ✓, log ✗)
        (× 4, by hand)        ├─> laravel/ai tool class (different schema dialect, authz ✓, log ✗)
                              ├─> Controller+FormRequest (3rd schema dialect, authz ✓, log ✓)
                              └─> Artisan command        (manual args, authz forgotten ← drift)

agent decides to refund ──> MCP tool executes IMMEDIATELY ──> no ledger, no human in the loop
                                                              "what did it do Tuesday?" = unanswerable

WITH laravel-agentic
────────────────────
dev writes ONE action class + input DTO      (schema, authorize(), needsApproval — defined once)
                        │
  MCP · ai-tool · HTTP · CLI · job  ────>  same Runner pipeline (drift impossible)
                        │
agent decides to refund ──> validate ──> policy check ──> KNOCK: human approves
                        ──> grant consumed (single-use, this exact args hash, expires if ignored)
                        ──> execute ──> audit row: who called, via which surface, who approved
```

## Installation

```bash
composer require gtapps/laravel-agentic
php artisan migrate
```

Optionally publish the config and the agent-facing conventions file:

```bash
php artisan vendor:publish --tag=agentic-config
php artisan vendor:publish --tag=agentic-agents-md   # AGENTS.md for your repo
```

Requires PHP 8.3+ and Laravel 12/13. Built on laravel/mcp, laravel/ai, and
spatie/laravel-data.

## Quickstart: one action, every surface

Define an input DTO and an action class in `app/Actions`:

```php
use Spatie\LaravelData\Data;

class RefundInvoiceInput extends Data
{
    public function __construct(
        public int $invoiceId,
        public float $amount,
        public string $reason = 'requested_by_customer',
    ) {}
}
```

```php
use Gtapps\LaravelAgentic\Attributes\AgentAction;
use Gtapps\LaravelAgentic\Contracts\ActionContext;
use Gtapps\LaravelAgentic\Enums\Surface;

#[AgentAction(
    name: 'refund-invoice',
    description: 'Refund an invoice to the original payment method.', // written for the MODEL
    needsApproval: true,
    surfaces: [Surface::Mcp, Surface::AiTool, Surface::Http, Surface::Cli, Surface::Job],
)]
class RefundInvoice
{
    public function authorize(ActionContext $ctx, RefundInvoiceInput $input): bool
    {
        return $ctx->user()->can('refund', Invoice::find($input->invoiceId));
    }

    public function handle(RefundInvoiceInput $input, ActionContext $ctx): RefundResult
    {
        // business logic — $ctx->caller() ∈ {mcp, ai-tool, http, cli, job}
    }
}
```

Expose it over MCP in `routes/ai.php`:

```php
use Gtapps\LaravelAgentic\Surfaces\Mcp\AgenticServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', AgenticServer::class)->middleware(['auth:sanctum']);
```

That single definition is now callable, with identical validation,
authorization, approval, and audit behavior, via:

| Surface | How |
|---|---|
| MCP | `tools/call refund-invoice` on the server above |
| laravel/ai | `Agentic::tools()` inside any agent's `tools()` iterable |
| HTTP | `POST /agentic/actions/refund-invoice` (GET allowed for `readOnly`) |
| CLI | `php artisan agentic:action refund-invoice '{"invoiceId":42,"amount":99.5}' --as=1` |
| Queue | `RunAction::dispatch('refund-invoice', $args, $userId)` |

`php artisan agentic:list` shows everything registered; `agentic:cache`
compiles the manifest like `route:cache`.

## The approval flow

`needsApproval` actions execute only with per-invocation human consent:

1. The agent calls the action. It does not execute; the agent receives an
   agent-legible knock: *"Approval required for action 'refund-invoice'.
   Pending under key `abc…`. Ask a human to run: `php artisan
   agentic:approve abc…`. Then retry this exact call unchanged."*
2. A human runs `agentic:approve <key>` (or `agentic:deny <key>`).
3. The agent retries the identical call — it executes exactly once. The
   grant is consumed; a repeat call knocks again.

Semantics you can rely on:

- Grants are keyed on `sha256(action + canonicalized args)` — different
  arguments knock separately; argument order never matters.
- Grants are **bound to the requesting principal**: another user (or agent
  token) with identical args knocks separately.
- Unanswered knocks and unconsumed grants **expire to deny**
  (`agentic.approvals.ttl`, default 10 minutes).
- A **throwing `needsApproval` predicate fails closed** to "approval
  required".
- If the action definition changed since approval, the grant is void and
  the call knocks again.
- `needsApproval` accepts a predicate class for conditional consent:

```php
#[AgentAction(..., needsApproval: BigRefundsNeedApproval::class)]
// class BigRefundsNeedApproval {
//     public function __invoke(RefundInvoiceInput $input): bool {
//         return $input->amount > 100;
//     }
// }
```

### Wiring approvals to your own channels

v1 ships no HTTP grant/deny endpoints. The `ApprovalRequested` event
carries the pending approval and a single-use capability token; wire any
channel you like and call the broker:

```php
// routes/web.php — POST only. Never grant over GET: chat-app link
// preview prefetchers will auto-approve your links.
Route::post('/approvals/{key}', function (string $key, Request $request) {
    $granted = app(ApprovalBroker::class)->decide(
        $key,
        $request->input('token'),   // timing-safe verified
        approve: true,
        decidedBy: $request->user()->email,
    );

    return $granted ? response()->noContent() : abort(410);
})->middleware(['auth', 'can:approve-agentic']);
```

### The trust boundary, plainly

`agentic:approve` is token-free by design: anyone with artisan access has
`tinker` and could flip the row anyway. In-app approvals bind agents that
reach your app through its surfaces (MCP, HTTP, queue). **An agent with an
unrestricted shell on the app server cannot be bound by in-app approvals**
— gate that layer at the transport with something like
[daemonsudo](https://github.com/daemonsudo/daemonsudo). The two compose
(transport gate + in-app gate), but expect double knocks if both approve
the same tool.

## Approvals vs Sanctum + Policies

They answer different questions, and this package builds ON the second:

- **Standing authorization** — *may this principal ever do X?* — is
  Sanctum abilities + Gates/Policies, decided by code written in advance.
  Your action's `authorize()` delegates straight to them, and `authorize()`
  always runs first; an approval can never escalate past a policy denial.
- **Per-invocation consent** — *may this specific call, with these exact
  args, happen now?* — is a human decision at call time, single-use,
  expiring. That's the approvals subsystem.

**When NOT to use this package:** if your operations split cleanly into
always-allowed vs never-exposed by role, Sanctum + Policies suffice — and
if you're exposing a handful of read-only tools on a single surface, raw
laravel/mcp is simpler. Value scales with actions × surfaces × danger of
mutations.

## Audit

Every non-readOnly execution — success, failure, denial, or knock — writes
an `agentic_action_log` row: action, surface, user, redacted args, args
hash, status, error, approval id, definition hash, request id, duration.
Opt out per action with `#[AgentAction(..., audit: false)]` or globally
with `agentic.audit.enabled`.

Redaction globs (`agentic.redact`, e.g. `'password'`, `'*.password'`,
`'card.secret'`) apply to both audit rows and approval payloads — secrets
never land in either.

## Schemas

Input DTOs are spatie/laravel-data classes compiled once to JSON Schema
(draft 2020-12) and reused everywhere: the MCP tool schema, the laravel/ai
tool schema, HTTP validation, and CLI argument parsing. Optionally declare
a compact `agentInputSchema` DTO shown to models (token economy) while the
full schema still validates; coherence between the two is linted at
registration. `outputSchema` with `Mismatch::Warn|Strict|Fallback`
(`Fallback` requires an `outputFallback(): mixed` method) governs result
shape.

## Testing your app's actions

```php
use Gtapps\LaravelAgentic\Facades\Agentic;

it('refunds after checkout', function () {
    $fake = Agentic::fake();

    app(CheckoutFlow::class)->cancelAndRefund($order);

    $fake->assertRan('refund-invoice', fn (array $args) => $args['invoiceId'] === $order->invoice_id);
    $fake->assertAudited('refund-invoice');
});

it('never runs actions on validation failure', function () {
    $fake = Agentic::fake();

    // ...

    $fake->assertNothingRan();
});

it('knocks for large refunds', function () {
    $fake = Agentic::fake()->requireApprovalFor('refund-invoice');

    // ...

    $fake->assertApprovalRequested('refund-invoice');
});
```

## Config reference

```php
return [
    'discovery' => ['paths' => [app_path('Actions')]], // scanned for #[AgentAction]
    'http' => [
        'enabled' => true,
        'prefix' => 'agentic',            // POST /agentic/actions/{name}
        'middleware' => ['api'],          // add your guard, e.g. 'auth:sanctum'
    ],
    'mcp' => [
        'tiers' => ['unauthenticated' => []], // allowlist for anonymous callers
        'exclude' => [],                      // hard denylist, beats everything
    ],
    'approvals' => ['ttl' => 600],        // seconds until knock/grant expires to deny
    'redact' => [],                       // dot-path globs, e.g. '*.password'
    'audit' => ['enabled' => true],
];
```

## License

MIT — see [LICENSE](LICENSE).
