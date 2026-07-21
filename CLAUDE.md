# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`gtapps/laravel-agentic` is a Laravel **package** (not an app): an agent-native
action layer. You define an action once, and it becomes callable — with
identical validation, authorization, approval, and audit — across five
surfaces: MCP, laravel/ai tools, HTTP, CLI, and queued jobs. Built on
`laravel/mcp`, `laravel/ai`, and `spatie/laravel-data`.

Namespace is `Gtapps\LaravelAgentic` (PSR-4 root `src/`). Read `README.md`
for the user-facing story and this file for the package architecture and
development conventions.

## Commands

Tests run via Orchestra Testbench on any PHP ≥ 8.3 (composer floor); the suite
is green on both 8.4 and the local default 8.5, so plain `php` works:

```bash
php vendor/bin/pest                    # full suite (~78 tests)
php vendor/bin/pest tests/Kernel/RunnerTest.php   # one file
php vendor/bin/pest --filter "knocks"  # by name substring
vendor/bin/pint                        # format (composer format)
```

There is no build step. `composer test` / `composer format` are the aliases.

## Architecture

### The Runner is the one chokepoint

Every surface funnels into `Runner::run($name, $rawArgs, $context)`
(`src/Kernel/Runner.php`). All guarantees live inside a **fixed, ordered**
pipeline of steps (`src/Kernel/Steps/`), and the order is not configurable:

1. `Resolve` — look up the `ActionDefinition` from the Registry
2. `ValidateAndHydrate` — validate raw args against the full input schema, hydrate the DTO
3. `Authorize` — the action's `authorize()`; standing authz, always first
4. `ApprovalGate` — per-invocation human consent (only for non-`readOnly` + `needsApproval`)
5. `Execute` — call the handler's `handle()`
6. `NormalizeResult` — apply `outputSchema` / `Mismatch` policy

The Runner wraps the whole pipeline so denials, approval knocks, and failures
are audited alongside successes. Adding cross-cutting behavior means adding or
editing a Step — never bypassing the pipeline from a surface.

**Approval is consent, never escalation:** `ApprovalGate` runs *after*
`Authorize`, so an approval can never override a policy denial. The grant is
consumed *before* `Execute`, so a handler failure after consume makes the
retry knock again rather than double-execute.

### Definitions, registry, caching

An action is a class with `#[AgentAction(...)]` (`src/Attributes/`) and a
`handle()` method. The input DTO is inferred as the first `handle()` parameter
typed as a `spatie/laravel-data` `Data` subclass.

`Registry` (`src/Kernel/Registry.php`) builds an immutable, serializable
`ActionDefinition` per action — attribute metadata + compiled JSON Schema +
a `definitionHash`. It resolves actions from two sources: package-registered
classes (`Agentic::register([...])`) first, then classes scanned from
`agentic.discovery.paths` (default `app/Actions`), with **scanned overriding
registered by name**. A broken action logs a warning and is skipped — it
never fatals boot (file class names are lexed via tokens without executing
the file). `agentic:cache` serializes definitions to
`bootstrap/cache/agentic.php` (like `route:cache`); `agentic:clear` removes it.

### Schema spine

Input DTOs compile once to JSON Schema (draft 2020-12) via
`SchemaCompiler` → `SpatieDataCompiler` (`src/Schema/`) and the result is
reused for the MCP tool schema, ai-tool schema, HTTP validation, and CLI
parsing — so schema dialects can't drift between surfaces. An optional
compact `agentInputSchema` is what models see (token economy) while the full
schema still validates; `Registry::lintCoherence()` enforces at registration
that every field the full schema *requires* exists in the compact one.

### Context is built explicitly, never ambient

`ContextFactory::make(Surface $caller, ?Authenticatable $user, ...)`
(`src/Kernel/`) is the only place an `ActionContext` is built. Each surface
passes the identity **it** verified (token user, `--as` user, job's stored
user id). The kernel never reads session/cookie state — this is the deliberate
token/cookie-confusion defense. `Surface` (`src/Enums/`) is one enum serving
both "surfaces an action is exposed on" and "who is calling now".

### Surfaces (`src/Surfaces/`)

Each is a thin adapter that verifies identity, builds a context, and calls the
Runner — no business logic:

- **Mcp** — `AgenticServer` (mount in `routes/ai.php` via `Mcp::web`); tiered
  exposure via `agentic.mcp.tiers` (allowlist for unauthenticated) and
  `exclude` (hard denylist beating everything), gating both `tools/list` and
  `tools/call`.
- **AiTool** — `Agentic::tools()` yields `ActionToolAdapter`s for any laravel/ai agent's `tools()` iterable.
- **Http** — auto-mounted `ActionController` (`routes/agentic.php`, toggle with `agentic.http.enabled`); POST for all, GET allowed for `readOnly`.
- **Cli** — `agentic:action` plus `agentic:list|cache|clear|approve|deny`.
- **Jobs** — `RunAction` dispatchable.

`tests/ParityTest.php` asserts all five surfaces produce identical behavior —
keep it green when touching any surface.

### Approvals & audit (`src/Approvals/`, `src/Audit/`)

Grants are keyed on `sha256(action + canonicalized args)` (arg order never
matters), bound to the requesting principal, single-use, and expire to **deny**
(`agentic.approvals.ttl`). A throwing `needsApproval` predicate fails **closed**.
`ApprovalBroker` mediates; wire your own channel via the `ApprovalRequested`
event (v1 ships no HTTP grant endpoint by design).

`Recorder` writes an `agentic_action_log` row for every audited execution
(success, failure, denial, or knock): non-`readOnly` actions by default,
`readOnly` ones only when they opt in with `#[AgentAction(audit: true)]`.
`Redactor` applies
`agentic.redact` dot-path globs to **both** audit rows and approval payloads,
so secrets never land in either.

### Testing your own actions

`Agentic::fake()` (`src/Testing/AgenticFake.php`) swaps the Runner binding in
the container for a recorder: subsequent runs on any surface are captured, not
executed. Assertions: `assertRan`, `assertNothingRan`, `assertAudited`,
`assertApprovalRequested`, `requireApprovalFor(...)`.

## Test conventions

Tests use Pest + Testbench. `getPackageProviders` (`tests/TestCase.php`)
registers the Data, MCP, and Agentic providers. The `workbench/` app provides
real fixture actions (`workbench/app/Actions/`) discovered via a workbench
provider; `tests/Fixtures/` holds narrower per-test action and schema fixtures.
Helpers in `tests/Pest.php`: `approvalKey($text)` extracts a knock key from
agent-facing text; `useUsersTable()` sets up an auth model + table.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

</laravel-boost-guidelines>
