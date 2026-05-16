# Test 6 — Plugin Creation / Scaffolding Benchmark

## Purpose

Determine whether Open Mira needs a dedicated `scaffold-plugin` ability, or whether the current file-editing, sandbox, lint, probe, and screenshot surface is sufficient for creating compact WordPress plugins from scratch.

This benchmark is intentionally not a theme/page benchmark. The output is the friction log and metrics, not a polished product.

## Brief

Build a compact WordPress plugin named **Open Mira Listing Cards**.

The plugin should add a simple real-estate listing workflow:

1. Register a public custom post type `om_listing` named **Listings**.
2. Register a hierarchical taxonomy `om_listing_market` named **Markets** for listings.
3. Add a meta box on listing edit screens with fields:
   - `om_listing_price` text input.
   - `om_listing_beds` number input.
   - `om_listing_featured` checkbox.
4. Save those fields safely on `save_post_om_listing` with nonce and capability checks.
5. Add a shortcode `[openmira_listings]` that renders up to three published listings as cards, including title, price, beds, and market names.
6. Create at least two sample listing posts and assign one market term so the shortcode has visible output.
7. Create or update a page with the shortcode and verify the front-end output.

## Expected Workflow

Use the Open Mira MCP server only.

Required steps:

1. Discover current site/project context.
2. Determine where a new plugin-like PHP file can safely be created.
3. First try the normal WordPress plugin path only if the available abilities make that safe. If the surface blocks new PHP under `wp-content/plugins/`, adapt to the supported Open Mira sandbox path and log that as friction.
4. Use `write-file`, `edit-file`, or `apply-patch` for file changes when possible.
5. Do **not** use `execute-php` to write plugin source files unless no safer first-class file ability supports the needed path; if you do, record it as friction.
6. Run `lint-file` on every PHP file you create or modify.
7. Use `run-wpcli` or a narrow Open Mira ability where available for activation/content setup. If unavailable, use `execute-php` and record why.
8. Verify the shortcode page anonymously with `probe-url`.
9. Create at least one screenshot job for the shortcode page, complete it with the bridge helper, and inspect the result/resource.
10. Stop after the first coherent working result; do not polish.

## Constraints

- Use prefix `omlc_` for functions and meta keys.
- Use text domain `open-mira`.
- Avoid broad options mutations.
- Avoid installing third-party plugins.
- Keep generated plugin/source code compact.
- Do not edit repository files directly.
- Keep all new runtime code inside the WordPress install.

## Success Criteria

- A listing CPT exists and accepts sample posts.
- A market taxonomy exists and is assigned to at least one listing.
- Meta fields are saved and rendered by the shortcode.
- The shortcode page renders listing cards for anonymous visitors.
- PHP lint passes.
- No fatal errors.
- At least one screenshot job completes and is used for visual verification.

## Metrics To Report

- MCP ability calls.
- Schema/tool failures.
- Rollbacks or stale-write retries.
- Syntax/lint failures.
- Time/calls to first valid PHP file.
- Time/calls to first visible front-end output.
- Whether `execute-php` wrote plugin source files.
- Whether the final code lives in `wp-content/plugins/` or `wp-content/openmira-sandbox/`.
- Whether a dedicated `scaffold-plugin` ability would have reduced calls or risk.

## Friction Log Format

For each friction item, include:

- Tool or ability involved.
- What the agent tried.
- What failed or felt inefficient.
- What Open Mira feature would have helped.

## Decision Gate

- If the agent needs many manual file/path/header/bootstrap steps before writing useful plugin logic, prioritize `scaffold-plugin`.
- If the agent must use `execute-php` to create normal plugin files, prioritize either `scaffold-plugin` or a narrowly scoped `create-plugin-file`/`graduate-sandbox-plugin` ability instead of broadening generic `write-file` PHP permissions.
- If sandbox plugin creation works cleanly but normal plugin activation is the main missing piece, prioritize a sandbox-to-plugin promotion workflow.
- If the agent completes the plugin in ≤15 MCP ability calls, 0-1 schema failures, no source-writing through `execute-php`, and no major path confusion, defer `scaffold-plugin`.
- If shortcode verification requires multiple screenshot/probe retries because anonymous output is hard to inspect, improve verification/resource hints before adding more creation tools.

## Result

Pilot run: `#runtime/pilot-runs/20260516T105345Z`

Outcome: plugin creation from scratch worked through the existing sandbox workflow. The agent naturally avoided unsafe plugin-directory PHP writes, created compact plugin code in `wp-content/openmira-sandbox/`, linted it, seeded sample content, created a shortcode page, and verified output visually.

Metrics:

- MCP ability calls: 12
- Schema/tool failures: 1 (`get-project-map` rejected `sandbox` as a field)
- WP-CLI allowlist rejections: 1 (`term create` blocked)
- Rollbacks/stale-write retries: 0
- Syntax/lint failures: 0
- Calls to first valid PHP file: 4
- Calls to first visible front-end output: 9
- `execute-php` wrote plugin source files: no
- Final code location: `wp-content/openmira-sandbox/open-mira-listing-cards.php`
- Final page URL: `http://127.0.0.1:9400/listing-cards/`

Verification:

- `lint-file` passed on the generated sandbox PHP file.
- `probe-url` returned anonymous `200` for `/listing-cards/`, but body content was hidden behind large block-theme inline CSS.
- Screenshot bridge completed in one pass and showed both listing cards:
  - `42 Maple Street` with `$485,000`, `3` beds, and `Downtown` market.
  - `7 Lakeside Drive` with `$320,000` and `2` beds.

Friction:

1. New PHP source is intentionally sandbox-only through `write-file`, so a real plugin directory still needs a promotion path.
2. `get-project-map` should accept `sandbox` as an alias for `writable_locations`.
3. `run-wpcli` should allow narrow content setup commands (`term create`, `post create`, `post meta`, `post term`).
4. `probe-url` needs body-aware excerpts/search for block themes with large inline CSS.

Follow-up shipped after the pilot:

- `probe-url` now supports `body_only=true` and `body_search` for body-content checks without screenshot fallback.
- `get-project-map` now accepts `sandbox` and normalizes it to `writable_locations`.
- `run-wpcli` now allowlists narrow content setup signatures: `term create`, `post create`, `post meta`, and `post term`.

Decision:

- Defer full `scaffold-plugin` for now. The current sandbox path completed the benchmark under the threshold.
- Prioritize a smaller `graduate-sandbox-plugin` or promotion workflow later, because the real missing piece is moving proven sandbox code into `wp-content/plugins/<slug>/` with audit and activation semantics.
- Keep probing the plugin lane with extension/modification benchmarks before adding broader plugin scaffolding.
