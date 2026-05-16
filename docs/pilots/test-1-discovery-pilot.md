# Test 1 Discovery Pilot

## Purpose

Run this pilot after Phase A.5 and before the first WP-aware patch grammar operation. The goal is not to produce a portfolio-grade page. The goal is to observe where an MCP client struggles when it has Open Mira's current IDE-style abilities but no semantic patch grammar yet.

Run this from an external MCP client such as Claude Code, Cursor, Claude Desktop, or another MCP host connected to the local Playground. Do not run it as a Codex-local implementation task, because that would measure Codex's direct repository access instead of the MCP client experience.

## Fixed Brief

Build one simple landing page for a fictional WordPress product called **Pilot One**.

Requirements:

- Scaffold or use a block theme named `pilot-one`.
- Create a published homepage called `Pilot One Landing`.
- Build exactly three sections:
  - Hero with eyebrow, headline, short paragraph, and one primary button.
  - Three-card feature grid.
  - CTA band with headline, short paragraph, and one button.
- Style through `theme.json` and minimal theme CSS when possible.
- Use accessible semantic HTML/block markup.
- First render must have no PHP fatals.
- Avoid third-party block libraries for this pilot.
- Keep generated files under the theme unless the client can justify a plugin.

Success criteria:

- Homepage renders at `http://127.0.0.1:9400/` or a clear page URL.
- No PHP syntax failures, no rollback left unresolved, and no visible WordPress fatal.
- Screenshot loop returns a protected image URL by default.
- Page is visually coherent at `1440x1000`.

## Required Metrics

Record these during the run:

| Metric | Value |
| --- | --- |
| MCP client |  |
| Start time |  |
| End time |  |
| Time to first front-end render |  |
| Time to completion |  |
| Tool calls total |  |
| File reads |  |
| File writes/edits |  |
| Rollbacks |  |
| Lint/syntax failures |  |
| Screenshot jobs |  |
| Manual user interventions |  |

## Friction Log

Each friction item should use this shape:

```text
### F-001: Short name

- Tool called:
- What the agent tried:
- What was missing or awkward:
- Extra tool calls caused:
- Suggested patch op or ability:
- Evidence:
```

Classify likely patch grammar candidates only from observed friction:

- Repeated tiny `theme.json` edits → candidate `*** Update theme.json (path: ...):`
- Boilerplate-heavy block creation → candidate `*** Register Block:`
- Repeated hook wiring in `functions.php` → candidate `*** Add Hook Callback:`
- Repeated pattern file/header/namespace work → candidate `*** Add Pattern:`
- Generic file replacement worked fine → do not add a semantic op yet.

## Suggested Client Prompt

```text
You are connected to Open Mira on the local WordPress Playground.

Run the Test 1 discovery pilot:
1. Build the fixed Pilot One landing page exactly as specified.
2. Use Open Mira abilities rather than direct filesystem access where possible.
3. Keep a friction log while working.
4. Capture baseline metrics: tool-call count, rollbacks, lint/syntax failures, screenshot jobs, time to first render, and time to completion.
5. Use the screenshot URL ability for visual verification. Prefer image_url/legacy resource URI. If your MCP client authenticates with an application password and cannot fetch the protected image URL, call the legacy screenshot read path with the legacy inline-image option.
6. Stop when the page renders coherently and validation is clean.
7. Return the metrics table and friction log. Do not optimize the page after the first coherent result.
```

## Authentication Note

the legacy screenshot read path returns `image_url` and the legacy resource URI by default to keep tool results small. Cookie-authenticated browser clients can fetch `image_url`. CLI clients using WordPress application passwords may not have admin cookies for `admin-ajax.php`; those clients should request `the legacy inline-image option` only when they need inline bytes.

## Automated Runner

Use `scripts/run-pilot.sh` to run this pilot without manual orchestration. The runner creates a temporary WordPress application password, connects Claude Code to the local Open Mira MCP endpoint, provides a Playwright screenshot bridge, captures the stream transcript, and revokes temporary credentials on exit.

```bash
./scripts/run-pilot.sh docs/pilots/test-1-discovery-pilot.md
```

The runner writes ignored artifacts to `#runtime/pilot-runs/<timestamp>/`, including:

- `summary.txt` — final metrics and friction log.
- `claude-stream.jsonl` — full client transcript with the temporary app password redacted.
- `screenshots/` — completed screenshot job metadata and PNGs.
- `cleanup.log` — application-password revocation and helper-plugin cleanup proof.

Use `OPENMIRA_PILOT_DRY_RUN=1 ./scripts/run-pilot.sh` to verify authentication, app-password setup, prompt creation, redaction, and cleanup without invoking Claude Code.

## 2026-05-15 Pilot Result

The first automated run completed successfully through Claude Code against the local Playground:

| Metric | Value |
| --- | --- |
| MCP client | Claude Code (`claude-sonnet-4-6`) |
| Time to first front-end render | ~1m 41s |
| Time to completion | ~5m 35s |
| Tool calls total | 22 (17 successful, 5 schema-error retries) |
| File writes/edits | 11 via `scaffold-theme` |
| Rollbacks | 0 |
| Lint/syntax failures | 0 |
| Screenshot jobs | 1 completed |
| Manual user interventions | 0 |

Observed friction, in priority order:

1. `render-gutenberg-pattern` returned flat markup and ignored design structure, forcing manual recomposition of hero, feature grid, and CTA sections.
2. `get-project-map` returned a 200 KB payload and overflowed the client context.
3. `render-gutenberg-pattern` used `pattern` while related discovery output uses `slug`, causing avoidable retries.
4. `screenshot-url` lacked `label` / `note`, making multi-iteration job tracking awkward.
5. `execute-php` rejected a natural `timeout` parameter with a generic schema error.

The evidence-backed next operation is structurally complete Gutenberg section rendering before broader patch grammar work.

## Follow-Up Decision

After the pilot, choose the first Phase B patch operation by evidence:

- If a friction pattern appears multiple times and costs several tool calls, build that semantic patch op first.
- If no clear patch-op friction appears, do one more pilot with a slightly different brief before starting Phase B.
- Do not implement patch grammar from preference alone.

## 2026-05-15 Rerun After Foundation Fixes

The rerun used the same fixed brief through Claude Code against the local Playground after the bounded project map, schema tolerance, structural Gutenberg renderer, and screenshot loop landed.

| Metric | Value |
| --- | --- |
| MCP client | Claude Code (`claude-sonnet-4-6`) |
| Time to first front-end render | ~3m 30s |
| Time to completion | ~4m |
| Tool calls total | 19 (14 successful, 5 failures) |
| File writes/edits | 11 via `scaffold-theme` |
| Rollbacks | 0 |
| Lint/syntax failures | 0 |
| Screenshot jobs | 1 completed |
| Manual user interventions | 0 |

Artifacts are in `#runtime/pilot-runs/20260515T224756Z-rerun-pty-clean/`.

Confirmed improvements:

- `get-project-map` no longer forces a 200 KB first response by default.
- `render-gutenberg-pattern` now returns structural core blocks for hero, feature grid, and CTA.
- `screenshot-url` completes through the Playwright bridge and returns URL/resource metadata without inline base64.
- The resulting page remains visible at `http://127.0.0.1:9400/` and `http://127.0.0.1:9400/pilot-one-landing/`.

New friction found and fixed:

1. `run-wpcli` now accepts a natural `command` string alias in addition to `args`.
2. `render-gutenberg-pattern` now accepts WordPress palette slugs such as `base`, `contrast`, and `var:preset|color|secondary` without fatal errors.
3. Repeatable pattern items now accept `heading`, `label`, `description`, and `text` aliases instead of silently dropping reasonable copy fields.
4. `screenshot-url` now accepts a nested `viewport: { width, height }` object alias.

Operational note: in this Codex desktop environment the Playground CLI must run in a persistent PTY. Background `nohup`/`npm exec` starts were not reliable. Deleting and recreating the same `pilot-one` theme slug inside one Playground session can also leave a virtual-filesystem tombstone, so the runner supports disabling theme reset with `OPENMIRA_PILOT_RESET_THEME_SLUG=''` when preserving the current clickable page is more important than a clean slug reset.

## 2026-05-16 Pilot #3 After Friction Fixes

Pilot #3 used the same fixed brief through Claude Code against a fresh Playground site after the friction fixes from the rerun landed.

Two invalid attempts were discarded before measuring:

- A reused Playground site kept stale virtual-filesystem state for the `pilot-one` theme slug.
- A fresh site exposed that AI Abilities are disabled by default, so the runner now enables them during bootstrap before creating the temporary application password.

The valid run artifacts are in `#runtime/pilot-runs/20260516T010900Z-pilot3-clean-after-bootstrap/`.

| Metric | Value |
| --- | --- |
| MCP client | Claude Code (`claude-sonnet-4-6`) |
| Time to first front-end render | ~2m 30s |
| Time to completion | ~3m |
| Tool calls total | 17 (15 successful, 2 failures) |
| File writes/edits | 11 via `scaffold-theme` |
| Rollbacks | 0 |
| Lint/syntax failures | 0 |
| Screenshot jobs | 1 completed |
| Manual user interventions | 0 |

Measured improvement versus prior runs:

- Schema failures dropped from 5 to 2.
- Tool calls dropped from 19 to 17.
- Completion time dropped from ~4m to ~3m.
- Project-map overflow stayed fixed.
- Screenshot capture still completed end-to-end.

New friction found:

1. `create-gutenberg-page` rejected a natural top-level `raw_markup` payload and required `sections`, causing a failed call plus a schema lookup.
2. `run-wpcli` accepted the natural `command` alias but rejected `option update show_on_front page`; a dedicated front-page setter is safer than broadening the WP-CLI allowlist.
3. Hero pattern colors still used hardcoded defaults for eyebrow and muted text instead of palette-coupled CSS variables.
4. Feature-grid card border and muted text colors still used hardcoded gray values instead of theme palette defaults.

Decision: hold broad tool compression and Phase B patch grammar. Patch these evidence-backed friction items first, then rerun or live-test the same brief before choosing the first semantic patch operation.

## 2026-05-16 Pilot #4 After Three Friction Fixes

Pilot #4 used the same fixed brief after adding top-level page markup aliases, a safe `set-front-page` ability, and palette-coupled pattern defaults.

The valid run artifacts are in `#runtime/pilot-runs/20260516T025905Z/`.

| Metric | Value |
| --- | --- |
| MCP client | Claude Code (`claude-sonnet-4-6`) |
| Time to first front-end render | ~2m |
| Time to completion | ~2m |
| Tool calls total | 14 MCP ability calls (12 successful, 2 failures) |
| File writes/edits | 11 via `scaffold-theme` |
| Rollbacks | 0 |
| Lint/syntax failures | 0 |
| Screenshot jobs | 1 completed |
| Manual user interventions | 0 |

Resolved versus Pilot #3:

- `set-front-page` replaced unsafe `wp option update` fallback.
- Top-level `raw_markup` worked for page creation.
- Hero and feature-grid colors used palette variables instead of hardcoded gray/green values.

New friction found:

1. `run-wpcli` still attracted a theme activation attempt, but WP-CLI is not available in Playground; add a dedicated `activate-theme` ability and make `scaffold-theme` advertise `activate=true`.
2. `sections: [{ raw_markup: "..." }]` failed because nested raw aliases were not accepted; accept `block_markup`, `raw_markup`, and `markup` inside each section.
3. `get-project-map` dropped natural `sections` input when the agent meant `fields`; accept `sections` as a `fields` alias.

Decision: patch these directly attributable frictions and rerun before Phase B.

## 2026-05-16 Pilot #5 Decision Gate

Pilot #5 used the same fixed brief after adding nested section raw aliases, `activate-theme`, `scaffold-theme` activation guidance, and `get-project-map.sections` as a `fields` alias.

The valid run artifacts are in `#runtime/pilot-runs/20260516T030534Z/`.

| Metric | Value |
| --- | --- |
| MCP client | Claude Code (`claude-sonnet-4-6`) |
| Time to first front-end render | ~2m |
| Time to completion | ~3m including screenshot |
| Tool calls total | 13 (12 MCP + 1 Bash bridge) |
| File writes/edits | 11 via `scaffold-theme` |
| Rollbacks | 0 |
| Lint/syntax failures | 0 |
| Screenshot jobs | 1 completed |
| Manual user interventions | 0 |

Decision gate result:

- Target ≤15 tool calls: passed.
- Target ≤1 schema/tool failure: passed with 0 failures.
- Prior frictions confirmed resolved: project-map overflow, structural pattern rendering, palette slugs, palette-coupled colors, safe front-page setting, nested viewport screenshot jobs, and screenshot completion.

Remaining friction: the agent used one `get-ability-info` call to confirm `sections[*].block_markup`. The short `create-gutenberg-page` description now names that shape explicitly.

Decision: Test 1 has reached the current architecture floor. Start Phase B with WP-aware patch grammar rather than more aliases or broad compression.
