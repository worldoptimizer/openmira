# Open Mira Test 3A — Plugin Bugfix: Leaflet Map Issue #278

## Purpose

Validate Open Mira's WordPress-aware IDE surface outside the theme/page lane by fixing a real bug in an installed open-source plugin.

The deliverable is the friction log and minimal diff quality. Stop at the first verified fix.

## Selected Plugin

- Plugin: Leaflet Map by bozdoz
- Repository: https://github.com/bozdoz/wp-plugin-leaflet-map
- Installed path in Playground: `wp-content/plugins/leaflet-map`
- Main plugin file: `wp-content/plugins/leaflet-map/leaflet-map.php`
- GitHub issue: https://github.com/bozdoz/wp-plugin-leaflet-map/issues/278
- Issue title: `html chars and Javascript`

Issue summary: attribution text containing HTML entities such as `&copy;` does not render correctly in the map attribution; the reporter observed an extra comma after the character.

## Required Workflow

Use Open Mira MCP abilities for WordPress/plugin reads and writes. Do not use local shell to inspect or edit plugin source, except for the screenshot bridge if a screenshot job is created.

1. Confirm the plugin is installed and active.
2. Read the relevant plugin files through Open Mira abilities.
3. Locate how `[leaflet-map]` shortcode attribution is parsed, sanitized, and emitted to JavaScript.
4. Reproduce or explain the issue from code behavior with a minimal shortcode such as `[leaflet-map attribution="&copy; Test"]` or the closest valid attribution form the plugin expects.
5. Apply a minimal fix in the plugin source.
6. Verify changed PHP with `lint-file` or another Open Mira syntax/runtime check.
7. If practical, create a page using the shortcode and use the screenshot bridge to inspect the rendered result. If visual verification is not practical within budget, report why and provide code-level verification instead.

## Budget

- Stop after 25 MCP ability calls or 15 minutes wall time, whichever comes first.
- If the bug is not fixed inside the budget, return the friction log and best partial diagnosis.
- Do not broaden into unrelated open issues or security fixes.

## Success Criteria

- Plugin remains active or activatable without PHP fatal.
- Changed PHP passes syntax validation.
- Fix is minimal and scoped to attribution/entity handling.
- No unrelated plugin files are modified.
- The final report states whether hook navigation helped, was irrelevant, or was not discoverable.
- The final report states whether a new Open Mira capability would have reduced calls.

## Metrics To Report

- MCP ability-call count.
- Schema/tool failures.
- Rollbacks.
- Syntax/lint failures.
- Time to locate the relevant file.
- Time to first verified fix.
- Whether `find-hook-callers` / `find-hook-registrants` was used and whether it helped.
- Whether `apply-patch` was useful or bypassed.
- Files created/edited by path.

## Friction Log Format

For each friction point:

- Tool or ability involved.
- What the agent tried.
- What was missing, unclear, or too verbose.
- What Open Mira capability would have reduced the tool calls.

## Decision Gate

After this pilot:

- If hook placement is painful, prioritize `*** Add Hook Callback:`.
- If plugin creation/setup is painful, prioritize `scaffold-plugin`.
- If locating code is painful, prioritize a real `search-code` ability or project-map focused search mode.
- If the agent never reaches for hook navigation when relevant, tune discovery/descriptions before adding new patch ops.
- If verification is weak, prioritize richer `lint-changes` / runtime checks.

## Pilot Result

Run directory: `#runtime/pilot-runs/20260516T094456Z`

Outcome: fixed in the Playground plugin install.

Metrics:

- MCP ability calls: 11
- Schema/tool failures: 0
- Rollbacks: 0
- Syntax/lint failures: 0
- Time to locate relevant file: about 2 calls
- Time to first verified fix: about 6 calls
- Hook navigation: not used because the attribution path is direct shortcode parsing, not hook indirection
- `apply-patch`: bypassed because `edit-file` was simpler for one exact PHP hunk

Fix summary:

- Root cause: front-end JavaScript splits attribution strings on `;`, so the entity terminator in `&copy;` created a false extra attribution entry.
- Applied fix: decode sanitized attribution entities in `wp-content/plugins/leaflet-map/shortcodes/class.map-shortcode.php` before JSON-encoding options for JavaScript.
- Verification: `lint-file` reported no syntax errors; `execute-php` confirmed `&copy; Test` becomes `© Test` and no longer splits into two attribution entries.
- Visual check: created `/leaflet-attribution-test/` and completed a screenshot job, but attribution text was too small for full-page screenshot verification to be the primary proof.

Friction:

- Code location required directory listing and broad reads. A scoped `search-code` ability would have found both PHP and JavaScript attribution handling faster.
- Full-page screenshot was too coarse for small control text. A future crop or selector option on `screenshot-url` would help plugin UI verification.

Follow-up shipped:

- `openmira/search-code` v1 landed from this evidence.

## Pilot Rerun Result

Run directory: `#runtime/pilot-runs/20260516T095845Z`

Outcome: fixed again after resetting Leaflet Map to upstream. This run validated that `search-code` is discoverable without explicit instructions.

Metrics:

- MCP ability calls: 9
- Schema/tool failures: 0
- Rollbacks: 0
- Syntax/lint failures: 0
- Hook navigation: not used because the issue was still direct shortcode parsing
- `apply-patch`: bypassed because `edit-file` remained the right tool

Search-code signal:

- One `search-code` call for `attribution` found both relevant sides of the bug: PHP shortcode attribution assignment and JavaScript `attribution.split(';')`.
- Calls dropped from 11 to 9 compared with the first run.
- The remaining search friction is result noise. A future value-flow or scoped search mode could rank `class.map-shortcode.php` and `construct-leaflet-map.js` above settings/image-overlay matches.

Screenshot signal:

- The agent requested the legacy inline-image option and hit a large base64 response. This confirms inline screenshot bloat still happens when the client does not use Resources or the bridge file path.
- Follow-up shipped in the legacy screenshot read path: inline base64 is now capped by `inline_image_max_bytes` and large screenshots return a structured refusal plus resource/image alternatives.
