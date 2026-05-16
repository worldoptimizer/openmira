# Open Mira Test 5 — Hook Navigation Conflict Fixture

## Purpose

Stress Open Mira's WordPress hook-navigation abilities in a plugin workflow where the correct fix depends on inspecting existing callbacks and priorities.

This is intentionally a controlled fixture, not a real GitHub issue. Test 4 proved hook authoring can be trivial when the hook is obvious; this pilot must test whether agents naturally use `find-hook-registrants` / `find-hook-callers` when the task is explicitly a hook conflict.

## Selected Plugin

- Plugin: Open Mira Hook Conflict Fixture
- Installed path in Playground: `wp-content/plugins/openmira-hook-conflict-fixture`
- Main plugin file: `wp-content/plugins/openmira-hook-conflict-fixture/openmira-hook-conflict-fixture.php`
- Bug page slug: `hook-conflict-fixture-test`

## Bug Brief

A single post shows two fixture notices before the content:

- `Legacy fixture notice`
- `Current fixture notice`

Only the current notice should render. The legacy notice is still attached to `the_content` at a different priority.

Fix the plugin so the legacy notice no longer renders, while the current notice still renders. Do not hardcode a content replacement in the post. Fix the hook registration or callback behavior.

## Required Workflow

Use Open Mira MCP abilities for all WordPress/plugin reads and writes. Do not use local shell to inspect or edit plugin source.

1. Confirm the fixture plugin is installed and active.
2. Inspect what is currently registered to `the_content` using `find-hook-registrants` before reading broad source files.
3. Use `find-hook-callers` if you need to understand where `the_content` is fired.
4. Locate the fixture callback source and priority.
5. Apply a minimal fix that removes or disables the legacy callback while preserving the current callback.
6. Verify changed PHP with `lint-file`.
7. Verify frontend output with `probe-url` and/or screenshot:
   - page must contain `Current fixture notice`;
   - page must not contain `Legacy fixture notice`.
8. Return the diff and friction log.

## Budget

- Stop after 20 MCP ability calls or 12 minutes wall time, whichever comes first.
- If not fixed inside the budget, return the friction log and best partial diagnosis.

## Success Criteria

- Fixture plugin remains active or activatable with no PHP fatal.
- Changed PHP passes syntax validation.
- `find-hook-registrants` is used naturally before broad source reads, or the final report explains why it was skipped.
- The final frontend check proves the legacy notice is absent and the current notice remains.
- No unrelated plugin files or WordPress content are modified.

## Metrics To Report

- MCP ability-call count.
- Schema/tool failures.
- Rollbacks.
- Syntax/lint failures.
- Time to locate the relevant callback.
- Time to first verified fix.
- Whether `find-hook-registrants` helped.
- Whether `find-hook-callers` helped or was unnecessary.
- Whether `*** Add Hook Callback:` would have reduced calls.
- Files created/edited by path.

## Decision Gate

After this pilot:

- If `find-hook-registrants` is naturally used and reduces location time, keep hook navigation as shipped and only tune descriptions.
- If hook navigation is skipped despite this brief, fix discovery/descriptions before building hook patch grammar.
- If hook navigation is useful but placement/removal is painful, prioritize `*** Add Hook Callback:` or a narrower `*** Update Hook Registration:` patch op.
- If current file editing is enough and the fix is under budget, defer hook patch grammar again and move to plugin creation/scaffolding.

## Result

Pilot run: `#runtime/pilot-runs/20260516T104429Z`

Outcome: hook navigation was naturally discovered and useful. `find-hook-registrants` located both callbacks, priorities, and the source file before any broad file read.

Metrics:

- MCP ability calls: 8
- Schema/tool failures: 0
- Rollbacks: 0
- Syntax/lint failures: 0
- Time to locate relevant callback: 1 call after discovery
- Time to first verified fix: 5 calls
- `find-hook-registrants` used: yes, before source reads
- `find-hook-callers` used: no, unnecessary for a registration conflict
- `search-code` used: no
- `apply-patch` used: no
- Files edited: `wp-content/plugins/openmira-hook-conflict-fixture/openmira-hook-conflict-fixture.php`

Diff:

```diff
 function omhcf_register_hooks(): void {
-	add_filter( 'the_content', 'omhcf_legacy_notice', 8 );
 	add_filter( 'the_content', 'omhcf_current_notice', 12 );
 }
```

Verification:

- `lint-file` passed on the edited plugin file.
- Screenshot confirmed `Current fixture notice` rendered and `Legacy fixture notice` was absent.
- `probe-url` body excerpt was too short to reach the content body because the theme emitted large inline CSS first; screenshot was the reliable verification path for loop-conditional content filters.

Friction:

1. `execute-php` is weak for callbacks guarded by `is_singular()`, `in_the_loop()`, and `is_main_query()` because those conditions are request/loop-context dependent.
2. `probe-url` can verify redirects/status/body snippets, but body excerpts may miss late body content on block themes with large inline CSS.

Decision:

- Keep hook navigation as shipped.
- Defer `*** Add Hook Callback:` for now. This pilot was a removal fix, and `edit-file` was sufficient once hook navigation identified the line.
- Move next to plugin creation/scaffolding. The remaining untested plugin lane is creating a compact plugin from scratch, not navigating or editing an existing hook conflict.
