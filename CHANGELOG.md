# Changelog

All notable changes to `gtapps/laravel-agentic` are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [0.0.2] - 2026-07-21

### Fixed

- **audit: readOnly actions can opt into audit** — `#[AgentAction(audit: true)]` on a `readOnly` action now enables logging; previously the recorder always skipped `readOnly` actions regardless of the flag.

### Upgrading

No config or migration changes required.

**Note:** `readOnly` actions explicitly marked `#[AgentAction(audit: true)]` that were previously silently excluded from the audit log will now be audited as intended.
