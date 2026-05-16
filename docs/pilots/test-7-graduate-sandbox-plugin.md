# Test 7 — Graduate Sandbox Plugin

## Purpose

Validate `openmira/graduate-sandbox-plugin` as the evidence-backed promotion path from a verified sandbox plugin into a real WordPress plugin directory.

This is not a plugin-authoring test. The sandbox plugin already exists from Test 6. The question is whether an external MCP agent can naturally promote it, handle activation deferral safely, and verify the result.

## Brief

Promote the sandbox plugin **Open Mira Listing Cards** into a real WordPress plugin.

Source sandbox file:

`wp-content/openmira-sandbox/open-mira-listing-cards.php`

Expected real plugin file:

`wp-content/plugins/open-mira-listing-cards/open-mira-listing-cards.php`

After promotion, verify the shortcode page still works:

`http://127.0.0.1:9400/listing-cards/`

## Expected Workflow

Use the Open Mira MCP server only.

Required steps:

1. Discover available abilities and project context.
2. Use `openmira/graduate-sandbox-plugin` for the promotion. Do not copy files with `execute-php`.
3. If activation is deferred because the sandbox file was loaded earlier in the request, follow the ability's next-step hint and activate on a fresh request.
4. Verify the plugin is active or explain the activation state from ability output.
5. Verify the shortcode page with `probe-url` using `body_search` for `omlc-card`.
6. Create one screenshot job for the shortcode page, complete it with the bridge helper, and inspect the result/resource.
7. Stop after the first coherent verified result.

## Constraints

- Do not edit repository files directly.
- Do not use `execute-php` to copy, move, or activate the plugin unless `graduate-sandbox-plugin` fails and no first-class ability can proceed.
- Do not polish the plugin code.
- Preserve the sandbox source by disabling it, not deleting it.

## Success Criteria

- Real plugin file exists at `wp-content/plugins/open-mira-listing-cards/open-mira-listing-cards.php`.
- Sandbox source is disabled after promotion.
- Plugin is active, or activation is explicitly deferred with a correct next-step explanation.
- Shortcode page still renders listing cards.
- No PHP syntax failures or fatal errors.
- Screenshot job completes.

## Metrics To Report

- MCP ability calls.
- Schema/tool failures.
- Rollbacks/stale-write retries.
- Syntax/lint failures.
- Whether `graduate-sandbox-plugin` was naturally discovered.
- Whether activation required one or two calls.
- Whether `execute-php` was used for file copy or activation.
- Whether `probe-url body_search` avoided screenshot-only functional verification.

## Friction Log Format

For each friction item, include:

- Tool or ability involved.
- What the agent tried.
- What failed or felt inefficient.
- What Open Mira feature would have helped.

## Decision Gate

- If the agent naturally uses `graduate-sandbox-plugin`, handles activation deferral, and verifies in ≤8 MCP ability calls with 0-1 failures, the promotion primitive is validated.
- If the agent misses the activation deferral hint, improve `graduate-sandbox-plugin` response shape and discovery text.
- If activation requires `execute-php`, add a narrower activation follow-up ability or improve the promotion ability.
- If body verification still falls back to screenshot only, improve `probe-url` discovery/response examples.

## Result

Pilot run: `#runtime/pilot-runs/20260516T111129Z`

Summary:

- `graduate-sandbox-plugin` was naturally discovered and used as the first-class promotion path.
- Real plugin activation succeeded for `open-mira-listing-cards/open-mira-listing-cards.php`.
- `probe-url` with `body_search: omlc-card` verified the shortcode output without requiring screenshot-only functional verification.
- Screenshot bridge completed and confirmed four listing cards rendered.
- No PHP syntax failures, stale-write retries, or rollbacks occurred.

Metrics:

| Metric | Value |
| --- | --- |
| Total MCP ability calls | 21 |
| Core promotion calls excluding ghost diagnostics | 12 |
| Schema/tool failures | 1 (`search-code` MCP connection closed on broad `wp-content` search) |
| Rollbacks / stale-write retries | 0 |
| Syntax/lint failures | 0 |
| `graduate-sandbox-plugin` natural discovery | yes |
| `execute-php` for file copy or activation | no |
| `probe-url body_search` verified output | yes |

Friction:

- Playground retained ghost filesystem entries for the destination plugin directory and sandbox `.disabled` path from prior pilot state. The ability promoted and activated successfully after diagnostics/workarounds, but it needs clearer ghost-entry diagnostics.
- `openmira_graduate_disable_source()` used `file_exists()` for the `.disabled` guard; this is too broad for overlay/virtual filesystem ghosts. Use `is_file()` and return structured diagnostics on rename failure.
- The brief assumed the active sandbox artifact from Test 6 still existed. Future reruns should reset pilot state or accept an already disabled sandbox source explicitly.
- Broad `search-code` over `wp-content` closed the MCP connection. Future search hardening should cap broad scans or ask for a narrower path/glob.

Follow-up hardening:

- `graduate-sandbox-plugin` now clears stat cache around destination directory creation and returns structured path diagnostics when directory creation fails.
- Disabled-source checks now guard with `is_file()` instead of broad `file_exists()`, and rename failures include path diagnostics plus an actionable hint.
- Runtime smoke `#runtime/graduate-smoke-20260516T112927Z` used a fresh plugin slug and validated the clean two-call flow: first call copied and disabled the sandbox source with activation deferred; second call activated the promoted plugin; shortcode output rendered.
