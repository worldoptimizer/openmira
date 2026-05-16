# Open Mira TODO

## Current Status

- **Done:** Validation phase is complete. Eighteen external-client pilots validated or refuted each flagship positioning claim: theme/page work, `theme.json` patch grammar, plugin bug-fix, hook authoring, hook navigation, sandbox plugin creation/promotion, and vision-based design intake are validated; hook-callback patch grammar is not justified for v1.
- **Now:** Productization Phase A is active. Priority shifts from exploratory feature pilots to protecting the validated surface with integration smoke coverage and safety hardening: broad-scan guardrails, audit-log diff expansion, per-ability capability filters, production hard-blocks, and runaway protection.
- **Done:** `openmira/get-project-map` v1 is implemented and smoke-tested in Playground. It returns live site/theme/plugin/build-tool/writable-location/rules/file inventory context, prunes heavy directories during scans, defaults to a bounded payload, supports `fields` filtering, and exposes a summary Resource.
- **Partial:** Project-map v1 does not yet include a persistent symbol graph or PageRank focus weighting. Hook/template navigation now exists as dedicated abilities rather than inside the map.
- **Done:** Safe-edit v1 is implemented for file operations: content hashes, unified diffs, ring-buffer backups, restore ability, read tracking, audit storage, audit admin page, and default-on Plan/Act gating.
- **Partial:** Stale-write protection is enforced for existing `write-file`, `edit-file`, and file delete operations. Diff bodies are returned by abilities but the admin audit view currently shows summaries, not full diff expansion.
- **Done:** `openmira/scaffold-theme` v1 creates real block, classic, and child themes. Playground smoke test created and activated a block theme, then verified front-end rendering in the browser.
- **Done:** `.openmirarules.json` v1 is implemented with read/write abilities, memory overlay for `rules.*` keys, project-map integration, and scaffold-theme consumption of `text_domain` / `preferred_theme_type`.
- **Done:** `openmira/scaffold-block` v1 creates PHP-rendered dynamic blocks inside the active theme, writes `block.json` / render / styles / no-build editor script, wires `functions.php`, and was verified on a published page in Playground.
- **Done:** `find-hook-callers`, `find-hook-registrants`, and `resolve-template` v1 are implemented and smoke-tested in Playground. Hook scanning uses `nikic/php-parser`; registrants combine static AST matches with runtime `$wp_filter`; template resolution handles classic and block theme hierarchy.
- **Done:** Preview loop v1 is implemented: PHP syntax validation rolls back failed PHP writes, ability responses include new debug-log/fatal diagnostics, `lint-file` runs syntax and PHPCS when available, and `run-wpcli` exposes a narrow allowlist.
- **Done:** Browser-assisted screenshot loop v1 is implemented: `screenshot-url` creates same-site viewport jobs, browser clients complete them with PNG/JPEG bytes, reads return a protected image URL/resource URI by default, and inline base64 is explicit opt-in only.
- **Done:** Test 1 discovery pilot ran through Claude Code as an external MCP client using `scripts/run-pilot.sh`. Pilot #5 reached the decision gate: 13 total calls, 0 failures, 0 rollbacks, 0 syntax/lint failures, and a completed screenshot loop.
- **Done:** Phase B WP-aware patch grammar started with `openmira/apply-patch` and the first semantic op: `*** Update theme.json (path: …):`.
- **Done:** Test 2 complex-design benchmark ran through Claude Code as an external MCP client. Result: coherent first render, 21 calls, 3 failures, 0 rollbacks, 0 syntax/lint failures, 1 screenshot job, and `apply-patch` used for `settings.color.palette`.
- **Done:** Pilot #8's `apply-patch` theme-registry/path blocker was fixed with explicit `path` targeting, filesystem fallback, and scaffold-time theme cache refresh. Targeted smokes confirmed both explicit-path and fresh `theme_slug` patching.
- **Done:** Pilot #9 reran the complex-design benchmark cleanly: 14 total calls, 0 schema/tool failures, 0 rollbacks, 0 syntax/lint failures, one completed screenshot loop, and `apply-patch` succeeded through `theme_slug`.
- **Done:** Pilot #10 validated the clean benchmark path: required slug honored with `force_clean`, bulk `theme.json` patch applied 12 paths in one call, 18 total calls, 1 agent-slip failure, 0 rollbacks, 0 syntax/lint failures, and one completed screenshot loop.
- **Done:** Pilot #10 immediate friction fixes landed: `apply-patch` supports `include_diff=false` with `diff_summary`, and stale-write errors now explicitly suggest `expected_current_hash` from scaffold/read outputs.
- **Done:** Pilot #11 reran the complex benchmark without explicit bulk-patch instructions. Result: coherent page, 17 total calls, 2 stale-write failures, 0 rollbacks, 0 syntax/lint failures, one completed screenshot loop, and no natural `apply-patch` usage.
- **Done:** Pilot #12 reran the natural complex benchmark after description/discovery compression. Result: 15 MCP calls, 1 stale-write failure, 0 rollbacks, 0 syntax/lint failures, one completed screenshot loop, and natural bulk `apply-patch` use for `theme.json`.
- **Done:** Pilot #12 immediate hash friction fix landed: `scaffold-theme` now returns `next_write_hints` with top-level `expected_current_hash` values for `theme.json`, theme CSS, and `style.css`.
- **Done:** Resource infrastructure is now real, not decorative: completed screenshot jobs register exact MCP image resources, project-map summary is a JSON resource, and memory snapshot is a JSON resource. Playground MCP smoke confirmed `resources/list` and `resources/read` for all three paths.
- **Done:** Pilot #12 Gutenberg pattern reliability fixes landed: testimonial sections now honor background/text colors, and feature grids preserve item `number` / `label` / `accent_color` metadata.
- **Done:** Pilot #13 reran the complex-design benchmark with Resources enabled. Result: 15 MCP ability calls, 0 schema/tool failures, 0 rollbacks, 0 syntax/lint failures, one completed screenshot job, no `include_image: true`, and successful `resources/read` of the screenshot image resource.
- **Done:** Pilot #13 quick wins landed: `scaffold-theme` can include a blank landing-page template, `render-gutenberg-pattern` supports a native split/two-column brief section, and feature-grid numbers have explicit `number_color` / primary-color behavior.
- **Done:** Test 3A ran outside the theme/page lane against Leaflet Map issue #278. Result: 11 MCP ability calls, 0 schema/tool failures, 0 rollbacks, 0 syntax/lint failures, one plugin PHP edit, code-level verification, and a completed screenshot job.
- **Done:** Test 3A evidence-backed friction fix landed: `openmira/search-code` v1 searches scoped WordPress code paths with literal/regex matching, multi-glob filters, context lines, result bounds, content hashes, and read tracking for matched files.
- **Done:** Test 3B reran the Leaflet Map benchmark with `search-code` available. Result: natural `search-code` use, 9 MCP ability calls, 0 failures, 0 rollbacks, 0 syntax/lint failures, and faster root-cause location across PHP and JS.
- **Done:** Test 3B screenshot friction fix landed: `read-screenshot-url-job` now caps inline base64 by `inline_image_max_bytes` so large screenshots return resource/image URLs instead of blowing client context.
- **Done:** Test 4 ran a hook-heavy plugin benchmark against Taro Clockwork Post issue #43. Result: 13 MCP ability calls, 1 agent-slip failure, 0 rollbacks, 0 syntax/lint failures, a verified `template_redirect` hook registration, and one completed screenshot bridge.
- **Done:** Test 4 evidence-backed verification fix landed: `openmira/probe-url` probes same-site URLs as an anonymous HTTP visitor and reports status, headers, redirect location, and a bounded body excerpt.
- **Done:** Test 4 reran with `probe-url`. Result: anonymous redirect verification worked, but the run still did not stress hook navigation; the hard part was WordPress private-post-as-404 behavior, not finding or placing `template_redirect`.
- **Done:** Test 5 ran a controlled hook-navigation conflict fixture. Result: 8 MCP calls, 0 failures, `find-hook-registrants` used naturally before source reads, exact callbacks/priorities/line numbers found in one call, and a screenshot-verified fix.
- **Done:** Test 6 ran the plugin-creation/scaffolding benchmark. Result: 12 MCP calls, 1 schema/tool failure, 1 WP-CLI allowlist rejection, 0 rollbacks, 0 lint failures, no source writes through `execute-php`, and a working sandbox plugin/page verified by screenshot.
- **Done:** Test 6 friction fixes landed: `probe-url` body-aware excerpts/search, `get-project-map` `sandbox` alias, and narrow WP-CLI content setup signatures (`term create`, `post create`, `post meta`, `post term`).
- **Done:** Negative evidence recorded: hook navigation is a moat, but `*** Add Hook Callback:` is not justified for v1. Across plugin pilots, `edit-file` was sufficient once WP-aware navigation found the target.
- **Done:** `openmira/graduate-sandbox-plugin` shipped as the narrower evidence-backed plugin primitive: promote verified sandbox PHP into `wp-content/plugins/<slug>/`, disable the sandbox source, lint, audit, back up existing targets, and defer activation when duplicate-load risk exists.
- **Done:** Test 7 ran against `graduate-sandbox-plugin`. Result: the agent naturally discovered the promotion ability, real plugin activation succeeded, `probe-url body_search` verified output, and screenshot confirmed four listing cards. The run was noisy because Playground retained ghost filesystem entries from prior pilot state.
- **Done:** Test 7 hardening landed and smoke-tested in Playground on a fresh plugin slug: destination directory diagnostics are structured, disabled-source checks avoid `file_exists()` ghosts, first promotion deferred activation, second promotion activated, and shortcode output rendered.
- **Done:** Test 8 ran the vision-intake benchmark. Result: 14 MCP calls, 1 schema failure, 0 rollbacks, 3 screenshot jobs, no reference-source reads, one improvement pass after viewing build output, final target page returned 200 OK, and the visual hierarchy matched the reference.
- **Done:** Test 8 friction fixes landed: screenshot inline-size requests now clamp instead of schema-failing, and feature-grid rendering skips intentionally blank heading/body blocks.
- **Done:** Productization hardening started with `search-code` broad-scan protection. Ambiguous roots now refuse by default, explicit broad scans are capped before content reads, and live Playground REST validation confirmed `search_scope_too_broad` / `too_many_candidate_files` recovery paths.
- **Partial:** `npm run smoke:wp-env:all` is wired to a real REST-based wp-env smoke runner and GitHub Actions CI. The runner mounts the plugin at a deterministic `wp-content/plugins/openmira` path, starts wp-env, activates Open Mira, logs into WordPress, calls abilities over REST, asserts scaffolded theme files, then runs existing eval-file smokes. Local execution is blocked until Docker is installed; CI should exercise the dormant wp-env suite on push/PR.
- **Done:** Phase A safety controls now include `OPENMIRA_BLOCK_PRODUCTION` and `openmira_ability_capability`. Ability permission callbacks capture the ability name, production blocking returns a structured `WP_Error`, resources use a bool-only permission wrapper, and smoke coverage verifies default allow, per-ability deny, and production block paths.
- **Done:** `execute-php` runaway protection is implemented. Calls are rate-limited per user/window, responses include guard state and memory delta, large per-call memory growth is flagged as a structured guard error, and Playground REST smoke confirms the guard metadata path.
- **Now:** Validate the first GitHub Actions wp-env run when pushed, then continue Productization Phase A with audit-log diff expansion.

## Positioning

Open Mira is **Cursor/Windsurf for WordPress** — an IDE-class AI development environment for real WordPress codebases: themes (classic, child, block), plugins, blocks, templates, theme.json, patterns, CSS, JS, PHP, content. Generic AI coding harnesses can grep a WP repo; they don't *understand* it. Open Mira does, and exposes that understanding over MCP so any client (Claude Code, Cursor, Zed, Codex CLI, Windsurf) becomes WP-aware on connect.

Three principles drive every item below:

1. **WordPress-native navigation, not generic file IO.** Hooks, templates, blocks, and theme.json paths are first-class addresses — equivalents of LSP's "go to definition." Generic harnesses can grep; Open Mira resolves.
2. **Patch grammar with WP semantics, not raw diffs.** `*** Update theme.json` proved useful, including bulk path merges. Hook callback patch grammar did **not** earn its place in pilots: hook navigation matters, but plain `edit-file` is sufficient for v1 once Open Mira identifies the right callback line.
3. **Plan / Act separation for destructive operations.** WordPress changes touch live data and live users. The model proposes; a confirmed Act phase performs. More valuable in WP than in greenfield code.

## Default MCP Surface

Target ≤7 directly exposed tools. Default set:

- `get-project-map` — WP-aware repo map. Default output is bounded; summary Resource is shipped.
- `read-file`, `write-file`, `edit-file` — Claude Code semantics: read-before-edit, unique-anchor replace, unified diff returned in the response body.
- `search-code` — shipped v1 grep-style scoped search with literal/regex matching, multi-glob filters, context lines, hashes, and read tracking. Later: symbol-graph traversal for hook callsites and block lookups.
- `apply-patch` — WP-aware patch grammar (see Safe Code Editing).
- `execute-php` — primary dispatcher and escape hatch, audited and rate-limited.
- `memory` — persistent project facts and `.openmirarules` overlay.

Everything else — theme/block/plugin scaffolding, hook navigators, template resolvers, WP-CLI bridge, builder context, Gutenberg recipes — lives behind `search-abilities` + `execute-php` or in optional toolsets the admin enables. Adopt GitHub's toolsets vocabulary verbatim (`list_available_toolsets`, `enable_toolset`, `get_toolset_tools`); scope per application password so different agents on the same site see different surfaces.

## Project Map And Code Navigation

The foundation. Build first — every later workflow needs it.

- `get-project-map` returns: active theme (classic/block), parent/child relationship, plugins, mu-plugins, sandbox plugins, detected build tooling (`@wordpress/scripts`, Composer, npm/yarn scripts), file inventory grouped by role (theme files, templates, parts, patterns, theme.json, block.json files), writable locations, PHP / WP / locale / multisite metadata.
- Implementation: start with `nikic/php-parser` for PHP hook edges, then graduate to a cached symbol graph keyed on WordPress-specific edges — hook fired (`do_action`, `apply_filters`) and consumed (`add_action`, `add_filter`), template-part inclusion (`get_template_part`, `<!-- wp:template-part -->`), block registered (`register_block_type`) and used, theme.json paths referenced. **Personalize PageRank against the file the user is currently editing** (Aider's pattern, 50× multiplier for in-focus files). Rebuild on demand; **no embedding index in v1** — WP projects are small enough that AST + a graph algorithm beats embeddings on cost and accuracy.
- `find-hook-callers(hook)` — returns files and line numbers that fire a hook. V1 shipped with static PHP AST scan.
- `find-hook-registrants(hook)` — returns callbacks attached to a hook, with priority and source (core/theme/plugin/mu-plugin/sandbox). V1 shipped with static AST scan plus runtime `$wp_filter`.
- `resolve-template(post_type | url | route)` — full WordPress template hierarchy resolver, returns the template file that would render with the fallback chain. V1 shipped for URL/post/post-type contexts across classic and block themes.
- `find-block(name | namespace)` — locates `block.json`, render callback, scripts, styles.
- `describe-theme-json(path)` — traverses a `styles.color.primary`-style path with inheritance resolved.
- Expose results as Resources when the response is data; as Tools when the agent provides a query.

## Safe Code Editing

- **Baseline edit semantics (Claude Code-style):** `read-file` first, `edit-file` with unique `old_string`/`new_string` anchors or `replace_all`, `write-file` for new files. Every successful destructive operation returns a unified diff in the response body. Stale-buffer protection now rejects existing file edits/writes/deletes when the file was not read or changed since read.
- **WP-aware patch grammar (v2, the moat):** a V4A-style patch dialect with WordPress operations layered on generic file ops.
  - `*** Register Block: my-plugin/hero` — generates `block.json`, scaffolds render callback, wires `register_block_type`.
  - `*** Update theme.json (path: styles.color.primary):` — surgical edits to nested theme.json paths, schema-validated.
  - `*** Add Hook Callback: save_post (priority: 10):` — inserts a hook registration in the right file with namespace + text-domain awareness.
  - `*** Add Pattern: my-theme/hero-section`
  - `*** Create Template: single-product.php`
  - `*** Update File:` — fallback for generic edits.
  The server handles boilerplate (block.json schema, hook wiring, prefix/namespace conventions from `.openmirarules`); the model emits intent.
- **Diff preview is non-negotiable.** Every destructive write returns a unified diff the audit log and clients render verbatim.
- **Ring-buffer backups** of prior N versions per file, separate from git (which agents may not have available). Restore is a single ability.
- **Audit log** of every edit: timestamp, user, ability, target path, diff summary, status, duration, error. Admin-only view, filter for destructive-only.
- **Plan / Act mode.** A session flag that gates `write-file`, `edit-file`, `delete-file`, `execute-php`, `run-wpcli` (writes), and patch-grammar operations. Plan mode permits reads only. Cline's pattern; pays off more here than in greenfield because of the live-data risk.

## Preview And Verification Loop

The closing of the edit → test → fix cycle. Cap auto-fix at 2–3 iterations; never silently loop.

- `run-wpcli` — gated WP-CLI bridge with command allowlist (`wp plugin list/activate/deactivate`, `wp theme list/activate`, `wp option get`, `wp post list/get`, `wp eval-file`, `wp i18n make-pot`). Shipped v1; reports `wpcli_not_found` when unavailable.
- **Fatal-error and `error_log` capture** between edits — shipped v1 for REST ability responses via `_openmira_diagnostics`.
- **Lint integration:** shipped `lint-file(path)` with PHP syntax validation and PHPCS/WPCS detection when `vendor/bin/phpcs` is present. Remaining: `lint-changes` and autofix loop.
- **Syntax check** after every PHP write — shipped v1 using `nikic/php-parser` first and `php -l` fallback. Failed syntax rolls back the write and returns structured error data.
- **Anonymous HTTP verification:** shipped `probe-url` for same-site logged-out status/redirect/body checks without admin cookies. Use before guest screenshots when visual pixels are not required.
- **Sandbox preview** (existing `wp-content/openmira-sandbox/` with crash recovery): AI-written PHP starts here and graduates to `wp-content/plugins/` or `wp-content/themes/<slug>/` via an explicit operation. Sandbox + crash recovery is one of Open Mira's existing strengths — wire it into the IDE workflow as the default test bed.
- **Browser-backed preview** (extend the existing Gutenberg-serialization browser flow): open an authenticated screenshot URL, return the image to the agent. Initial scope: theme home, single post, custom URL. The image goes back to the model so it can iterate.
  - Shipped v1 as a browser-assisted job flow: `screenshot-url` creates the job, `complete-screenshot-url-job` stores PNG/JPEG bytes from a browser-capable client, and `read-screenshot-url-job` returns a protected image URL plus `openmira://...` resource URI. Full base64 is opt-in to avoid context-window blowups.
  - Shipped: exact MCP Resource handlers for completed screenshot jobs. Remaining: thumbnails for non-vision clients and visual-diff scoring.
  - Auth caveat: protected `image_url` uses admin cookies. CLI clients authenticated only with WordPress application passwords should request `include_image=true` when they need image bytes. Test 4 also showed anonymous-only redirects need a guest-mode screenshot or redirect-probe path.

## Theme Creation

- `scaffold-theme(type: classic | block | child, slug, name, parent_slug?, options)`:
  - `style.css` with proper header.
  - `functions.php` with text-domain and minimal enqueues.
  - **Block theme:** `theme.json`, `templates/index.html`, `templates/single.html`, `templates/page.html`, `parts/header.html`, `parts/footer.html`, `patterns/`.
  - **Classic theme:** `index.php`, `header.php`, `footer.php`, `sidebar.php`, `single.php`, `page.php`, `archive.php`, `404.php`.
  - **Child theme:** minimal `style.css` + `functions.php` enqueuing parent styles.
  - Optional asset scaffolding (`assets/css/`, `assets/js/`), screenshot placeholder, `readme.txt`.
  - All names, prefixes, text-domain pulled from `.openmirarules`.
- `scaffold-pattern(theme_slug, name, intent)` — `patterns/*.php` with the right header and a starting block-markup body.
- "Make this site look like X" support: write theme files and CSS, not only post content. Block themes give the agent real leverage here via `theme.json` edits.

## Block Creation

- `scaffold-block(name, type: dynamic | static, target: theme | plugin, options)`:
  - **PHP-rendered (dynamic) blocks first** — they work without any JS build tooling, which is where most agent-built blocks should land by default.
  - Generates `block.json` (WP 6.9 schema), `render.php`, `style.css`, `editor-style.css`.
  - If `@wordpress/scripts` is detected: optionally generate `src/index.js` + `src/edit.js` + `src/save.js` and wire `package.json` scripts.
  - Registers the block in the host plugin/theme automatically.
- Use the existing block-profile cache to inform attribute generation when the agent integrates with third-party blocks.

## Plugin Creation

- `scaffold-plugin(slug, name, namespace?, options)`:
  - Plugin header, text domain, activation / deactivation / uninstall hooks.
  - Optional Composer setup, `@wordpress/scripts` setup, PSR-4 autoloader.
  - Optional starters: block, REST route, admin page, settings page, CLI command.
  - **Sandbox-first.** New plugins land in `wp-content/openmira-sandbox/` (crash-protected) and graduate to `wp-content/plugins/` only via an explicit operation. Reduces the "agent broke the site" surface.

## Project Rules And Memory

- **`.openmirarules`** (project root). YAML or JSON. Captures what no codebase scan can infer:
  - PHP target version, WP target version
  - Text domain, namespace prefix, class prefix
  - Coding standard (WPCS, PSR-12, custom)
  - Preferred block type (dynamic / static)
  - Build tooling preference
  - "This site uses ACF Pro / Bricks / Elementor / …" hints
  - Per-path overrides (e.g. legacy plugin uses different conventions)
  - Read at session start; overlays defaults for scaffolders and patch operations.
- **Memory store** (existing): durable session-spanning project facts. Add per-key TTL, memory diff view in admin, JSON export/import for cross-site portability.

## Response Hints Pattern

- Multi-step abilities should return top-level `next_*_hints` when their output naturally feeds the next safe operation. This prevents agents from missing important data buried in arrays.
- Shipped: `scaffold-theme.next_write_hints` exposes `expected_current_hash` for `theme.json`, theme CSS, and `style.css`.
- Apply next where evidence appears:
  - `create-gutenberg-page` → hints for `set-front-page`, screenshot verification, and current content hash.
  - `scaffold-block` → hints for verification, insertion, and generated file hashes.
  - `apply-patch` / `write-file` → hints for follow-up read/write hash and visual verification when a front-end file changed.

## Safety And Reliability

- **Per-ability capability filters:** `apply_filters('openmira_ability_capability', $cap, $ability_name)` so non-admin roles can be granted narrow surfaces.
- **Configurable production hard-block** via `OPENMIRA_BLOCK_PRODUCTION` constant and option; warning stays the soft default.
- **Runaway protection on `execute-php`:** per-session invocation counter, memory-delta kill switch.
- **Stable ability names.** Removals get one minor-version deprecation cycle.
- **Dispatcher UX:** add an `execute-ability` example / repair hint that `ability_name` must exactly match the names returned by discovery, including the `openmira/` prefix. Test 4 rerun still produced one bare `execute-php` call.
- **Integration tests** against a real WP container (`wp-env`): scaffolding produces activatable themes/plugins; edits round-trip through diff + rollback; sandbox crash recovery; hook discovery against fixture themes/plugins; MCP Adapter integration points (discovery replacement, error reshaping).
- **CI:** `mago format`, `mago lint`, `mago analyze`, phpunit.

## Extensibility For The Ecosystem

Open Mira is most useful if other plugins extend it. WordPress is a hooks-and-plugins platform; Open Mira must be too.

- `openmira_register_ability($name, $config)` — plugins extend without learning the raw Abilities API; Open Mira metadata (toolset, risk level, dispatcher visibility, MCP type) is built in.
- `openmira_register_toolset($id, $label, $abilities)` — plugin-registered toolsets.
- `openmira_register_patch_op($prefix, $handler)` — plugins extend the patch grammar (e.g. WooCommerce ships `*** Register Product Type:`).
- Stable filter API: `openmira_ability_capability`, `openmira_pre_execute`, `openmira_post_execute`, `openmira_toolset_enabled`, `openmira_resource_payload`.
- Publish a one-page "extending Open Mira" doc once the filter API stabilizes; treat it as a stability contract.

## Gutenberg Support (Supporting Role)

Still useful, but in service of theme / block / page creation, not the main product:

- Browser-backed Gutenberg serialization (existing) remains the source of truth for exact block markup when needed.
- Block profile cache (existing) informs safe third-party block generation.
- Pattern creation and validation lives inside theme scaffolding.
- Hash-guarded post_content writes (existing) for content edits.

## Observability

- Audit log surfaces in admin with diff bodies; filter for "destructive operations only."
- **Usage telemetry:** which abilities and toolsets the last N sessions used. Lets users prune the surface confidently.
- **Sanitized feedback skill:** one-click "generate an issue report from the last N ability calls with site URLs, credentials, and content redacted." Pairs with the audit log.

## Demote / Retire

- `disable-file` / `enable-file`: fold into `edit-file` or remove.
- `create-upload-link`: convert to a resource.
- Verbose ability descriptions: compress to one sentence each.
- Legacy `/mcp/mcp-adapter-default-server` alias: keep through one minor-version cycle, then drop.
- The previous "MCP-Adapter Toolsets first" framing: kept as a mechanism (still in Default MCP Surface) but no longer the headline.

## Later

Real items, sequenced after the IDE foundation is solid. Not abandoned — staged.

### Field plugin specializations

- SCF first as the free target; ACF / ACF Pro second.
- Provider abstraction only after both work — abstractions designed before two implementations tend to be wrong.
- JetEngine, Meta Box, Pods, ACPT, ASE Pro as later compatibility targets.
- Shared vocabulary (post types, taxonomies, fields, options pages, dynamic tags) once SCF + ACF both ship.
- Field-plugin migration workflow once two providers work.

### Builder deep automation

- Close the Bricks write loop — `backup-builder-content` and `restore-builder-backup` already include Bricks meta; symmetric write is the next chapter.
- Elementor after Bricks ships and the adapter pattern is documented.
- Beaver / Divi only after that.

### Builder dynamic data

- Bricks dynamic data reliable from field providers: tag generation, rendered-output validation.
- Elementor dynamic tags after Bricks.
- Prefer site-level styles / global classes / variables when generating builder content; do not inline colors, spacing, or typography into every element.

### Pattern intelligence experiment

- `list-gutenberg-patterns` + `analyze-gutenberg-pattern` first.
- Dump native patterns from a real test site (core, theme, Kadence, GenerateBlocks). LLM classification by intent.
- **Kill criterion (pre-commit):** if <60% classify confidently AND pass write-gate validation, commit to a curated recipe registry. Otherwise build live pattern intelligence.

### Recipe sources (provenance, applies retroactively)

- Author clean-room from common layout patterns and our own test pages.
- WordPress core patterns and public docs only as structural / API references.
- No proprietary template packs, branding, copy, images, private implementation details.

## First Implementation Slice

The order that gives you a working "Cursor for WordPress" experience the fastest:

1. **Done, v1 partial:** `get-project-map` — live WP-aware map shipped with bounded default output, `fields` filtering, and summary Resource exposure. Remaining: WP-specific symbol graph and PageRank focus weighting.
2. **Done, v1 partial:** Diff preview + audit log + ring-buffer backups on `write-file` / `edit-file` / `delete-file`. Shipped: diffs, hashes, enforced read-before-write/stale-buffer policy, backups, restore ability, audit admin view, Plan/Act gating. Remaining: full diff expansion in admin.
3. **Done, v1 partial:** `.openmirarules.json` parser + memory overlay. Shipped: read/write abilities, defaults merge, audit/diff/backup on writes, `rules.*` memory overlay, project-map integration, and theme scaffolder defaults. Remaining: richer YAML support, schema validation, and per-path overrides.
4. **Done, v1:** `scaffold-theme` — block, classic, and child theme scaffolding. Smoke-tested by creating and activating a real block theme in Playground.
5. **Done, v1 partial:** `scaffold-block` — PHP-rendered dynamic theme blocks first. Shipped: metadata, render PHP, CSS, editor CSS, no-build editor script, asset file, theme registration, diff/backups/audit, and live rendered page smoke test. Remaining: plugin target, static/JS-build blocks, richer attribute schema prompts.
6. **Done, v1 partial:** `find-hook-callers` / `find-hook-registrants` / `resolve-template` — the "go to definition" equivalents that prove Open Mira navigates WordPress instead of grepping it. Shipped: AST hook scan, runtime hook registrants, and block/classic template hierarchy resolution. Remaining: cached symbol graph, template-part edges, block registration/use edges.
7. **Done, v1 partial:** `run-wpcli` + WPCS lint + fatal-error capture — closes the edit → test → fix loop. Shipped: PHP syntax rollback, diagnostics capture, `lint-file`, WP-CLI allowlist, Playground smoke validation. Remaining: `lint-changes`, PHPCS autofix loop, richer fatal scoping, and CI with Docker/wp-env.
8. **Done, v1 partial:** Browser-assisted screenshot URL loop — closes the agent's visual feedback loop without dumping base64 screenshots into every tool result. Shipped: same-site URL jobs, viewport metadata, protected image URL, exact MCP image resources for completed jobs, explicit base64 opt-in, and Playground smoke validation. Remaining: thumbnails for non-vision clients and visual-diff scoring.
9. **Done, v1:** Test 1 discovery pilot — shipped `docs/pilots/test-1-discovery-pilot.md` and `scripts/run-pilot.sh`, then ran it through Claude Code with Open Mira exposed only through MCP. Result: a coherent block-theme landing page, 22 tool calls, 0 rollbacks, 0 lint/syntax failures, 1 completed screenshot job.
10. **Done, v1:** Test 1 friction fixes — bounded `get-project-map`, summary Resource, schema tolerance with dropped-property notices, `slug`/`command`/`viewport` aliases, optional screenshot labels, palette-safe Gutenberg design colors, item heading aliases, and structurally complete core-block section rendering.
11. **Done, v1 proof:** `apply-patch` with first WP grammar op, `*** Update theme.json (path: …):`, including dry-run, backups, audit, stale-write protection, array slug selectors, and merge mode.
12. **Done, v1 measured:** Test 2 complex-design benchmark — Claude Code built a coherent design-heavy block-theme page with one screenshot loop and no rollbacks. Friction showed `apply-patch` is real but too narrow for broader `theme.json` design-system work.
13. **Done, v1:** Resource infrastructure and targeted Gutenberg reliability — completed screenshot jobs register MCP blob resources; project-map and memory snapshots register JSON resources; testimonial and feature-grid pattern rendering preserve the Pilot #12 design metadata.
14. **Done, v1 measured:** Pilot #13 — Resource-enabled complex benchmark. Result: 15 MCP ability calls, 0 failures, screenshot reviewed through `resources/read`, no inline-base64 bloat, feature-grid numbers rendered, and testimonial colors rendered.
15. **Done, v1:** Targeted Gutenberg/page-builder ergonomics from Pilot #13 — `scaffold-theme.include_blank_template`, split/two-column brief pattern rendering, and explicit feature-grid `number_color` behavior.
16. **Done, v1 measured:** Test 3 plugin-lane benchmark — validated Open Mira outside themes by fixing Leaflet Map issue #278, then reran with `search-code` and reduced calls from 11 to 9 with no failures.
17. **Done, v1 measured:** Test 4 hook-heavy plugin benchmark — implemented and verified a Taro Clockwork Post expired-private-post redirect using current file-editing abilities. Hook placement was not painful; anonymous visitor verification was the concrete missing capability.
18. **Done, v1:** Anonymous HTTP verification — `probe-url` verifies logged-out redirects and access-control responses without admin cookies, returning bounded output for agents.
19. **Done, v1 measured:** Test 4 rerun — validated natural `probe-url` use and confirmed anonymous `302` redirects. Remaining signal: this was still hook-authoring-trivial, so hook navigation remains untested.
20. **Done, v1 measured:** Hook-navigation benchmark — controlled fixture proved `find-hook-registrants` is discoverable and useful for priority/callback conflicts. Hook patch grammar remains deferred.
21. **Now:** Plugin creation/scaffolding benchmark — build a compact plugin with CPT/taxonomy/meta/settings/front-end output through current safe file-editing abilities. Gate: if repeated boilerplate dominates, prioritize `scaffold-plugin`.

Everything in **Later** stays in the file as a real backlog. The pivot is sequencing, not abandonment.
