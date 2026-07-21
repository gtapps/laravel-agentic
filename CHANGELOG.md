# Changelog

All notable changes to `gtapps/laravel-agentic` are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed

- **BREAKING: approvals are decided by approval id, not by key** — `agentic:approve` / `agentic:deny` now take the approval's ULID (`agentic:approve 01J…`), and `ApprovalBroker::decide()` / `decideViaArtisan()` take that id as their first argument. Deciding by key was ambiguous: two principals knocking with identical args share one key, so `agentic:approve <key>` could settle the wrong principal's approval. The key is still shown in the knock (and returned as `key` in the HTTP 409 body) for correlation; the new `approvalId` field carries the decision identity.

- **BREAKING: HTTP surface is now opt-in** — `agentic.http.enabled` defaults to `false`. Previously the HTTP surface auto-mounted with no authentication (the `api` middleware group does not authenticate), making any action without an `authorize()` method anonymously reachable. Set `agentic.http.enabled => true` and add your auth middleware (e.g. `auth:sanctum`) to restore it.

### Added

- **`agentic.audit.connection` / `agentic.approvals.connection`** — configure the database connection audit rows and approval rows are written to, independent of the app's default connection (`null` = current behavior).
- **`agentic:list` reports effective exposure, not just declared** — a new `Audit` column folds the per-action policy together with the global `agentic.audit.enabled` switch, and the `Surfaces` column renders `http (off)` while `agentic.http.enabled` is false, so a listed surface always means a reachable one.
- **`AgenticFake::assertNotAudited()`** — the inverse of `assertAudited()`, for pinning that an action's definition resolves to not-audited.

### Fixed

- **approvals: one pending row per (key, principal), enforced by the database** — a new nullable-unique `active_key` column (non-null only while pending) makes duplicate concurrent knocks collide at the index instead of racing to create two pending rows. Settling is now a single conditional `UPDATE` guarded on id + status + expiry, so a second decision on an already-settled or expired row is a no-op and fires no duplicate event.

### Upgrading

- **The `agentic_approvals` create migration gained an `active_key` column.** It is edited in place rather than added as a follow-up migration — if you already ran the 0.0.2 migrations, roll the table back and re-migrate.
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
