# Changelog

All notable changes to `gtapps/laravel-agentic` are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed

- **BREAKING: `ApprovalBroker::check()` returns `?Approval` instead of `CheckResult`** — the consumed approval is now returned so the audit row for the execution can link to it; the `Gtapps\LaravelAgentic\Approvals\CheckResult` enum is deleted. Callers only ever compared against `CheckResult::Granted`, so `$broker->check(...) !== null` is the direct replacement.
- **BREAKING: HTTP surface is now opt-in** — `agentic.http.enabled` defaults to `false`. Previously the HTTP surface auto-mounted with no authentication (the `api` middleware group does not authenticate), making any action without an `authorize()` method anonymously reachable. Set `agentic.http.enabled => true` and add your auth middleware (e.g. `auth:sanctum`) to restore it.

### Fixed

- **audit rows now link to the approval that authorized them** — a successful run behind an approval recorded `approval_id = null`; only the knock row carried the id. The audit trail now answers "who approved this" for the execution itself, as the README promises.

### Added

- **`agentic.audit.connection` / `agentic.approvals.connection`** — configure the database connection audit rows and approval rows are written to, independent of the app's default connection (`null` = current behavior).
- **`agentic:list` reports effective exposure, not just declared** — a new `Audit` column folds the per-action policy together with the global `agentic.audit.enabled` switch, and the `Surfaces` column renders `http (off)` while `agentic.http.enabled` is false, so a listed surface always means a reachable one.
- **`AgenticFake::assertNotAudited()`** — the inverse of `assertAudited()`, for pinning that an action's definition resolves to not-audited.

### Upgrading

- **If you already published `config/agentic.php`, this fix does not reach you.** The published file still carries `'enabled' => true`, and it wins over the package default — your HTTP surface stays mounted and unauthenticated after upgrading. Open the file and set it to `false`, or add auth middleware, before assuming you are covered.
- If you rely on the HTTP surface, set `agentic.http.enabled => true` in your published config and ensure auth middleware is configured before re-enabling.
- The audit boundary and failure semantics are now documented precisely in the README `## Audit` section — audit is synchronous and exception-propagating, written after the handler runs; it is not a transactional fail-closed guarantee.

## [0.0.2] - 2026-07-21

### Fixed

- **audit: readOnly actions can opt into audit** — `#[AgentAction(audit: true)]` on a `readOnly` action now enables logging; previously the recorder always skipped `readOnly` actions regardless of the flag.

### Upgrading

No config or migration changes required.

**Note:** `readOnly` actions explicitly marked `#[AgentAction(audit: true)]` that were previously silently excluded from the audit log will now be audited as intended.
