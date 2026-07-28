---
name: docs-drift
description: Documentation-drift audit for the gtapps/laravel-agentic Composer package. Audit either unreleased consumer-visible changes since the latest reachable tag or the latest shipped release from its previous tag, then compare those claims against the matching README.md, package guidance, published stubs, configuration documentation, and release workflow instructions. Report meaningful contradictions with concrete proposed edits and wait for approval before changing files. Use when preparing or verifying a release, when asked to check the latest version, or when the user says "docs drift", "check the docs", "are the docs up to date", "docs audit", "did the docs keep up", or whether documentation matches released or unreleased changes.
---

# Docs Drift

Verify that this package's documentation tells the truth about either the
changes about to ship or the latest version already shipped.

Keep the audit read-only. Do not edit, commit, push, tag, publish, or release
until the user explicitly approves specific documentation findings. Even after
approval, edit only the approved documentation and never commit or publish from
this skill.

## Step 1 — Choose the audit mode

Run from the repository root and confirm the package identity in
`composer.json`.

Choose one mode from the user's request:

- **Unreleased** — `$docs-drift` or `$docs-drift unreleased`. Audit changes
  after the latest reachable release tag plus relevant working-tree changes.
  Use this by default.
- **Latest release** — `$docs-drift latest`, "check the latest version", or
  equivalent wording. Audit the newest reachable release tag against its
  immediately preceding reachable release tag. Do not stop merely because
  `HEAD` is already tagged.

Never mix the two windows. In latest-release mode, later commits and
working-tree changes are not release claims.

Establish the refs:

```bash
git branch --show-current
git status --short

AUDIT_MODE=unreleased # set to latest when requested
LATEST_RELEASE=$(git describe --tags --abbrev=0 --match 'v*' HEAD 2>/dev/null || true)
EMPTY_TREE=$(git hash-object -t tree /dev/null)

if [ "$AUDIT_MODE" = latest ]; then
  if [ -z "$LATEST_RELEASE" ]; then
    printf '%s\n' 'No reachable release tag — cannot audit the latest release.'
    exit 1
  fi

  PREVIOUS_RELEASE=$(git describe --tags --abbrev=0 --match 'v*' "${LATEST_RELEASE}^" 2>/dev/null || true)
  RELEASE_TO="$LATEST_RELEASE"
  DOCS_REF="$LATEST_RELEASE"

  if [ -n "$PREVIOUS_RELEASE" ]; then
    RELEASE_DIFF_BASE="$PREVIOUS_RELEASE"
  else
    RELEASE_DIFF_BASE="$EMPTY_TREE"
  fi
else
  RELEASE_TO=HEAD
  DOCS_REF=WORKTREE

  if [ -n "$LATEST_RELEASE" ]; then
    RELEASE_DIFF_BASE="$LATEST_RELEASE"
  else
    RELEASE_DIFF_BASE="$EMPTY_TREE"
  fi
fi
```

Use the latest **reachable** tags. Do not select an unreachable tag from another
branch merely because its version sorts later. If latest-release mode finds
only one reachable tag, audit that first release from the empty tree.

Inspect the committed window:

```bash
if [ "$AUDIT_MODE" = latest ]; then
  if [ -n "$PREVIOUS_RELEASE" ]; then
    git log --oneline "$PREVIOUS_RELEASE".."$LATEST_RELEASE"
  else
    git log --oneline --reverse "$LATEST_RELEASE"
  fi
elif [ -n "$LATEST_RELEASE" ]; then
  git log --oneline "$LATEST_RELEASE"..HEAD
else
  git log --oneline --reverse HEAD
fi

git diff --name-status "$RELEASE_DIFF_BASE" "$RELEASE_TO"
```

In unreleased mode, also include:

- staged and unstaged tracked changes;
- relevant untracked source, config, migration, route, stub, or documentation
  files shown by `git status`;
- a non-empty `## [Unreleased]` section in `CHANGELOG.md`, when one exists.

```bash
git diff --name-status
git diff --cached --name-status
```

If unreleased mode finds no committed, staged, unstaged, relevant untracked, or
`[Unreleased]` changes, stop with:

`No unreleased changes — nothing to drift-check.`

Latest-release mode does not use this stop condition.

## Step 2 — Extract doc-checkable claims

Use the claims from the selected window only:

- **Unreleased:** read the working tree's non-empty `[Unreleased]` section when
  present, then inspect the committed and working-tree diffs.
- **Latest release:** read the exact `## [<version>]` section from
  `CHANGELOG.md` at `DOCS_REF`, then inspect only
  `<previous-release>..<latest-release>`. Do not use current unreleased changes
  as evidence for what the latest release shipped.

Inspect the selected committed diff:

```bash
git diff "$RELEASE_DIFF_BASE" "$RELEASE_TO" -- \
  src/ config/ database/ routes/ stubs/ composer.json
```

In unreleased mode only, also inspect:

```bash
git diff -- src/ config/ database/ routes/ stubs/ composer.json
git diff --cached -- src/ config/ database/ routes/ stubs/ composer.json
```

Turn changes into zero or more claims:

- **Removal or rename** — a public class, method, attribute argument, config or
  environment key, command, route, event, surface, stub, or documented path
  changed identity.
- **New consumer-visible surface** — a new action feature, surface behavior,
  command, config key, event, testing helper, schema feature, or integration
  should appear where sibling capabilities are enumerated.
- **Changed default or behavior** — documentation may still describe the old
  validation, authorization, approval, audit, schema, discovery, caching, or
  surface behavior. Treat this as the highest-severity class.
- **Installation or compatibility change** — PHP, Laravel, laravel/ai,
  laravel/mcp, spatie/laravel-data, publishing, migration, or setup guidance may
  now be stale.
- **Count or version marker** — a hand-written count, badge, compatibility
  range, surface list, command list, or current-version marker may be wrong.
- **Promised documentation or upgrade step** — a changelog-cited file, anchor,
  config example, migration instruction, or cache-clear instruction must exist
  and cover what the claim promises.

Mark internal refactors, test-only changes, formatting, and implementation
details with no reader-visible contract as `no doc surface`. Do not manufacture
a finding for every changed file.

This is a documentation audit, not a changelog-versus-code review. Use the diff
to discover claims, but do not expand into a general code review.

## Step 3 — Sweep the matching documentation snapshot

Work claim-first, not file-first. For every claim, search old names, defaults,
counts, examples, removed paths, and sibling enumerations. Read only the
matching passages and the narrow context needed to judge an omission.

Use the documentation snapshot selected in Step 1:

- **Unreleased:** inspect the working tree with `rg -n`.
- **Latest release:** inspect the files as they existed at `DOCS_REF` with
  `git grep -n <pattern> "$DOCS_REF" -- <paths>` and
  `git show "$DOCS_REF:<path>"`. Never silently substitute current
  documentation for the released snapshot.

Consider these documentation surfaces when relevant:

- `README.md` — public installation, usage, behavior, examples, configuration,
  testing, compatibility, badges, and surface lists.
- `AGENTS.md` and `CLAUDE.md` — package architecture and contributor workflow.
- `stubs/AGENTS.md` — guidance published into consumer applications; treat it
  as a separate public artifact, not a duplicate that can be ignored.
- `config/agentic.php` comments and examples — configuration semantics exposed
  to consumers who publish the config.
- `CHANGELOG.md` — promised documentation and upgrade instructions in the
  selected release window.
- `.claude/skills/release/SKILL.md` and `.agents/skills/*/SKILL.md` — only when
  a claim changes release, verification, or package-maintenance instructions,
  and only if the path exists in the selected snapshot.

Use `composer.json`, code, tests, and migrations as evidence for what the docs
must say, not as documentation targets.

When a verdict depends on version-specific Laravel ecosystem behavior, use
Laravel Boost `search-docs` with the relevant installed packages and broad
topic queries before declaring the local documentation wrong. Keep framework
evidence separate from repository evidence.

Check mirrored passages independently. A correct `README.md` does not excuse a
stale `stubs/AGENTS.md`, and a correct `CLAUDE.md` does not excuse a stale
`AGENTS.md`.

In latest-release mode, re-check every released-snapshot finding against the
current working tree:

- If the current tree is still stale, propose an edit to the current file.
- If the current tree already fixed it, report it under `Fixed after release`
  with no proposed edit.
- If the file no longer exists, explain the replacement or removal and do not
  propose recreating it without evidence.

## Step 4 — Judge meaningfulness

Propose an edit only when a reader would be factually misled:

- **Meaningful:** names a removed or renamed public surface; states an old
  default or behavior; gives a wrong version, count, compatibility range, or
  command; omits a new item from an explicit sibling enumeration; publishes
  stale generated guidance; or promises documentation that is absent.
- **Not meaningful:** tone, formatting, missing marketing coverage, internal
  implementation detail, test-only changes, or an optional example that
  remains valid.
- **Borderline:** a possible mismatch whose reader impact or source of truth is
  unclear. Report it separately with a one-line rationale and no proposed edit.

Do not pad findings. If external framework documentation and local behavior
appear to disagree, report the evidence conflict instead of guessing.

## Step 5 — Report and wait for approval

Use this shape:

```text
# Docs Drift — <date>

## Scope
Mode: <unreleased | latest release>
Release target: <HEAD | latest tag>
Committed window: <base or empty tree>..<target> (<count> commits)
Documentation snapshot: <working tree | latest tag>
Working tree inclusion: <included | excluded from release claims>
Claims: <changelog section, diff-derived, or both>

## Findings
### <n>. <misleading | stale-reference | missing-doc>
- Claim: <abbreviated selected-window claim and evidence path>
- Drift: <snapshot ref>:<doc file:line> — currently says: "<short excerpt>"
- Current tree: <still stale | changed | file removed>
- Proposed edit: <exact replacement text, or "add section X covering Y">

## Fixed after release (no edit proposed)
- <released ref>:<file:line> — <what was stale and where it is now corrected>

## Borderline (no edit proposed)
- <snapshot ref>:<file:line> — <one-line rationale>

## No doc surface
- <briefly grouped internal or test-only changes>

## Pre-existing drift (not this window)
- <anything noticed outside the selected release window>

## Verdict
<N> actionable findings across <M> current files | Docs are clean for this window.
```

Ask which actionable findings to apply: all, selected finding numbers, or none.
The audit request itself is not approval to edit.

After the user approves:

1. Apply only the approved documentation edits to the current working tree with
   `apply_patch`. Never edit a tag.
2. Match each file's existing style and preserve generated-vs-source
   boundaries.
3. Re-read every changed passage.
4. Run `git diff --check`.
5. Run focused searches for each stale name, value, or count that was fixed.
6. Do not add a changelog entry for documentation-only corrections; the
   package release workflow keeps those in the commit message.
7. Report the uncommitted files and verification. Never commit, push, tag,
   publish, or release from this skill.
