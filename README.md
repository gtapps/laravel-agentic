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

That single definition is now callable, with the same validation,
authorization, approval, and audit behavior, via:

| Surface | How |
|---|---|
| MCP | `tools/call refund-invoice` on the server above |
| laravel/ai | `Agentic::tools()` inside any agent's `tools()` iterable — `Agentic::tools($only, $user)` pins an explicit principal instead of the ambient guard |
| HTTP | `POST /agentic/actions/refund-invoice` (GET allowed for `readOnly`) — opt-in, off by default (`agentic.http.enabled`) |
| CLI | `php artisan agentic:action refund-invoice '{"invoiceId":42,"amount":99.5}' --as=1` |
| Queue | `RunAction::dispatch('refund-invoice', $args, $userId)` |

`php artisan agentic:list` shows everything registered; `agentic:cache`
compiles the manifest like `route:cache`.

### Caching the manifest

Actions reach the registry two ways: discovery scanning of
`agentic.discovery.paths` (default `app/Actions`), and `Agentic::register([...])`
from a service provider, which is how a package or module contributes actions.

`agentic:cache` compiles both into `bootstrap/cache/agentic.php`. As with
`route:cache` and `config:cache`, **the cached manifest fully replaces both
sources** — once it exists, a later `Agentic::register()` call is ignored. So
re-run `agentic:cache` whenever you add an action or install a package that
registers them; `agentic:clear` (or `optimize:clear`) is the recovery if a stale
manifest is serving a missing or outdated action. It refuses to write an empty
manifest, since that file would otherwise shadow every registration.

`optimize:clear` removes the manifest. `optimize` deliberately does *not* build
it — caching is opt-in, and an app with no actions yet shouldn't fail a deploy.

## The approval flow

`needsApproval` actions execute only with per-invocation human consent:

1. The agent calls the action. It does not execute; the agent receives an
   agent-legible knock: *"Approval required for action 'refund-invoice'.
   Pending under key `abc…`. Ask a human to run: `php artisan
   agentic:approve 01J…`. Then retry this exact call unchanged."*
2. A human runs `agentic:approve <id>` (or `agentic:deny <id>`). The **id**
   is the approval row's ULID — the decision identity. The **key** identifies
   the action+args combination and is shown for correlation only: two
   principals knocking with identical args share a key but hold separate
   approvals, so deciding by key would be ambiguous.
3. The agent retries the identical call — it executes exactly once. The
   grant is consumed; a repeat call knocks again.

On the laravel/ai surface the same consent is collected without asking the
model to retry anything — see [Native approval on the laravel/ai surface](#native-approval-on-the-laravelai-surface).

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

### Native approval on the laravel/ai surface

laravel/ai 0.10 can pause a run for a human, so the ai-tool surface uses that
instead of the retry protocol above: the run stops before the tool executes,
and the model is never asked to reissue anything.

The broker still decides. The knock is raised as the run pauses, before laravel/ai
hands it back to you; `Agentic::approvalDecisions()` then reads the answers a
human gave through any channel — `agentic:approve`, or your own — and hands
laravel/ai the decisions it needs to continue:

```php
$response = $agent->forUser($user)->prompt('Refund invoice 42');

if ($response->hasPendingApprovals()) {
    // Returns null until every paused call has an answer, so poll (or
    // re-enter on your approval event).
    $decisions = Agentic::approvalDecisions($response->pendingApprovals, $user);

    if ($decisions !== null) {
        $response = $agent->continue($response->conversationId, $user)->prompt($decisions);
    }
}
```

Worth knowing:

- **The agent must be conversational.** laravel/ai resumes a paused run from
  stored history, so an agent without `RemembersConversations` throws
  `ApprovalNotResumableException` when a gated tool pauses. Non-conversational
  agents keep the in-band knock from calling the tool directly.
- **All or nothing.** laravel/ai rejects a decision map that leaves any paused
  call unanswered, so `approvalDecisions()` returns `null` until every call it
  owns has one. Tool calls from outside this package are left for you to decide;
  merge yours in.
- **The principal must match the run.** Omitting `$user` falls back to the
  ambient guard, as `Agentic::tools()` does. Pass the same principal to both, or
  the grant is bound to someone other than whoever the tool executes as.
- **Waiting has a deadline.** An unanswered knock expires at
  `agentic.approvals.ttl` and comes back as a rejection, so polling terminates.
- **Editing arguments re-knocks.** Consent is bound to exact arguments, so a
  resume that rewrites them with `Decision::edit()` is a new call and asks
  again rather than riding the existing grant.
- **Sibling calls stay separate.** A model that emits the same tool twice with
  identical arguments gets two approvals; releasing one does not release the
  other.

### Wiring approvals to your own channels

v1 ships no HTTP grant/deny endpoints. The `ApprovalRequested` event
carries the pending approval and a single-use capability token; wire any
channel you like and call the broker:

```php
// routes/web.php — POST only. Never grant over GET: chat-app link
// preview prefetchers will auto-approve your links.
Route::post('/approvals/{id}', function (string $id, Request $request) {
    $granted = app(ApprovalBroker::class)->decide(
        $id,                        // $approval->id from ApprovalRequested
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
with `agentic.audit.enabled`. `readOnly` actions are excluded by default —
opt one in with `#[AgentAction(..., audit: true)]`.

**The boundary, plainly:** audit covers calls where an action definition
resolved — validation failures, `authorize()` denials, approval knocks,
handler failures, and successes. It does **not** cover calls that never
reach that point: transport/middleware rejections, controller-level
rejections, unknown actions, or actions hidden from that surface. If you
need a record of rejected attempts, add it at your app's transport layer.

**Failure semantics:** the audit write happens *after* the handler runs,
and is synchronous and exception-propagating. If the audit write itself
fails, the action has already executed — the caller sees an error, but
side effects already happened. There is no transactional fail-closed
guarantee across the handler and the audit row.

**`authorize()` is the standing gate, not exposure:** an action with no
`authorize()` method is allowed by `Authorize` on every surface it's
exposed to. Closing the HTTP surface (`agentic.http.enabled`, off by
default) removes the only anonymous, auto-mounted vector — but if you
mount MCP, any *authenticated* caller can still invoke a no-`authorize()`
action. Write `authorize()` on every action that isn't meant to be
universally callable.

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

### Array properties

Object arrays use `#[DataCollectionOf(AddressData::class)]`. Scalar arrays are
declared with a docblock — `@var int[]`, `@var list<string>`, `@var array<int,
bool>`; `T` may be `int`, `string`, `float`, or `bool`. Item types are enforced
on every surface, and elements arriving as strings (CLI arguments, HTTP query
strings) are coerced to the declared type, so `?ids[]=1&ids[]=2` reaches an
`int[]` handler as ints.

Enum items (`@var Suit[]`), nested arrays (`@var int[][]`), and string-keyed
maps (`@var array<string, bool>` — a JSON object, not an array) are not
supported in v1 and fail at registration rather than compiling to a wrong shape.

One sharp edge worth knowing: a property with a default is still `required` to
Laravel, and `required` treats `[]` and `''` as empty. So `{"ids": []}` is
rejected even though the schema marks `ids` optional with `"default": []` —
omitting the key is the way to mean "none". Add `#[Present]` to the property to
accept an explicit empty array:

```php
class ListInvoicesInput extends Data
{
    public function __construct(
        #[Present]
        /** @var int[] */
        public array $ids = [],
    ) {}
}
```

## Coming from laravel/mcp

If you're porting an existing `laravel/mcp` tool server, the shapes map
directly:

| `laravel/mcp` | `laravel-agentic` |
|---|---|
| `Tool::handle()` | action `handle()` |
| `Tool::schema()` | spatie/laravel-data `Data` input DTO |
| `tokenCan('x')` in the tool | `authorize()` calling `tokenCan('x')` |
| transport middleware on the route | server middleware where `AgenticServer` is mounted |
| idempotency wrapper in the tool | keep it in `handle()` |

Three things that are easy to get wrong on the way over:

**Authorization is opt-in per action, not implicit.** An action with no
`authorize()` method is allowed for any caller a surface already
authenticated — the same as a Laravel route with no policy check; see
["`authorize()` is the standing gate, not exposure"](#audit) for the full
implications and [Approvals vs Sanctum + Policies](#approvals-vs-sanctum-policies)
for how it composes with the approval flow. Define `authorize()` on every
mutating action you port.

**Reuse your `FormRequest` rules — don't re-derive them.** Input DTOs
validate through spatie/laravel-data's normal pipeline, which merges a
static `rules(): array` on top of the rules inferred from types and
attributes. A `FormRequest::rules()` array can be pasted in as-is — for the
`RefundInvoiceInput` from the Quickstart above:

```php
public static function rules(): array
{
    return [
        'invoiceId' => ['required', 'integer', 'exists:invoices,id'],
        'amount' => ['required', 'numeric', 'min:0.01'],
    ];
}
```

These rules run identically on all five surfaces. One caveat: rules that
only exist in `rules()` (closures, `exists:`, conditional `sometimes`) are
enforced but aren't visible in the JSON Schema shown to agents — express
structural constraints (types, required-ness) via properties and
attributes, and keep `rules()` for business rules a schema can't capture.

**`agentic:cache` only sees actions your provider registered during the
cache run.** Like `route:cache`, the compiled manifest is a snapshot: if
the provider calling `Agentic::register(...)` is deferred or conditionally
booted, its actions won't be in the manifest until that provider runs
during `agentic:cache`. Prefer `agentic.discovery.paths` for actions you
want cached unconditionally, or make the registering provider eager.

Generators, a `FormRequest`-to-DTO codegen command, and other
boilerplate-reduction tooling for this migration path are tracked
separately and aren't part of this package.

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
        'enabled' => false,               // opt-in; set true and add your guard first
        'prefix' => 'agentic',            // POST /agentic/actions/{name}
        'middleware' => ['api'],          // add your guard, e.g. 'auth:sanctum'
    ],
    'mcp' => [
        'tiers' => ['unauthenticated' => []], // allowlist for anonymous callers
        'exclude' => [],                      // hard denylist, beats everything
    ],
    'approvals' => [
        'ttl' => 600,                      // seconds until knock/grant expires to deny
        'connection' => null,              // null = default connection
    ],
    'redact' => [],                       // dot-path globs, e.g. '*.password'
    'audit' => [
        'enabled' => true,
        'connection' => null,              // null = default connection
    ],
];
```

## License

MIT — see [LICENSE](LICENSE).
