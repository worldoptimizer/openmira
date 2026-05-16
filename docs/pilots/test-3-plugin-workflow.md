# Open Mira Test 3 — Plugin Workflow Benchmark

## Purpose

Measure whether Open Mira behaves like a WordPress-aware IDE outside the theme/page-building lane.

This benchmark must exercise plugin code navigation, hook reasoning, safe PHP editing, and verification. The output is the friction log and diff quality, not a polished UI.

## Preferred Brief

Fix a small real bug in a real open-source WordPress plugin. The runner or operator should pre-select the plugin and issue before the pilot starts.

Use an untracked reference/work directory for downloaded plugin code and do not track the dependency itself. Prefer a plugin with:

- under ~2,000 lines of first-party PHP;
- a public GitHub issue with a clear reproduction or code-level failure mode;
- no WooCommerce/security/payment dependency;
- no large vendor directory or build step required for the bug;
- no dependency on paid services or other premium plugins.

Install the selected plugin into the Playground `wp-content/plugins/` directory before the pilot so WordPress-aware tooling can inspect loaded code and, where useful, activate or verify it. Treat the plugin source as out-of-scope except for the minimal bug fix.

The agent should:

- read the plugin code through Open Mira abilities;
- locate the relevant hooks/classes/files;
- reproduce or explain the bug from code behavior;
- apply a minimal safe fix;
- run syntax/lint or a local runtime check;
- return the diff and friction log.

## Fallback Brief

If a suitable real bug cannot be selected quickly, build a small plugin from scratch that registers:

- one custom post type;
- one taxonomy;
- one meta box with save handling;
- one settings page;
- one front-end shortcode or block render callback.

This fallback deliberately tests plugin scaffolding and hook authoring without external issue selection noise.

There is no `scaffold-plugin` ability yet. The fallback must build the compact plugin through the current safe file-editing surface (`write-file`, `edit-file`, PHP syntax validation, and hook navigation) so the run measures whether `scaffold-plugin` is actually needed.

## Required Surface To Exercise

- `get-project-map`
- `read-file`
- `write-file` / `edit-file` / `apply-patch` where appropriate
- `find-hook-callers` or `find-hook-registrants`
- `lint-file` or PHP syntax validation
- `run-wpcli` only where the allowlist fits
- screenshot only if the plugin has visible front-end/admin UI

## Budget

- Stop after 25 MCP ability calls or 15 minutes wall time, whichever comes first.
- If the bug is not fixed inside the budget, return the friction log and the best partial diagnosis.
- Do not spend more than 10 minutes selecting a plugin/issue; use the fallback brief if selection stalls.

## Success Criteria

- Plugin remains activatable with no PHP fatal.
- Changed PHP passes syntax validation.
- Fix is minimal and explainable from code context.
- Any new hook registration follows project naming/text-domain conventions.
- No unrelated files or bundled dependencies are modified.
- Agent reports whether a WordPress-specific patch op would have reduced calls.

## Metrics To Report

- Tool-call count.
- Schema/tool failures.
- Rollbacks.
- Syntax/lint failures.
- Time to locate the relevant file.
- Time to first verified fix.
- Whether hook navigation was useful.
- Whether `apply-patch` was useful or bypassed.
- Files created/edited by path.

## Friction Log Format

For each friction point:

- Tool or ability involved.
- What the agent tried.
- What was missing, unclear, or too verbose.
- What Open Mira capability would have reduced the tool calls.

## Decision Gate

After Test 3, choose the next Phase B investment from evidence:

- If hook placement is painful, prioritize `*** Add Hook Callback:`.
- If plugin creation is painful, prioritize `scaffold-plugin`.
- If code search is painful, prioritize a real `search-code` ability or project-map focused search mode.
- If the agent never reaches for `find-hook-callers` / `find-hook-registrants` when hooks are relevant, tune ability discovery/descriptions before adding new patch ops.
- If PHP file edits are fine but verification is weak, prioritize richer `lint-changes` / runtime checks.
- If none of those surfaces friction, pick a real-bug plugin benchmark before expanding theme-specific polish.

## Test 3A Result — Leaflet Map Issue #278

Pilot run: `#runtime/pilot-runs/20260516T094456Z`

Selected issue: Leaflet Map's shortcode attribution handling split HTML entities such as `&copy;` because the front-end JavaScript separates attribution entries on semicolons.

Metrics:

- MCP ability calls: 11
- Schema/tool failures: 0
- Rollbacks: 0
- Syntax/lint failures: 0
- Files edited: `wp-content/plugins/leaflet-map/shortcodes/class.map-shortcode.php`
- Verification: `lint-file` passed; `execute-php` confirmed `&copy; Test` is decoded to `© Test` before JavaScript splitting; screenshot job completed for `/leaflet-attribution-test/`.

Decision signals:

- Hook navigation was irrelevant for this issue; the bug lived in direct shortcode parsing and JavaScript rendering.
- `apply-patch` was not needed; `edit-file` was the right tool for a single exact PHP replacement.
- The highest-value missing capability was code search. The agent needed separate directory reads to find PHP and JS attribution handling; a scoped grep-style search for `attribution` / `attribution.split` would have reduced calls.
- Screenshot worked, but small map attribution text was hard to verify at full-page scale. For plugin UI bugs, a future crop/selector option on `screenshot-url` would be more useful than another full-page capture.

Follow-up shipped from this evidence:

- `openmira/search-code` v1: scoped WordPress code search with literal/regex matching, multi-glob filters, context lines, result bounds, content hashes, and read tracking for matched files.

Next benchmark:

- Rerun this same Leaflet Map issue with `search-code` available. If calls drop and no new search friction appears, run a second plugin benchmark where hooks are actually relevant before prioritizing `*** Add Hook Callback:` or `scaffold-plugin`.

## Test 3B Result — Leaflet Map Rerun With Search Code

Pilot run: `#runtime/pilot-runs/20260516T095845Z`

Outcome: `search-code` was naturally discovered and used.

Metrics:

- MCP ability calls: 9
- Schema/tool failures: 0
- Rollbacks: 0
- Syntax/lint failures: 0
- Files edited: `wp-content/plugins/leaflet-map/shortcodes/class.map-shortcode.php`
- Verification: `lint-file` passed; `execute-php` confirmed the entity split behavior; screenshot job completed for `/leaflet-attribution-test/`.

Delta from Test 3A:

- Calls dropped from 11 to 9.
- Code location improved: one `search-code` call surfaced both `class.map-shortcode.php` and `construct-leaflet-map.js`, including the critical JavaScript `attribution.split(';')` line.
- The agent still read the full PHP file before editing. That is acceptable for now, but suggests a later `line_hint` / focused-read affordance could reduce one more call.

New friction:

- `search-code` returned useful but noisy broad results for `attribution`. Future refinements could add scoped modes such as `scope: plugin`, `kind: assignment|call`, or a value-flow helper for PHP-to-JS paths.
- The agent explicitly requested `include_image: true` when reading the screenshot job, causing a 579 KB base64 response. This repeated the context-bloat class from earlier pilots.

Follow-up shipped from this evidence:

- `read-screenshot-url-job` now caps inline base64 by `inline_image_max_bytes` and refuses large inline screenshots with a structured hint to use `resource_uri`, `image_url`, or the bridge `screenshot_file`.

Next benchmark:

- Use a hook-heavy plugin issue or fallback plugin task. The Leaflet bug did not exercise `find-hook-callers`, `find-hook-registrants`, `*** Add Hook Callback:`, or `scaffold-plugin`, so those should not be prioritized from this evidence alone.

## Test 4 Candidate

Selected next brief: `docs/pilots/test-4-taro-clockwork-post-issue-43.md`

Reason: Taro Clockwork Post issue #43 is small, public, and hook-specific. The likely fix requires adding frontend redirect behavior around WordPress request timing, so it directly tests whether Open Mira's hook navigation and current edit surface are enough before building `*** Add Hook Callback:`.

## Test 4 Result — Taro Clockwork Post Issue #43

Pilot run: `#runtime/pilot-runs/20260516T101031Z`

Outcome: hook authoring worked with the current surface, but logged-out verification did not.

Metrics:

- MCP ability calls: 13
- Schema/tool failures: 1 agent slip (`execute-ability` without an ability name), not a schema problem
- Rollbacks: 0
- Syntax/lint failures: 0
- Files edited: `wp-content/plugins/taro-clockwork-post/includes/setting.php`
- Files created: `wp-content/openmira-sandbox/tscp-expired-redirect.php`
- Verification: `lint-file` passed for both changed PHP files; `execute-php` confirmed option sanitization, plugin activation, and `template_redirect` callback registration; screenshot bridge completed for the expired private post URL.

Decision signals:

- `find-hook-callers` / `find-hook-registrants` were not used. In this case `template_redirect` was obvious from the task, so non-use is not evidence against the navigation abilities.
- `*** Add Hook Callback:` would have saved at most one call. The current `edit-file` / `write-file` surface handled the hook change without meaningful friction.
- `scaffold-plugin` was irrelevant because this was a modify-existing-plugin task.
- `search-code` was unnecessary because the plugin was small enough for directory listing plus targeted reads.
- The concrete missing capability is guest/anonymous verification. `screenshot-url` currently uses the authenticated admin browser path, so it correctly showed the private post for an admin but could not prove the anonymous redirect behavior visually.

Next benchmark/fix:

- Shipped from this evidence: `openmira/probe-url`, a same-site anonymous HTTP probe for logged-out redirects, status checks, headers, and bounded body excerpts.
- Rerun result: `probe-url` was naturally used and confirmed the anonymous redirect path, but the run needed 22 calls because the implementation had to account for private posts resolving as anonymous 404s.
- Keep `*** Add Hook Callback:` deferred until a richer hook-authoring task shows repeated placement or boilerplate friction.
- Do not move directly to plugin creation yet. Run one sharper hook-navigation benchmark first: a hook-priority/conflict task where the agent must inspect existing callbacks and priorities, not simply know the correct hook from WordPress fundamentals.

## Test 5 Result — Hook Navigation Conflict Fixture

Pilot run: `#runtime/pilot-runs/20260516T104429Z`

Outcome: hook navigation was naturally used and reduced callback location to one call.

Metrics:

- MCP ability calls: 8
- Schema/tool failures: 0
- Rollbacks: 0
- Syntax/lint failures: 0
- `find-hook-registrants` used naturally before source reads: yes
- `find-hook-callers` used: no, unnecessary for this registration conflict
- Files edited: `wp-content/plugins/openmira-hook-conflict-fixture/openmira-hook-conflict-fixture.php`
- Verification: `lint-file` passed; screenshot confirmed `Current fixture notice` rendered and `Legacy fixture notice` was absent.

Decision signals:

- `find-hook-registrants` returned exact callback names, priorities, file paths, and line numbers in one response.
- No hook patch grammar was needed for a removal fix; `edit-file` was enough after navigation identified the line.
- `execute-php` produced weak/false-negative checks for callbacks guarded by `is_singular()`, `in_the_loop()`, and `is_main_query()`. Real HTTP/screenshot verification is the right path for loop-conditional hooks.
- This closes the hook-navigation ambiguity from Test 4. Move next to plugin creation/scaffolding unless a later real issue specifically exposes hook placement boilerplate.
