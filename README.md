# laravel-agentic

<p align="center">
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue.svg" alt="MIT License" /></a>
  <a href="https://github.com/gtapps/laravel-agentic/actions/workflows/tests.yml"><img src="https://github.com/gtapps/laravel-agentic/actions/workflows/tests.yml/badge.svg" alt="Tests" /></a>
  <a href="CHANGELOG.md"><img src="https://img.shields.io/badge/version-0.0.7-green.svg" alt="Version 0.0.7" /></a>
  <img src="https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/gtapps/laravel-agentic/_gh_traffic_stats/.github/badges/clones.json" alt="Downloads" />
  <img src="https://img.shields.io/badge/PRs-welcome-brightgreen.svg" alt="PRs Welcome" />
</p>

**An agent-native action layer for Laravel. Define an action once; expose it
to humans and agents everywhere.**

Ship your agentic Laravel app faster: authorization, human approval, and an
audit trail come built in, and behave the same on every surface.

```
WITHOUT: raw laravel/mcp + laravel/ai
─────────────────────────────────────
dev writes "refund invoice"  ─┬─> MCP Tool class
        (× 4, by hand)        ├─> laravel/ai tool class
                              ├─> Controller+FormRequest
                              └─> Artisan command

agent refunds over MCP ──> tool executes IMMEDIATELY ──> no knock, no row
                                                         "who approved that on Tuesday?" is answerable
                                                         only from whatever you logged by hand

WITH laravel-agentic
────────────────────
dev writes ONE action class + input DTO      (schema, authorize(), needsApproval, defined once)
                        │
  MCP · ai-tool · HTTP · CLI · job  ────>  one Runner pipeline (nothing per-surface to drift)
                        │
agent decides to refund ──> validate ──> policy check ──> KNOCK: human approves
                        ──> grant consumed (single-use, this exact args hash, expires if ignored)
                        ──> execute ──> audit row: who called, via which surface, who approved
```

Three things you get once, for every surface at once:

- **One definition, the surfaces you choose.** Schema compiled once and served
  to MCP, ai-tool, HTTP, CLI, and queue: same validation, same `authorize()`,
  same gate, no per-surface copy to drift.
- **Consent that outlives the run.** A grant is durable, single-use, bound to
  the principal _and_ the exact arguments, expires to deny, and is answered
  out of band (artisan, Slack, your own endpoint) by someone who isn't the
  caller. On the ai-tool surface it rides laravel/ai's own approval pause
  rather than replacing it; the other four surfaces have no such hook to ride.
- **One row per attempt.** Success, failure, denial, or knock: action,
  surface, principal, args hash, who approved.

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

Requires PHP 8.3+ and Laravel 13. Built on laravel/mcp, laravel/ai, and
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
    surfaces: [Surface::Mcp, Surface::AiTool, Surface::Http, Surface::Cli, Surface::Job], // define the ones you want, omit for all
)]
class RefundInvoice
{
    public function authorize(ActionContext $ctx, RefundInvoiceInput $input): bool
    {
        return $ctx->user()->can('refund', Invoice::find($input->invoiceId));
    }

    public function handle(RefundInvoiceInput $input, ActionContext $ctx): RefundResult
    {
        // business logic; $ctx->caller() ∈ {mcp, ai-tool, http, cli, job}
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
authorization, approval, and audit behavior (`tests/ParityTest.php` holds all
five surfaces to it), via:

| Surface    | How                                                                                                                                              |
| ---------- | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| MCP        | `tools/call refund-invoice` on the server above                                                                                                  |
| laravel/ai | `Agentic::tools()` inside any agent's `tools()` iterable; `Agentic::tools($only, $user)` pins an explicit principal instead of the ambient guard |
| HTTP       | `POST /agentic/actions/refund-invoice` (GET allowed for `readOnly`); opt-in, off by default (`agentic.http.enabled`)                             |
| CLI        | `php artisan agentic:action refund-invoice '{"invoiceId":42,"amount":99.5}' --as=1`                                                              |
| Queue      | `RunAction::dispatch('refund-invoice', $args, $userId)`                                                                                          |

`php artisan agentic:list` shows everything registered; `agentic:cache`
compiles the manifest like `route:cache`.

### Choosing your surfaces

`surfaces:` defaults to all five. Narrow it per action and the rest stop
existing there:

```php
#[AgentAction(name: 'refund-invoice', surfaces: [Surface::Mcp, Surface::Cli])]
```

That's enforced, not cosmetic: `Resolve`, the pipeline's first step, rejects
any call whose caller surface isn't on the list, so an action you didn't
expose to HTTP is unreachable over HTTP even by someone who knows its name.
Deployment keeps the final say: HTTP stays off until `agentic.http.enabled`,
`agentic.mcp.exclude` hard-denies a name on MCP, and
`agentic.mcp.tiers.unauthenticated` is the allowlist guests get.
`agentic:list` prints exposure as it actually is: an action declaring `http`
with no routes mounted shows `http (off)`.

### Caching the manifest

Actions reach the registry two ways: discovery scanning of
`agentic.discovery.paths` (default `app/Actions`), and `Agentic::register([...])`
from a service provider, which is how a package or module contributes actions.

`agentic:cache` compiles both into `bootstrap/cache/agentic.php`. As with
`route:cache` and `config:cache`, **the cached manifest fully replaces both
sources**: once it exists, a later `Agentic::register()` call is ignored. So
re-run `agentic:cache` whenever you add an action or install a package that
registers them; `agentic:clear` (or `optimize:clear`) is the recovery if a stale
manifest is serving a missing or outdated action. It refuses to write an empty
manifest, since that file would otherwise shadow every registration.

`optimize:clear` removes the manifest. `optimize` deliberately does _not_ build
it: caching is opt-in, and an app with no actions yet shouldn't fail a deploy.

## The approval flow

`needsApproval` actions execute only with per-invocation human consent:

1. The agent calls the action. It does not execute; the agent receives an
   agent-legible knock: _"Approval required for action 'refund-invoice'.
   Pending under key `abc…`. Ask a human to run: `php artisan
agentic:approve 01J…`. Then retry this exact call unchanged."_
2. A human runs `agentic:approve <id>` (or `agentic:deny <id>`). The **id**
   is the approval row's ULID, the decision identity. The **key** identifies
   the action+args combination and is shown for correlation only: two
   principals knocking with identical args share a key but hold separate
   approvals, so deciding by key would be ambiguous.
3. The agent retries the identical call, and it executes exactly once. The
   grant is consumed; a repeat call knocks again.

On the laravel/ai surface the same consent is collected without asking the
model to retry anything; see [Native approval on the laravel/ai surface](#native-approval-on-the-laravelai-surface).

Semantics you can rely on:

- Grants are keyed on `sha256(action + canonicalized args)`: different
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
human gave through any channel (`agentic:approve`, or your own) and hands
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

This package ships no HTTP grant/deny endpoints. The `ApprovalRequested` event
carries the pending approval and a single-use capability token; wire any
channel you like and call the broker:

```php
// routes/web.php, POST only. Never grant over GET: chat-app link
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
unrestricted shell on the app server cannot be bound by in-app approvals**.
Gate that layer at the transport with something like
[daemonsudo](https://github.com/daemonsudo/daemonsudo). The two compose
(transport gate + in-app gate), but expect double knocks if both approve
the same tool.

## Approvals vs Sanctum + Policies

They answer different questions, and this package builds ON the second:

- **Standing authorization** (_may this principal ever do X?_) is
  Sanctum abilities + Gates/Policies, decided by code written in advance.
  Your action's `authorize()` delegates straight to them, and `authorize()`
  always runs first; an approval can never escalate past a policy denial.
- **Per-invocation consent** (_may this specific call, with these exact
  args, happen now?_) is a human decision at call time, single-use,
  expiring. That's the approvals subsystem.

**When NOT to use this package:** if your operations split cleanly into
always-allowed vs never-exposed by role, Sanctum + Policies suffice; if
you're exposing a handful of read-only tools on a single surface, raw
laravel/mcp is simpler; and if one conversational agent is your only surface,
[its native pause](#native-approval-on-the-laravelai-surface) already covers
you. Value scales with actions × surfaces × danger of mutations.

## Audit

Every non-readOnly execution (success, failure, denial, or knock) writes
an `agentic_action_log` row: action, surface, user, redacted args, args
hash, status, error, approval id, definition hash, request id, duration.
Opt out per action with `#[AgentAction(..., audit: false)]` or globally
with `agentic.audit.enabled`. `readOnly` actions are excluded by default;
opt one in with `#[AgentAction(..., audit: true)]`.

**The boundary, plainly:** audit covers calls where an action definition
resolved: validation failures, `authorize()` denials, approval knocks,
handler failures, and successes. It does **not** cover calls that never
reach that point: transport/middleware rejections, controller-level
rejections, unknown actions, or actions hidden from that surface. If you
need a record of rejected attempts, add it at your app's transport layer.

**Failure semantics:** the audit write happens _after_ the handler runs,
and is synchronous and exception-propagating. If the audit write itself
fails, the action has already executed: the caller sees an error, but
side effects already happened. There is no transactional fail-closed
guarantee across the handler and the audit row.

**`authorize()` is the standing gate, not exposure:** an action with no
`authorize()` method is allowed by `Authorize` on every surface it's
exposed to. Closing the HTTP surface (`agentic.http.enabled`, off by
default) removes the only anonymous, auto-mounted vector, but if you
mount MCP, any _authenticated_ caller can still invoke a no-`authorize()`
action. Write `authorize()` on every action that isn't meant to be
universally callable.

Redaction globs (`agentic.redact`, e.g. `'password'`, `'*.password'`,
`'card.secret'`) apply to the **arguments** in both audit rows and approval
payloads, so a matched path lands in neither. Two things to know: the list is
empty by default (nothing is redacted until you name it), and it doesn't
cover the `error` column, which stores the exception message as thrown.

## Per-surface authorization

An action may gate a surface on its own terms by defining
`authorize{Surface}()` — `authorizeMcp`, `authorizeAiTool`, `authorizeHttp`,
`authorizeCli`, `authorizeJob` — alongside or instead of `authorize()`. The
shape is Laravel's notification channels: `surfaces:` declares where the action
is reachable the way `via()` does, and each method owns one channel.

```php
#[AgentAction(name: 'refund-invoice', surfaces: [Surface::Mcp, Surface::Cli])]
class RefundInvoice
{
    public function authorize(ActionContext $ctx, RefundInvoiceInput $input): bool
    {
        return Gate::forUser($ctx->user())->allows('refund', $input->invoiceId);
    }

    public function authorizeCli(): bool
    {
        return true;   // local-trust surface: the process boundary is the auth line
    }
}
```

Three rules:

- **The surface's own method wins.** It _replaces_ `authorize()` for that
  surface rather than running on top of it, so a surface gate can widen access,
  not only narrow it. That is the point — `authorizeCli(): true` above drops the
  gate for CLI while MCP still runs the policy.
- **A surface no method names is ungated**, exactly like an action with no
  `authorize()` at all (see "`authorize()` is the standing gate, not exposure"
  above). An action defining only `authorizeMcp()` is open on every other
  surface in its `surfaces:` list — and `surfaces:` defaults to all five. Narrow
  `surfaces:`, or add a generic `authorize()`, whenever that isn't what you
  want. `agentic:list`'s **Gate** column shows what each action actually gates.
- **Surface methods receive the same bindings** as `authorize()` —
  `ActionContext` and the input DTO by type, in any order, with method-injection
  DI for the rest. Inherited and trait-provided methods count, and because PHP
  method lookup is case-insensitive, `authorizeMCP()` gates MCP too.

`$ctx->caller()` is stamped by the adapter that verified identity, never by the
payload, so no MCP or HTTP caller can present itself as CLI. But note what a CLI
gate actually asserts: `--as` is local-trust impersonation, selecting a subject
without establishing who selected it.

> **The dangerous shape:** `authorizeMcp(): true` on an action listed in
> `agentic.mcp.tiers.unauthenticated` drops the gate for **guests**, and
> `$ctx->user()` is `null` inside it. Gate on abilities rather than the channel
> whenever the rule is really about the principal.

### Denials that say why

Authorization methods may return `bool` or an
`Illuminate\Auth\Access\Response`, the same contract Laravel policies already
use — so `Gate::inspect()` can be returned straight through:

```php
public function authorizeMcp(RefundInvoiceInput $input): Response
{
    return $input->amount <= 10_000
        ? Response::allow()
        : Response::deny('Refunds over $10,000 must be run by a human via CLI.');
}
```

The message reaches the caller verbatim on every surface, and HTTP answers with
the response's status instead of a blanket 403. `Response::denyAsNotFound()` is
the one exception: the caller gets the unknown-action wording byte-identical to
a genuine miss, because a reason returned there would disclose exactly what the
404 conceals. Either way the policy's reason is recorded in the audit row.

Two things follow from "verbatim". The message is written for a model that will
read it, so say what to do differently, not what your schema is called. And it
lands **unredacted** in the audit `error` column — `agentic.redact` covers
arguments, not exception messages.

Only authorization varies by surface. Behavior does not: there is no
`handleMcp()`, and `tests/ParityTest.php` asserts all five surfaces produce
identical results from one definition.

### Denied jobs

A denial thrown inside a queued action propagates, so the worker retries it per
the job's policy. If a denial should be terminal for your app, that's a queue
decision Laravel already owns — add
`Illuminate\Queue\Middleware\FailOnException` to the job's middleware rather
than expecting the package to decide retry policy for you.

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
declared with a docblock: `@var int[]`, `@var list<string>`, `@var array<int,
bool>`; `T` may be `int`, `string`, `float`, or `bool`. Item types are enforced
on every surface, and elements arriving as strings (CLI arguments, HTTP query
strings) are coerced to the declared type, so `?ids[]=1&ids[]=2` reaches an
`int[]` handler as ints.

Enum items (`@var Suit[]`), nested arrays (`@var int[][]`), and string-keyed
maps (`@var array<string, bool>`, a JSON object rather than an array) are not
supported yet and fail at registration rather than compiling to a wrong shape.

One sharp edge worth knowing: a property with a default is still `required` to
Laravel, and `required` treats `[]` and `''` as empty. So `{"ids": []}` is
rejected even though the schema marks `ids` optional with `"default": []`;
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

### Listing actions & pagination

Extend `Gtapps\LaravelAgentic\Pagination\PaginatedInput` for a listing
action's input: it compiles `page` (default `1`) and `perPage` (default
`15`, max `100`) into the schema for you. Out-of-range `perPage` is
**rejected** with a validation error, not clamped to the max; see
["Validation rejects; it doesn't clamp"](#reusing-formrequest-validation-during-migration)
if you're porting a tolerant legacy endpoint:

```php
class ListInvoicesInput extends PaginatedInput
{
    // add your own filters via a constructor; page/perPage still apply
}
```

Wire names are `page`/`perPage` (e.g. an HTTP GET reads `?page=2&perPage=50`).
Return an Illuminate paginator (or a spatie `PaginatedDataCollection`) of
`outputSchema` items from `handle()`:

```php
public function handle(ListInvoicesInput $input): mixed
{
    return InvoiceSummary::collect(
        Invoice::query()->paginate($input->perPage, page: $input->page)
    );
}
```

You don't have to pre-wrap with `::collect()`: returning a **raw** Illuminate
paginator of Eloquent models or plain arrays (e.g. `Invoice::paginate(...)`)
works too: its items are hydrated into the declared `outputSchema` type for
you. Items that can't be shaped into that type fall through to the action's
`outputMismatch` policy (a raw paginator is passed through under `Warn`,
throws under `Strict`).

The result is normalized into spatie/laravel-data's own pagination
envelope, the same `{data, links, meta}` shape `PaginatedDataCollection`
already produces, with the paginator's path pinned to `/` so link URLs
(`first_page_url`, `next_page_url`, ...) are deterministic across every
surface (MCP, ai-tool, HTTP, CLI, job) instead of reflecting whichever
surface happened to run. Simple (`simplePaginate()`) and cursor
pagination are both supported the same way.

Those link URLs are **indicative**, not endpoints to dereference: they are
pinned to `/` and carry only `page` (not `perPage` or your filters). Paginate
by re-calling the action with structured `page`/`perPage` arguments (the one
input every surface accepts) rather than following the URLs.

### Scaffolding a new action

`agentic:make-action` generates a blank action class and its paired input DTO,
following the same conventions as `make:model`/`make:controller`:

```bash
php artisan agentic:make-action RefundInvoice
php artisan agentic:make-action Invoices/ListInvoices --paginated
```

`--paginated` generates an input extending `PaginatedInput` and a `handle()`
returning a paginator. `--force` overwrites existing files. This is a blank
scaffold only: it does not introspect an existing route or `laravel/mcp`
tool; fill in the generated `description`, validation, `authorize()`, and
`handle()` body yourself.

### Reusing FormRequest validation during migration

Porting an existing route into an action doesn't require re-expressing a
FormRequest's rules as spatie attributes. spatie/laravel-data calls a
static `rules()` method on the Data class if one exists, merging it into
validation:

```php
class StoreInvoiceInput extends Data
{
    public function __construct(public int $invoiceId, public float $amount) {}

    /** Reuse the REST rules as the single source of truth during migration. */
    public static function rules(): array
    {
        return (new StoreInvoiceRequest)->rules();
    }
}
```

Two caveats: rules added this way run during validation but do **not**
appear in the compiled JSON Schema the model sees, and a FormRequest that
touches `$this->route()` or `$this->user()` won't port; extract those
rules first.

#### Validation rejects; it doesn't clamp

A common manual pagination clamp:

```php
public function perPage(int $default = 100, int $max = 250): int
{
    return max(1, min($max, $this->integer('per_page', $default)));
}
```

translates naturally to a `#[Max(250)]` attribute on the input DTO, but
that's a behavior change, not a like-for-like port. Validation attributes
**reject** out-of-range input with a `ValidationException` (a field error
surfaced on every surface); they don't silently coerce it to the bound. A
caller passing `per_page=9999` gets a structured error back, not a
silently-substituted `250`.

This is the deliberate choice for an agent-facing action (see the audited
["validation failures" outcome](#audit)): the agent gets clear, actionable
feedback instead of a result it didn't ask for. If you need the legacy
tolerant behavior, drop the attribute and clamp inside `handle()` instead:

```php
public function handle(ListInvoicesInput $input): mixed
{
    $perPage = max(1, min(250, $input->perPage));
    // ...
}
```

The tradeoff: a bound enforced only in `handle()` is invisible to the JSON
Schema the agent sees, the same caveat that already applies to
`rules()`-only constraints above.

## Coming from laravel/mcp

If you're porting an existing `laravel/mcp` tool server, the shapes map
directly:

| `laravel/mcp`                     | `laravel-agentic`                                  |
| --------------------------------- | -------------------------------------------------- |
| `Tool::handle()`                  | action `handle()`                                  |
| `Tool::schema()`                  | spatie/laravel-data `Data` input DTO               |
| `tokenCan('x')` in the tool       | `authorize()` calling `tokenCan('x')`              |
| transport middleware on the route | server middleware where `AgenticServer` is mounted |
| idempotency wrapper in the tool   | keep it in `handle()`                              |

Three things that are easy to get wrong on the way over:

**Authorization is opt-in per action, not implicit.** An action with no
`authorize()` method is allowed for any caller a surface already
authenticated, the same as a Laravel route with no policy check; see
["`authorize()` is the standing gate, not exposure"](#audit) for the full
implications and [Approvals vs Sanctum + Policies](#approvals-vs-sanctum-policies)
for how it composes with the approval flow. Define `authorize()` on every
mutating action you port.

**Reuse your `FormRequest` rules; don't re-derive them.** Input DTOs
validate through spatie/laravel-data's normal pipeline, which merges a
static `rules(): array` on top of the rules inferred from types and
attributes. A `FormRequest::rules()` array can be pasted in as-is. For the
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
enforced but aren't visible in the JSON Schema shown to agents: express
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
        'enabled' => env('AGENTIC_HTTP_ENABLED', false), // opt-in; set true and add your guard first
        'prefix' => 'agentic',            // POST /agentic/actions/{name}
        'middleware' => ['api'],          // add your guard, e.g. 'auth:sanctum'
    ],
    'mcp' => [
        'tiers' => ['unauthenticated' => []], // allowlist for anonymous callers
        'exclude' => [],                      // hard denylist, beats everything
    ],
    'approvals' => [
        'ttl' => env('AGENTIC_APPROVALS_TTL', 600),      // seconds until knock/grant expires to deny
        'connection' => env('AGENTIC_APPROVALS_CONNECTION'), // null = default connection
    ],
    'redact' => [],                       // dot-path globs, e.g. '*.password'
    'audit' => [
        'enabled' => env('AGENTIC_AUDIT_ENABLED', true),
        'connection' => env('AGENTIC_AUDIT_CONNECTION'), // null = default connection
    ],
];
```

The scalar, per-environment settings read from env vars (defaults shown above):

| Env var                        | Config key             |
| ------------------------------ | ---------------------- |
| `AGENTIC_HTTP_ENABLED`         | `http.enabled`         |
| `AGENTIC_APPROVALS_TTL`        | `approvals.ttl`        |
| `AGENTIC_APPROVALS_CONNECTION` | `approvals.connection` |
| `AGENTIC_AUDIT_ENABLED`        | `audit.enabled`        |
| `AGENTIC_AUDIT_CONNECTION`     | `audit.connection`     |

## License

MIT, see [LICENSE](LICENSE).
