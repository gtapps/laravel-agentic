# laravel-agentic

<p align="center">
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue.svg" alt="MIT License" /></a>
  <a href="https://github.com/gtapps/laravel-agentic/actions/workflows/tests.yml"><img src="https://github.com/gtapps/laravel-agentic/actions/workflows/tests.yml/badge.svg" alt="Tests" /></a>
  <a href="CHANGELOG.md"><img src="https://img.shields.io/badge/version-0.1.0-green.svg" alt="Version 0.1.0" /></a>
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

Actions marked `needsApproval` run only after human approval:

1. The caller receives an approval request instead of executing the action.
2. A human runs `agentic:approve <id>` or `agentic:deny <id>`.
3. The caller retries the same action. An approved call executes once and
   consumes the grant.

Approvals are bound to the requesting principal, canonicalized arguments, and
current action definition. They are single-use and expire to deny after
`agentic.approvals.ttl` (10 minutes by default). The approval key identifies
the action and arguments for correlation; decisions use the approval ULID.

`needsApproval` also accepts a predicate class for conditional consent. If the
predicate throws, Laravel Agentic fails closed and requires approval:

```php
#[AgentAction(..., needsApproval: BigRefundsNeedApproval::class)]
// class BigRefundsNeedApproval {
//     public function __invoke(RefundInvoiceInput $input): bool {
//         return $input->amount > 100;
//     }
// }
```

### Native approval on the laravel/ai surface

Laravel Agentic uses `laravel/ai`'s native approval pause on the ai-tool
surface. The approval broker records the request, and
`Agentic::approvalDecisions()` returns the decisions needed to continue:

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

Laravel Agentic records successful executions, failures, authorization
denials, and approval requests in `agentic_action_log`.

Mutating actions are audited by default. Read-only actions may opt in with
`audit: true`, while any action may opt out with `audit: false`. Auditing can
also be disabled globally with `agentic.audit.enabled`.

Audit begins after an action resolves. Transport rejections, unknown actions,
and actions hidden from a surface must be recorded at the transport layer.

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

Laravel Agentic supports a separate authorization method for each surface:
`authorizeMcp()`, `authorizeAiTool()`, `authorizeHttp()`, `authorizeCli()`,
and `authorizeJob()`.

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

The surface-specific method replaces `authorize()` for that surface. When no
surface-specific method exists, Laravel Agentic uses `authorize()` if present;
otherwise the surface is ungated. All authorization methods receive the same
input, context, and container-injected dependencies.

`agentic:list` reports the effective gate for each action. Its **Gate** column
shows `all` or `none` when exposed surfaces agree, `open: cli` for an ungated
surface, and `broken: cli` for a gate that cannot be called. Gates outside an
action's `surfaces:` list are ignored. A `?` means the handler could not be
loaded, which may indicate a stale manifest; rebuild it with `agentic:cache`.

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

`denyAsNotFound()` conceals the **denial**, not the action's existence. The
pipeline validates before it authorizes, so a caller that sends invalid
arguments to a concealed action still gets the 422 field errors an exposed
action returns, and over HTTP a `GET` of a concealed non-`readOnly` action still
answers 405 where an unknown name answers 404. If a caller must not learn that
the action exists at all, drop the surface from `surfaces:` (or, on MCP, use
`agentic.mcp.exclude` / the unauthenticated tier) — those gate `Resolve`, which
runs first.

The throwing half of the same contract works too: an `AuthorizationException`
raised inside the gate — what `Gate::authorize()` does on a denial — carries a
response, and the step reads it exactly as a returned one. So the trail records
`denied`, not `error`.

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

Pagination results use spatie/laravel-data's `{data, links, meta}` envelope on
every surface. Standard, simple, and cursor pagination are supported.

Pagination links are indicative rather than callable endpoints. Request another
page by calling the action again with `page` and `perPage`.

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
