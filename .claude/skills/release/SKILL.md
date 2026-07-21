---
name: release
description: Cut a release of the gtapps/laravel-agentic Composer package — validate, write a CHANGELOG entry, commit, push, tag, and publish a GitHub release. Use whenever the user says "release", "version bump", "cut a release", "changelog and push", "tag a release", or finishes a set of changes and wants to ship them to Packagist.
---
# Release

Cut a release of `gtapps/laravel-agentic`. This is a **Composer package**, not
an app or a plugin: the version lives in the **git tag** and nowhere else.
Packagist reads tags to publish versions, so the tag *is* the release.

**Never add a `version` field to `composer.json`.** A hardcoded version there
conflicts with the tag and Packagist rejects or ignores it.

## Usage

`/release` — infer the bump level from the changes since the last tag.
`/release patch|minor|major` — force a bump level.
`/release 0.3.0` — force an exact version.

## Steps

### 1. Pre-release validation

Run before anything else. Abort the release if any step fails — do not "fix it
after tagging", a published tag is immutable on Packagist.

```bash
vendor/bin/pint --test          # formatting must be clean
php vendor/bin/pest             # full suite must be green
```

Notes:
- Plain `php` works (Testbench runs on any PHP ≥ 8.3; suite is green on 8.4 and 8.5).
- If Pint reports diffs, run `vendor/bin/pint` and include the formatting in the release commit only if it is trivially mechanical. Substantive formatting churn belongs in its own commit before the release.
- `tests/ParityTest.php` is the one that matters most — it asserts all five surfaces behave identically. If it fails, stop.

Then check for drift the test suite cannot see:

1. **Public API surface** — if any `src/` class, method signature, config key, or attribute parameter changed, that is at minimum a `minor` under 0.x. List them.
2. **Config drift** — if `config/agentic.php` gained or renamed a key, it must appear in the CHANGELOG's Upgrading section (publishers hold a stale copy of this file).
3. **Migrations** — if `database/migrations/` gained a file, it must appear in the Upgrading section (consumers must re-run `migrate`).
4. **README/CLAUDE.md accuracy** — if a surface, command, or config key changed, confirm both files still describe reality. Fix in this release; docs fixes go in the commit message, not the CHANGELOG (see Step 3).

### 2. Refresh the knowledge graph

The repo carries a gitignored graphify knowledge graph at `graphify-out/`.
Refresh it so it reflects the code that's shipping:

```bash
if command -v graphify >/dev/null && [ -f graphify-out/graph.json ]; then
  graphify update .
fi
```

AST-only, no API cost. Guarded on the graph existing, so it skips silently on
checkouts without graphify. `graphify-out/` is gitignored, so this never
affects release staging or the Step 5 `git status` check. If it errors, warn
and continue — a stale graph must not block a release.

### 3. Determine the version

**Already-tagged fast-path:** if `HEAD` already carries a `v*` tag, there is
nothing to release. Report the tag and stop.

Read the last tag and the changes since it:

```bash
LAST=$(git tag --list 'v*' | sort -V | tail -1)
git log --oneline "$LAST"..HEAD
git diff --stat "$LAST"..HEAD
```

Decide the bump level. The package is pre-1.0, so **`^0.x` constraints treat
every minor as breaking** — err toward minor whenever a consumer could notice:

- **Patch** (0.0.X) — bug fixes, internal refactors, added tests, doc-only releases.
- **Minor** (0.X.0) — new features, new surfaces, new config keys, new migrations, any public API change, any behavior change a consumer could depend on.
- **Major** (X.0.0) — only if the user explicitly asks, or for the 1.0.0 cut.

Present the suggested version and the rationale (name the specific changes
driving it). **Wait for confirmation before proceeding.**

### 4. Write the CHANGELOG entry

If `CHANGELOG.md` does not exist, create it:

```markdown
# Changelog

All notable changes to `gtapps/laravel-agentic` are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).
```

Prepend the new entry immediately after that header block, before the previous
version entry. If an `[Unreleased]` section already exists, rename it to
`[X.Y.Z] - YYYY-MM-DD` instead of prepending — it has been accumulating during
development.

**Docs-only double-check (do this before writing the entry).** Read every
bullet about to ship and confirm each describes a real code or behavior change
a *consumer of the package* experiences. Drop any bullet whose only backing
change is `README.md`, `CLAUDE.md`, `AGENTS.md`, or a code comment — including
the docs half of a bullet that pairs a docs fix onto a code change. Verify
against the diff: `git diff "$LAST"..HEAD -- src/ config/ database/ routes/`.
Docs corrections belong in the commit message, never the CHANGELOG.

**Format:**

```markdown
## [X.Y.Z] - YYYY-MM-DD

### Added / Changed / Fixed
(use whichever sections apply — skip empty ones)

- **subsystem: one-line summary** — optional ≤1-sentence rationale.

### Upgrading

1. **Imperative step title** — what to do, in one sentence.

No config or migration changes required.
```

**Constraints (enforce these):**

1. **Narrative bullets** — `- **subsystem: what changed** — short rationale if non-obvious.` Target one line, ~40 words max.
   - Lead with the subsystem, using the architecture's own vocabulary: `runner:`, `registry:`, `schema:`, `approvals:`, `audit:`, `mcp:`, `http:`, `cli:`, `jobs:`, `testing:`.
   - Do NOT list internal refactors, helper extractions, test scaffolding, or renamed variables — those are visible in `git diff`.
   - Migration steps and shell snippets belong in `### Upgrading`, never in the bullet.

2. **Upgrading** — strict imperative block, one action per numbered step, every step starting with a verb (`Run`, `Republish`, `Add`, `Replace`, `Delete`). No rationale clauses — the *why* lives in the Changed bullet above. Include a step for each of:
   - New or changed migration → `Run \`php artisan migrate\`.`
   - New or renamed `config/agentic.php` key → `Republish the config with \`--force\`, or add the \`<key>\` key manually.` Name the key and its default.
   - Changed public API → the exact old→new call shape.
   - Cached definitions affected by a schema or registry change → `Run \`php artisan agentic:clear\`.`

   Close with `No config or migration changes required.` when true — it's the common case and consumers scan for it.

3. **What belongs where:** why it changed → the bullet. What the consumer must execute → Upgrading. A behavior delta needing no action → one final line prefixed `**Note:**`, not a numbered step.

### 5. Final validation

Steps 2–4 only touch Markdown, so re-running the suite is unnecessary. Confirm:

```bash
git status --short
grep -c '"version"' composer.json   # must print 0
```

`git status` must show only `CHANGELOG.md` plus any doc files this release
legitimately corrected. Any unexpected entry → investigate before committing.
`HANDOFF.md`, `PLAN-V1.md`, `.claude/settings.local.json`, and `graphify-out/`
are gitignored and must never appear.

### 6. Commit and push

Stage only the files this release touched (**not** `git add -A`). Commit
following the existing convention:

```
release: v<X.Y.Z>
```

Add a body summarizing the release and any docs corrections that were kept out
of the CHANGELOG. Push to `origin`.

### 7. Branch check before tagging

```bash
git branch --show-current
```

- **On `main`** → tag immediately (Step 8).
- **On any other branch** → **stop. Do not tag.** Tagging a branch tip pins a
  SHA that `main` never carries after a squash or rebase merge, stranding the
  tag on an orphan commit — and Packagist will publish that orphan as the
  release.

  **Recommended path:** open a PR, merge to `main`, then re-run `/release` from
  `main`. Offer tagging now only as an explicit second option, and wait for the
  user's choice.

### 8. Tag and publish

Tags are **plain `v<X.Y.Z>`** — this is what Composer's version parser and
Packagist expect. Do not prefix the package name.

Create an annotated tag (matching `v0.0.1`, which is annotated) and push it:

```bash
VERSION=<X.Y.Z>
git tag -a "v$VERSION" -m "v$VERSION"
git push origin "v$VERSION"
```

Then create the GitHub release, sourcing notes from the CHANGELOG section just
written rather than `--generate-notes`:

```bash
NOTES_FILE=$(mktemp)
awk -v ver="$VERSION" '
  $0 ~ "^## \\[" ver "\\]" {flag=1; next}
  /^## \[/ && flag {exit}
  flag {print}
' CHANGELOG.md > "$NOTES_FILE"
[ ! -s "$NOTES_FILE" ] && { echo "CHANGELOG section for $VERSION not found — fix and retry"; rm "$NOTES_FILE"; exit 1; }
gh release create "v$VERSION" --title "v$VERSION" --notes-file "$NOTES_FILE"
rm "$NOTES_FILE"
```

If CI runs on tags, confirm the workflow went green before announcing.

### 9. Report

Print the new version, the commit hash, the tag, the GitHub release URL, and a
one-liner confirming it's pushed. Note that Packagist picks up the tag via its
GitHub hook — if the new version does not appear at
`https://packagist.org/packages/gtapps/laravel-agentic` within a few minutes,
the hook needs re-triggering from the Packagist package page.
