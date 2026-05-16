# Open Mira Test 4 — Hook-Heavy Plugin Bugfix: Taro Clockwork Post Issue #43

## Purpose

Validate Open Mira's plugin-lane support when the likely fix requires WordPress hook tracing or hook authoring.

The deliverable is the friction log and minimal diff quality. Stop at the first verified fix or the budget limit.

## Selected Plugin

- Plugin: Taro Clockwork Post
- Repository: https://github.com/tarosky/taro-clockwork-post
- Installed path in Playground: `wp-content/plugins/taro-clockwork-post`
- Main plugin file: `wp-content/plugins/taro-clockwork-post/taro-clockwork-post.php`
- GitHub issue: https://github.com/tarosky/taro-clockwork-post/issues/43
- Issue title: `[WP Audit] 期限切れ投稿へのアクセス時リダイレクト・訪問者向けUXの欠如`

Issue summary: expired posts are changed to `private`, but anonymous visitors who open an expired bookmarked URL only see the default login/404 behavior. The issue asks for a configurable visitor redirect target and frontend redirect behavior for expired/private posts.

## Required Workflow

Use Open Mira MCP abilities for WordPress/plugin reads and writes. Do not use local shell to inspect or edit plugin source, except for the screenshot bridge if a screenshot job is created.

1. Confirm the plugin is installed and active.
2. Read the plugin structure through Open Mira abilities.
3. Use hook-aware abilities where relevant:
   - `find-hook-registrants` for likely plugin boot/settings hooks.
   - `find-hook-callers` if deciding where the redirect hook should run.
4. Locate existing settings and expiration-status logic.
5. Implement a minimal fix that:
   - Adds a Reading settings field for an expired-post redirect URL.
   - Sanitizes/stores that option.
   - Registers a frontend hook that redirects anonymous visitors away from expired/private singular content when the option is configured.
6. Verify changed PHP with `lint-file`.
7. Run a code-level runtime check with `execute-php` to confirm:
   - the option sanitization behaves as expected;
   - the redirect callback exists or the hook is registered;
   - the plugin remains active.
8. If practical, create an expired/private post and screenshot the behavior. If visual verification is not practical within budget, explain why and rely on code-level verification.

## Budget

- Stop after 25 MCP ability calls or 15 minutes wall time, whichever comes first.
- If the bug is not fixed inside the budget, return the friction log and best partial diagnosis.
- Do not broaden into unrelated settings, cron, or block-editor behavior.

## Success Criteria

- Plugin remains active or activatable without PHP fatal.
- Changed PHP passes syntax validation.
- Fix is minimal and scoped to expired-post visitor redirect behavior.
- No unrelated plugin files or bundled dependencies are modified.
- The final report states whether hook navigation helped, was irrelevant, or was not discoverable.
- The final report states whether `*** Add Hook Callback:` or `scaffold-plugin` would have reduced calls.

## Metrics To Report

- MCP ability-call count.
- Schema/tool failures.
- Rollbacks.
- Syntax/lint failures.
- Time to locate relevant files.
- Time to first verified hook-based fix.
- Whether `find-hook-callers` / `find-hook-registrants` was used and whether it helped.
- Whether `search-code` was useful or too noisy.
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

- If hook placement or hook registration is painful, prioritize `*** Add Hook Callback:`.
- If hook navigation is not naturally used when relevant, tune discovery/descriptions before adding patch ops.
- If code location is still noisy, refine `search-code` ranking or add focused search modes.
- If plugin creation/setup is painful, prioritize `scaffold-plugin`; otherwise keep it deferred.
- If verification is weak, prioritize richer runtime checks or browser/DOM inspection.

## Result

Pilot run: `#runtime/pilot-runs/20260516T101031Z`

Outcome: Open Mira fixed and verified the hook-heavy plugin behavior inside the Playground, but the visual proof was limited to the authenticated admin path.

Metrics:

- MCP ability calls: 13
- Schema/tool failures: 1 agent slip (`execute-ability` called without an ability name)
- Rollbacks: 0
- Syntax/lint failures: 0
- Time to locate relevant files: about 2 calls after the initial map/listing
- Time to first verified hook-based fix: call 8
- `find-hook-registrants` used: no
- `find-hook-callers` used: no
- `search-code` used: no
- `apply-patch` used: no
- Files edited: `wp-content/plugins/taro-clockwork-post/includes/setting.php`
- Files created: `wp-content/openmira-sandbox/tscp-expired-redirect.php`

Implemented behavior:

- Added a Reading settings option for an expired-post redirect URL.
- Sanitized the option with `esc_url_raw`.
- Added a `template_redirect` callback for anonymous visitor redirects.
- Verified the plugin stayed active.
- Verified the callback registration through runtime inspection.
- Created an expired/private test post and completed a screenshot job for its URL.

Friction:

1. `execute-php` verification initially checked settings globals without firing `admin_init`, so the registered setting looked absent. A targeted hint for testing `admin_init` / `init` registrations would avoid this false-negative path.
2. `screenshot-url` runs through an authenticated admin browser session. That is correct for protected admin workflows, but it cannot verify anonymous-only redirect behavior.
3. New PHP files still need the sandbox path, while existing plugin PHP can be edited directly. This was clear enough and did not block the pilot.

Decision:

- Do not prioritize `*** Add Hook Callback:` from this pilot alone. Hook placement was not painful; the hook target was obvious and current file-editing abilities were sufficient.
- Do not prioritize `scaffold-plugin`; the task modified an existing plugin.
- Shipped from this evidence: `openmira/probe-url`, a same-site anonymous HTTP probe that reports status, headers, redirect location, and a bounded body excerpt without admin cookies.
- Smoke validation: probing `/` returned anonymous `200`; probing `/wp-admin/` returned anonymous `302` with a login redirect location, confirming the ability can detect logged-out redirects in Playground without triggering auto-login cookies.
- Rerun this benchmark with `probe-url` available. Only add `screenshot-url` guest mode if HTTP probing is insufficient for logged-out UX validation.

## Rerun Result — With `probe-url`

Pilot run: `#runtime/pilot-runs/20260516T103355Z`

Outcome: `probe-url` was naturally discovered and was the decisive verification tool. The rerun also confirmed this issue is hook-authoring-trivial, not hook-navigation-heavy.

Metrics:

- MCP ability calls: 22
- Schema/tool failures: 2
- Rollbacks: 0
- Syntax/lint failures: 0
- File writes/revisions: 3 total across the retained settings edit and two sandbox redirect revisions
- Time to first redirect-working probe: call 22
- `find-hook-registrants` used: no
- `find-hook-callers` used: no
- `search-code` used: no
- `apply-patch` used: no

What changed from the first run:

- `probe-url` verified the anonymous path directly: the final private-post URL returned `302` to `http://127.0.0.1:9400/`.
- The first redirect implementation failed because WordPress serves private posts as anonymous `404`, so `is_singular()` never became true for logged-out visitors.
- A second attempt using `url_to_postid( home_url( $wp->request ) )` also failed in the live HTTP request.
- The working implementation used `$wp_query->query_vars['name']` and queried private posts by slug inside the `is_404()` branch.

Friction:

1. The agent first called `execute-ability` with `ability_name: "execute-php"` instead of `openmira/execute-php`. This should be tracked as a dispatcher/discovery hint issue.
2. Manual `$wp_filter` dumping in `execute-php` caused a `WP_Sitemaps` object-to-string fatal. Agents should be steered toward `find-hook-registrants` for runtime hook inspection.
3. The private-post-as-404 behavior was the real WordPress nuance. `probe-url` exposed it immediately; a hint in `probe-url` and hook-navigation discovery should reduce this class.

Decision:

- `probe-url` is validated.
- This still does not prove or disprove `*** Add Hook Callback:`. The hook target was known from WordPress fundamentals, and the harder part was WordPress request behavior for private posts.
- Run one sharper hook-navigation benchmark before deciding whether hook patch grammar is worth building.
