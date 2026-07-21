# Changelog

All notable changes to `gtapps/laravel-agentic` are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed

- **BREAKING: HTTP surface is now opt-in** — `agentic.http.enabled` defaults to `false`. Previously the HTTP surface auto-mounted with no authentication (the `api` middleware group does not authenticate), making any action without an `authorize()` method anonymously reachable. Set `agentic.http.enabled => true` and add your auth middleware (e.g. `auth:sanctum`) to restore it.

### Added

- **`agentic.audit.connection` / `agentic.approvals.connection`** — configure the database connection audit rows and approval rows are written to, independent of the app's default connection (`null` = current behavior).
- **`agentic:list` shows an `Audit` column** — the effective audit state (per-action policy AND the global `agentic.audit.enabled` switch).
- **`AgenticFake::assertNotAudited()`** — the inverse of `assertAudited()`, for pinning that an action's definition resolves to not-audited.

### Upgrading

- If you rely on the HTTP surface, set `agentic.http.enabled => true` in your published config and ensure auth middleware is configured before re-enabling.
- The audit boundary and failure semantics are now documented precisely in the README `## Audit` section — audit is synchronous and exception-propagating, written after the handler runs; it is not a transactional fail-closed guarantee.

## [0.0.2] - 2026-07-21

### Fixed

- **audit: readOnly actions can opt into audit** — `#[AgentAction(audit: true)]` on a `readOnly` action now enables logging; previously the recorder always skipped `readOnly` actions regardless of the flag.

### Upgrading

No config or migration changes required.

**Note:** `readOnly` actions explicitly marked `#[AgentAction(audit: true)]` that were previously silently excluded from the audit log will now be audited as intended.
