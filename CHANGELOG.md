# Changelog

All notable changes to `gtapps/laravel-agentic` are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [0.0.6] - 2026-07-22

### Fixed

- **mcp: empty input schemas now encode as a JSON object** — `ActionTool::toArray()` previously let PHP serialize an action's empty `properties` map as `[]`; strict MCP clients that require an object there rejected the schema. `properties` is now coerced to a `stdClass` so it always encodes as `{}`.

### Upgrading

No config or migration changes required.

## [0.0.5] - 2026-07-22

### Fixed

- **schema: compiled keys now honor spatie input name mappers** — `SpatieDataCompiler` previously emitted the raw PHP property name as both the schema key and the `required` entry, ignoring a DTO's `#[MapInputName]` attribute or the global `data.name_mapping_strategy.input`. Advertised schema keys now match what `validateAndCreate()` actually accepts, including for nested `Data` children.

### Upgrading

No config or migration changes required.

## [0.0.4] - 2026-07-22

### Added

- **`Gtapps\LaravelAgentic\Pagination\PaginatedInput`** — a base input DTO for listing actions, compiling `page`/`perPage` into the schema. `NormalizeResult` now recognizes a paginated result (an Illuminate paginator, or a spatie `PaginatedDataCollection`/`CursorPaginatedDataCollection`) of `outputSchema` items and normalizes it to spatie/laravel-data's own `{data, links, meta}` envelope, with the paginator path pinned to `/` so links are deterministic across every surface. A **raw** paginator of Eloquent models or plain arrays is hydrated into the `outputSchema` type (via spatie's `collect()`); items that can't be shaped into it fall through to the `outputMismatch` policy.
- **`agentic:make-action {name} {--paginated} {--force}`** — scaffolds a blank `#[AgentAction]` class and its paired input DTO (extending `PaginatedInput` with `--paginated`), following the same `make:*` generator conventions as `make:model`/`make:controller`.

### Changed

- **Behavior note:** an action with `outputSchema` whose handler already returned a matching paginator previously fell through to `Mismatch::Warn` (a logged warning, raw paginator passed through). It now gets the pagination envelope instead. No shipped action relied on the old behavior.

### Upgrading

No config or migration changes required.

## [0.0.3] - 2026-07-21

### Changed

- **BREAKING: `ApprovalBroker::check()` returns `?Approval` instead of `CheckResult`** — the consumed approval is now returned so the audit row for the execution can link to it; the `Gtapps\LaravelAgentic\Approvals\CheckResult` enum is deleted. Callers only ever compared against `CheckResult::Granted`, so `$broker->check(...) !== null` is the direct replacement.
- **BREAKING: approvals are decided by approval id, not by key** — `agentic:approve` / `agentic:deny` now take the approval's ULID (`agentic:approve 01J…`), and `ApprovalBroker::decide()` / `decideViaArtisan()` take that id as their first argument. Deciding by key was ambiguous: two principals knocking with identical args share one key, so `agentic:approve <key>` could settle the wrong principal's approval. The key is still shown in the knock (and returned as `key` in the HTTP 409 body) for correlation; the new `approvalId` field carries the decision identity.

- **BREAKING: `Gtapps\LaravelAgentic\Contracts\ActionContext` gained `idempotencyKey(): ?string`** — the caller's own identifier for one invocation, which is what binds an approval to a single tool call. The shipped `Context` implements it; a custom implementation of the contract must add it, returning `null` if the surface has no such id.

- **BREAKING: HTTP surface is now opt-in** — `agentic.http.enabled` defaults to `false`. Previously the HTTP surface auto-mounted with no authentication (the `api` middleware group does not authenticate), making any action without an `authorize()` method anonymously reachable. Set `agentic.http.enabled => true` and add your auth middleware (e.g. `auth:sanctum`) to restore it.

### Added

- **native tool approval on the laravel/ai surface** — requires `laravel/ai ^0.10` (the constraint moved from `~0.9.1` to `~0.10.0`). A gated tool now pauses the run through laravel/ai's own approval mechanism instead of returning an in-band knock and relying on the model to reissue the identical call. `Agentic::approvalDecisions($response->pendingApprovals, $user)` reads the human's answer from the broker and returns the decisions needed to resume; it returns `null` until every paused call it owns has one, because laravel/ai rejects a partial decision map. The principal falls back to the ambient guard's user when omitted, exactly as `Agentic::tools()` does — the knock and the execution that later rides it have to agree on who is asking. An unanswered knock expires to a rejection at `agentic.approvals.ttl`, so a paused run resumes rather than polling forever. Requires a conversational agent — laravel/ai resumes from stored history and throws `ApprovalNotResumableException` otherwise. Calling a tool directly still knocks in band, as the other four surfaces do.
- **approvals are now per invocation, not per (action, args)** — a new `invocation_key` column records `sha256(surface|principal|tool-call id)` for callers that have one. A model emitting the same tool twice with identical arguments previously collapsed into a single pending row, so one approval released both calls; each call now holds its own. The four surfaces without a tool-call id are unaffected and keep their previous hashes exactly. `args_hash` remains the authoritative consumption key, so a resume that rewrites arguments with `Decision::edit()` misses the grant and knocks again rather than executing on consent given for different arguments.
- **`agentic_action_log.idempotency_key`** — the caller's own identifier for an invocation (laravel/ai's tool-call id today), recorded alongside `request_id`, which is minted per context and so differs between a paused call and its resume. Null on surfaces without one.
- **`agentic.audit.connection` / `agentic.approvals.connection`** — configure the database connection audit rows and approval rows are written to, independent of the app's default connection (`null` = current behavior).
- **`agentic:list` reports effective exposure, not just declared** — a new `Audit` column folds the per-action policy together with the global `agentic.audit.enabled` switch, and the `Surfaces` column renders `http (off)` while `agentic.http.enabled` is false, so a listed surface always means a reachable one.
- **`AgenticFake::assertNotAudited()`** — the inverse of `assertAudited()`, for pinning that an action's definition resolves to not-audited.

### Fixed

- **approvals: one pending row per (key, principal), enforced by the database** — a new nullable-unique `active_key` column (non-null only while pending) makes duplicate concurrent knocks collide at the index instead of racing to create two pending rows. Settling is now a single conditional `UPDATE` guarded on id + status + expiry, so a second decision on an already-settled or expired row is a no-op and fires no duplicate event.
- **audit rows now link to the approval that authorized them** — a successful run behind an approval recorded `approval_id = null`; only the knock row carried the id. The audit trail now answers "who approved this" for the execution itself, as the README promises.

### Upgrading

- **`laravel/ai ^0.10` is now required** (was `~0.9.1`). No source changes were needed for the bump itself; the `Tool` contract is unchanged between the two.
- **Both create migrations were edited in place** — `agentic_approvals` gained `active_key` and `invocation_key`, `agentic_action_log` gained `idempotency_key`. They are edited rather than added as follow-up migrations, so if you already ran the 0.0.2 migrations, roll both tables back and re-migrate.
- Any code calling `ApprovalBroker::decide()` / `decideViaArtisan()`, or wiring an approval channel off the `ApprovalRequested` event, must pass `$approval->id` where it previously passed the args hash. Passing a key now finds nothing and returns `false`.
- **If you already published `config/agentic.php`, this fix does not reach you.** The published file still carries `'enabled' => true`, and it wins over the package default — your HTTP surface stays mounted and unauthenticated after upgrading. Open the file and set it to `false`, or add auth middleware, before assuming you are covered.
- If you rely on the HTTP surface, set `agentic.http.enabled => true` in your published config and ensure auth middleware is configured before re-enabling.
- The audit boundary and failure semantics are now documented precisely in the README `## Audit` section — audit is synchronous and exception-propagating, written after the handler runs; it is not a transactional fail-closed guarantee.

## [0.0.2] - 2026-07-21

### Fixed

- **audit: readOnly actions can opt into audit** — `#[AgentAction(audit: true)]` on a `readOnly` action now enables logging; previously the recorder always skipped `readOnly` actions regardless of the flag.

### Upgrading

No config or migration changes required.

**Note:** `readOnly` actions explicitly marked `#[AgentAction(audit: true)]` that were previously silently excluded from the audit log will now be audited as intended.
