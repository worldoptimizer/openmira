# Open Mira Test 2 — Complex Design Benchmark

## Purpose

Measure whether Open Mira can use the current MCP surface, including `openmira/apply-patch`, to build a more design-heavy WordPress page without human orchestration.

This is not a polish exercise. Stop when the page is coherent and measurable.

## Input

Read the reference files through Open Mira MCP file abilities, not local shell:

- `wp-content/plugins/open-mira/docs/pilots/fixtures/test-2-reference.html`
- `wp-content/plugins/open-mira/docs/pilots/fixtures/test-2-reference.css`

Use those files as the source design. The intended output is a WordPress block-theme landing page with comparable structure, spacing, color system, typography, and section rhythm.

## Required Build

- Scaffold or reuse a block theme with slug `pilot-two`.
- Create a published page with slug `pilot-two-landing`.
- Set that page as the front page.
- Use Gutenberg/core-block-compatible content unless a stronger Open Mira ability is available.
- Use `openmira/apply-patch` for at least one `theme.json` design-system change if practical.
- Keep custom CSS minimal and theme-scoped.
- Create at least one screenshot job, complete it with the bridge, and use the result to decide whether one iteration is needed.

## Success Criteria

- Front page renders without PHP fatals.
- No lint or syntax failures.
- Screenshot loop completes.
- Page includes hero, two-column brief section, three-card grid, testimonial/quote band, and CTA/footer-like close.
- Theme file count stays under 30 unless justified in the friction log.
- Agent stops after first coherent result rather than over-polishing.

## Metrics To Report

Report:

- Tool-call count.
- Schema/tool failures.
- Rollbacks.
- Syntax/lint failures.
- Time to first render.
- Time to completion.
- Screenshot job count.
- Whether `openmira/apply-patch` was used and for what.
- Files created/edited by path.

## Friction Log Format

For each friction point:

- Tool or ability involved.
- What the agent tried.
- What was missing, unclear, or too verbose.
- What Open Mira capability would have reduced the tool calls.

The main benchmark output is the friction log. Visual quality matters, but roadmap signal matters more.

## Pilot #6 Result — 2026-05-16

Run through `scripts/run-pilot.sh` against a fresh Playground using Claude Code as the external MCP client.

### Metrics

- Tool calls: 21 total.
- Schema/tool failures: 3.
- Rollbacks: 0.
- Syntax/lint failures: 0.
- Time to first render: ~13 minutes.
- Time to completion: ~13 minutes.
- Screenshot jobs: 1 created, 1 completed.
- `openmira/apply-patch`: used once for `settings.color.palette`.
- Theme file count: 13.
- Final URL: `http://127.0.0.1:9400/`.

### Outcome

The page rendered coherently on the first completed visual pass: hero, split brief, three-card grid, quote band, and CTA were all visible with a coherent warm design system. No PHP fatals or rollbacks occurred.

### Friction

1. `openmira/apply-patch` description did not clearly show the required `*** Begin Patch` / `*** End Patch` envelope, causing one failed call and one `get-ability-info` recovery call.
2. `openmira/apply-patch` and `openmira/write-file` required `read-file` before changing files that had just been scaffolded and returned with hashes, causing extra round trips.
3. `openmira/create-gutenberg-page` silently dropped the `template` property, forcing an `execute-php` workaround to set `_wp_page_template` and the front page.
4. the legacy inline screenshot read path returned a ~1.2M-character base64 payload and exceeded the client context limit.
5. The agent used `apply-patch` only for the palette, then overwrote full `theme.json` for broader layout/style/template changes. This suggests the next grammar value is richer `theme.json` ergonomics before assuming `*** Add Pattern:` is the highest-value op.

### Fixes Applied Immediately

- Clarified `openmira/apply-patch` description and instructions with the required envelope.
- Added `expected_current_hash` to `openmira/apply-patch` and `openmira/write-file` so scaffold/read hashes can satisfy stale-write protection without a redundant read.
- Added `template` support to `openmira/create-gutenberg-page`.
- Added screenshot inline-image warnings so agents avoid full-size base64 unless necessary.

### Roadmap Signal

The first complex-design benchmark does not justify `*** Add Pattern:` as the next patch op yet. The strongest evidence points to:

1. richer `theme.json` patch ergonomics, including multi-path updates and examples;
2. better first-class visual asset/resource flow for screenshots;
3. reducing raw file overwrites after scaffolded theme creation.

## Pilot #7 Result — 2026-05-16

Run through `scripts/run-pilot.sh` after applying the Pilot #6 friction fixes. This run is **not comparable** to Pilot #6 for patch-grammar behavior because it was dominated by stale Playground filesystem state for the reused `pilot-two` theme slug.

### Metrics

- Tool calls: 33 total.
- Schema/tool failures: 9.
- Rollbacks: 0.
- Syntax/lint failures: 0.
- Time to first render: ~18 minutes.
- Time to completion: ~18 minutes.
- Screenshot jobs: 1 created, 1 completed.
- `openmira/apply-patch`: used once for `settings.color.palette`.
- Final URL: `http://127.0.0.1:9400/`.

### Outcome

The final page rendered coherently with the required hero, split brief, three-card grid, quote band, and CTA. The screenshot loop worked. The result does not answer whether `expected_current_hash` changes the agent's broader `apply-patch` usage because most of the run was spent recovering from environment reset/state issues.

### Friction

1. `pilot-two` existed in a stale Playground theme filesystem state. PHP saw the directory in some calls, but writes to expected files failed or reported contradictory `file_exists()` / `file_put_contents()` results.
2. `openmira/scaffold-theme` failed early when the target directory could not be cleanly created or overwritten.
3. `openmira/list-directory` did not expose per-entry writability, forcing manual diagnostic probes.
4. The agent had to create a theme under `wp-content/openmira-sandbox/themes/` and register that directory with sandbox PHP, which is a workaround rather than the intended theme scaffold path.

### Fixes Applied Immediately

- Hardened `openmira_ensure_parent_dir()` to clear stat caches and accept an existing directory after a failed `mkdir()` attempt.
- Switched `openmira/scaffold-theme` directory creation to the shared hardened helper.
- Added `readable`, `writable`, and `exists` fields to `openmira/list-directory` entries so filesystem permission problems are visible without probe calls.

### Roadmap Signal

Pilot #7 is invalid as apply-patch evidence. Fix/reset the harness and rerun Test 2 with a clean theme slug before investing in richer `theme.json` grammar or `*** Add Pattern:`.

## Pilot #8 Result — 2026-05-16

Run through `scripts/run-pilot.sh` with a fresh slug (`pilot-seven-clean`) to avoid stale `pilot-two` Playground state. This is the clean rerun after Pilot #7's filesystem hardening.

### Metrics

- Tool calls: 17 MCP calls plus 1 local bridge call.
- Schema/tool failures: 2.
- Rollbacks: 0.
- Syntax/lint failures: 0.
- Time to first render: ~11 minutes.
- Time to completion: ~11 minutes.
- Screenshot jobs: 1 created, 1 completed.
- `openmira/apply-patch`: attempted, but failed before applying due a stale theme-registry lookup.
- Theme file count: 12.
- Final URL: `http://127.0.0.1:9400/`.
- Screenshot: `#runtime/pilot-runs/20260516T050536Z/screenshots/screenshot-job-078cff3ef4de43288bcac3b513403b53.png`.

### Outcome

The page rendered coherently with the required hero, split brief, three-card grid, quote band, and CTA. The screenshot loop completed without inline base64 overflow. The fresh slug confirmed the Pilot #7 filesystem issue was environmental, not the normal scaffold path.

### Friction

1. `openmira/apply-patch` failed with `Theme not found: pilot-seven-clean` immediately after `openmira/scaffold-theme` returned `activated: true`. The files existed and the page could be built, but `wp_get_theme('pilot-seven-clean')->exists()` returned false.
2. The agent then fell back to `write-file` for theme.json/CSS. This means Pilot #8 still does not answer whether richer `theme.json` grammar is needed; the stronger evidence is a theme registry/path-resolution bug.
3. A `write-file` call missed the scaffold-returned `content_hash` and had to recover with `read-file`. The API supports `expected_current_hash`, but the error should explicitly suggest using hashes returned by scaffold/read responses.

### Fixes Applied Immediately

- Added `path` to `openmira/apply-patch` so known `theme.json` paths can bypass stale theme-registry lookups while keeping stale-write protection.
- Added filesystem fallback in `openmira_patch_resolve_theme()` when `wp_get_theme()` is stale but the active theme directory exists.
- Refreshed theme caches after `openmira/scaffold-theme` writes files and before/after activation.

### Roadmap Signal

Do not build richer `theme.json` grammar yet. The next gate is a targeted smoke/rerun proving `apply-patch` works against freshly scaffolded themes via the explicit path/fallback fix. If that passes and the next clean benchmark still uses `write-file` for broad theme.json redesign, then richer theme.json ergonomics are justified. Screenshot Resource wiring remains the next non-patch investment; `*** Add Pattern:` remains unproven.

## Pilot #9 Result — 2026-05-16

Run through `scripts/run-pilot.sh` after the targeted `apply-patch` theme-registry/path fallback smokes. The benchmark used a clean page slug but the agent chose a fresh theme slug (`atelier-ridge`) instead of the requested `pilot-nine-clean` because previous stale-slug history was present in the brief.

### Metrics

- Tool calls: 12 MCP calls plus 2 local helper calls.
- Schema/tool failures: 0.
- Rollbacks: 0.
- Syntax/lint failures: 0.
- Time to first render: ~9 minutes.
- Time to completion: ~9 minutes.
- Screenshot jobs: 1 created, 1 completed.
- `openmira/apply-patch`: used once for `settings.color.palette`.
- Theme file count: 13.
- Final URL: `http://127.0.0.1:9400/`.
- Screenshot: `#runtime/pilot-runs/20260516T052206Z/screenshots/screenshot-job-50dfef2283c24f5e938cff81a0deb0e8.png`.

### Outcome

The page rendered coherently on the first visual pass with the required hero, split brief, three-card grid, quote band, and CTA. No PHP fatals, rollbacks, schema retries, or lint failures occurred. `openmira/apply-patch` succeeded through normal `theme_slug` lookup after `openmira/scaffold-theme` returned `registry_exists: true`, confirming the Pilot #8 registry/path fix.

### Friction

1. The brief still carried historical stale-slug context, so the agent avoided the requested benchmark slug and created `atelier-ridge`. This is a benchmark prompt issue and a missing reset primitive.
2. The agent used `apply-patch` for the palette, then switched to `write-file` for broader `theme.json` initialization: typography, font sizes, spacing, and element styles.
3. All five sections were implemented as `core/html` blocks with custom CSS classes. This produced the best visual result quickly, but the sections are not structurally editable as native Gutenberg groups/cards/buttons.
4. Screenshot inline base64 was avoided. The bridge wrote and read the PNG file without context-window bloat.

### Fixes Applied Immediately

- Clarified `openmira/apply-patch` descriptions and instructions so agents see that repeated theme.json hunks are supported in one patch envelope.
- Added bulk theme.json patch syntax: `*** Update theme.json (paths, mode: merge):` with a JSON object keyed by semantic theme.json paths.
- Added `force_clean` to `openmira/scaffold-theme`, moving an existing theme directory to `wp-content/openmira-file-backups/theme-cleanups/` before fresh scaffolding instead of forcing slug archaeology.

### Roadmap Signal

Pilot #9 proves the current architecture can build a complex design with a clean run: 14 total calls, 0 failures, 0 rollbacks, and one screenshot loop. The next evidence-backed investments are:

1. **Use and validate bulk theme.json patching** in the next complex benchmark before adding unrelated patch ops.
2. **Improve benchmark isolation** with `force_clean` and prompt cleanup so required slugs are actually used.
3. **Build a section-builder / pattern-rendering layer** only after a benchmark proves raw HTML blocks are the bottleneck for editability, not just the fastest route to visual fidelity.
4. **Wire screenshot `openmira://` URIs as real MCP Resources** so browser-capable clients do not need the helper bridge or inline base64 fallback.

## Pilot #10 Result — 2026-05-16

Run through `scripts/run-pilot.sh` with a clean brief, required slug `pilot-ten-bulk`, `force_clean: true`, and explicit instruction to use the new bulk theme.json patch form.

### Metrics

- Tool calls: 18 total.
- Schema/tool failures: 1 agent slip: empty `execute-ability` call with no `ability_name`.
- Rollbacks: 0.
- Syntax/lint failures: 0.
- Time to first render: ~14 tool calls.
- Time to completion: ~5 minutes wall time.
- Screenshot jobs: 1 created, 1 completed.
- `openmira/apply-patch`: used once for a 12-path `theme.json` design-system merge.
- Theme file count: 12.
- Final URL: `http://127.0.0.1:9400/`.

### Outcome

The page rendered coherently on the first visual pass with the required hero, split brief, three-card grid, quote band, and CTA footer. The required slug was used. `force_clean` and bulk `theme.json` patching both worked in a real external-MCP run.

### Friction

1. `openmira/write-file` rejected a deliberate full CSS overwrite until the agent read the file first. The safety policy is correct, but the error needs to strongly suggest `expected_current_hash` when the agent already has a scaffold/read hash.
2. the legacy inline screenshot read path produced a ~1.3M-character base64 payload and overflowed context. The bridge/local-file fallback worked, but this confirms real MCP Resource wiring is the right fix.
3. `openmira/apply-patch` succeeded, but returned a full diff for a large 12-path change. The agent mostly needed a summary, not the entire diff.
4. `discover-abilities` still required follow-up `get-ability-info` calls for schemas.

### Fixes Applied Immediately

- Added `include_diff` to `openmira/apply-patch`; successful large patches can now return `diff_summary` without the full unified diff.
- Updated `openmira/write-file` guidance and stale-write errors to explicitly suggest `expected_current_hash` from scaffold/read outputs.

### Roadmap Signal

Bulk `theme.json` patching is now validated. Do not add `*** Add Pattern:` yet. The next highest-value work is:

1. **Screenshot Resource implementation** for the legacy screenshot resource URI.
2. **Diff/token controls** across large write-style abilities.
3. **Schema discovery compression** so agents do not spend setup calls on common ability schemas.
4. **Section-builder / pattern rendering** only after measuring whether native editability is more important than the raw HTML route that currently wins on visual fidelity.

## Pilot #11 Result — 2026-05-16

Run through `scripts/run-pilot.sh` with the same Test 2 brief as Pilot #10, required slug `pilot-eleven-natural`, and no explicit instruction to use the bulk `theme.json` patch form. The goal was to test whether agents naturally discover the bulk form from ability descriptions alone.

### Metrics

- Tool calls: 17 total.
- MCP ability calls: 15.
- Local helper calls: 2: one screenshot bridge call and one local screenshot read.
- Schema/tool failures: 2 stale-write rejections from `openmira/write-file`.
- Rollbacks: 0.
- Syntax/lint failures: 0.
- Time to first render: ~10 minutes wall time.
- Screenshot jobs: 1 created, 1 completed.
- `openmira/apply-patch`: not used.
- Theme file count: 12.
- Final URL: `http://127.0.0.1:9400/`.
- Run directory: `#runtime/pilot-runs/20260516T055523Z`.

### Outcome

The page rendered coherently on the first visual pass with the required hero, split brief, three-card grid, quote band, and CTA. `create-gutenberg-page` remained the strongest page-build primitive: it accepted the five-section block markup in one call with no block validation errors.

### Friction

1. The agent first tried `write-file` for `theme.json` and CSS without passing `expected_current_hash`, even though `scaffold-theme` returned per-file `content_hash` values. It recovered after the stale-write error. This confirms the safety model works, but the hash affordance was not visible enough through the dispatcher workflow.
2. The agent did not naturally choose `openmira/apply-patch` for broad `theme.json` initialization. It explicitly said the bulk patch format was not obvious enough from the description and used a full `write-file` rewrite instead.
3. Scaffold defaults still target a general block/blog theme (`contentSize: 760px`, `wideSize: 1180px`, header/footer-first templates). Landing-page builds repeatedly need wider layout defaults and a `front-page.html` post-content template.
4. Screenshot job creation and bridge completion worked without inline base64 overflow.

### Fixes Applied Immediately

- Tightened `openmira/write-file` public description and instructions to mention `expected_current_hash` from read/scaffold responses where agents see the ability through `execute-ability`.
- Tightened `openmira/apply-patch` public description and schema text to explicitly prefer the bulk `paths` form for theme.json design-system setup.

### Roadmap Signal

Pilot #11 proves the bulk patch feature works when instructed but is not yet self-discovering. Before adding unrelated patch ops, rerun a natural complex benchmark after the description fix or add schema-discovery compression so `discover-abilities` can surface the exact bulk syntax without a separate `get-ability-info` call. Screenshot Resources remain the next architectural investment because screenshot context bloat has appeared in multiple complex pilots.

## Pilot #12 Result — 2026-05-16

Run through `scripts/run-pilot.sh` with the same natural-discovery Test 2 brief as Pilot #11, required slug `pilot-twelve-natural`, and the updated compact discovery / tightened ability descriptions.

### Metrics

- MCP tool calls: 15.
- Local helper calls: 1 screenshot bridge call.
- Schema/tool failures: 1 stale-write rejection from `openmira/apply-patch` missing `expected_current_hash`.
- Rollbacks: 0.
- Syntax/lint failures: 0.
- Time to first render: ~55 seconds from scaffold to front-page set.
- Time to screenshot complete: ~75 seconds.
- Screenshot jobs: 1 created, 1 completed.
- `openmira/apply-patch`: used once for a bulk `theme.json` design-system merge.
- Theme file count: 11.
- Final URL: `http://127.0.0.1:9400/`.
- Run directory: `#runtime/pilot-runs/20260516T061119Z`.

### Outcome

The agent naturally selected the bulk `apply-patch` paths form after the description/discovery change. The page rendered coherently on the first visual pass with all five required sections. The CTA section had a layout quirk from raw `core/columns` alignment, but no fatal, rollback, schema, or lint failures occurred after the one stale-write retry.

### Friction

1. The first `apply-patch` call omitted `expected_current_hash` even though `scaffold-theme` returned a file hash. The agent recovered after the error. This suggests scaffold results should expose high-value follow-up hashes at a top-level `next_write_hints` key rather than burying them in the `files` array.
2. `render-gutenberg-pattern` testimonial ignored `design.background_color` and returned a bare quote block, forcing a manual styled wrapper.
3. `render-gutenberg-pattern` feature-grid ignored per-item `number` metadata, forcing manual numbered cards.
4. Raw Gutenberg `core/columns` markup can drift visually from editor-normalized layouts for split CTA sections.

### Fixes Applied Immediately

- Added `next_write_hints` to `openmira/scaffold-theme`, exposing `theme_json`, `theme_css`, and `style_css` paths with `expected_current_hash` values for immediate follow-up writes/patches.

### Roadmap Signal

Pilot #12 closes the bulk-patch discoverability loop. The next evidence-backed work is no longer another theme.json grammar tweak; it is Resource support and Gutenberg section/pattern reliability:

1. Wire `openmira://` screenshots as real MCP Resources.
2. Keep project-map and memory snapshots on the same Resource infrastructure.
3. Fix `render-gutenberg-pattern` testimonial/feature-grid behavior before adding broad `*** Add Pattern:` grammar.
4. Consider a targeted split-CTA/native-section helper only if the next visual benchmark repeats the CTA layout drift.

## Post-Pilot #12 Implementation — 2026-05-16

Shipped the two targeted fixes before broadening the architecture:

- Superseded: screenshot image resource delivery was later removed after dogfood testing showed Claude Code CLI could not consume it reliably. External capture now stores files on disk for human/CI inspection.
- Project-map summary and memory snapshot are direct JSON MCP resources, avoiding the adapter's no-input ability-backed resource read path.
- `render-gutenberg-pattern` now preserves feature-grid item `number`, `label`, and `accent_color` metadata.
- Testimonial patterns now honor `background_color` and `text_color` by rendering a section group with styled quote content.

Playground MCP smoke confirmed:

- `resources/list` includes `openmira://project-map/summary` and `openmira://memory/snapshot`. Screenshot image resources were removed before the next release.
- `resources/read` returns JSON text for project-map and memory resources.
- Superseded: the historical image resource read was removed before release; only project-map and memory remain as MCP resources.
- Server-side pattern smoke confirmed feature-grid metadata and testimonial background/text colors render into block markup.

Next benchmark: Pilot #13 should reuse the complex-design brief with no special instruction to request inline screenshots. This historical criterion was later superseded by the external capture-to-disk decision.

## Pilot #13 Result — 2026-05-16

Run through `scripts/run-pilot.sh` after Resource implementation and the targeted testimonial/feature-grid pattern fixes. The benchmark used required theme slug `pilot-thirteen-resources` and page slug `pilot-thirteen-resources-landing`.

### Metrics

- MCP ability calls: 15.
- Local helper calls: 1 screenshot bridge call.
- Schema/tool failures: 0.
- Rollbacks: 0.
- Syntax/lint failures: 0.
- Time to first render: ~4 minutes wall time.
- Time to completion: ~5 minutes wall time.
- Screenshot jobs: 1 created, 1 completed.
- the legacy inline-image option: not used.
- `resources/read`: used for `the legacy screenshot resource URI`.
- `openmira/apply-patch`: used once for bulk `theme.json` palette, typography, layout, and button styles.
- `render-gutenberg-pattern`: used for hero, feature-grid, testimonial, and CTA sections.
- Theme file count: 12.
- Final URL: `http://127.0.0.1:9400/`.
- Run directory: `#runtime/pilot-runs/20260516T092025Z`.
- Screenshot: `#runtime/pilot-runs/20260516T092025Z/screenshots/screenshot-job-9842e4422ba24871beef78ef9e8012e3.png`.

### Outcome

The Resource milestone is validated. The agent completed a coherent complex-design page without requesting inline screenshot base64, read the screenshot through the MCP Resource channel, and avoided the screenshot context-overflow class that appeared in earlier complex pilots.

The targeted Gutenberg fixes also held in a real build: feature-grid numbers rendered as visible card metadata, and testimonial background/text color rendered in the final section markup.

### Friction

1. `openmira/scaffold-theme` did not provide a blank/full-bleed landing template, forcing one extra `write-file` call for `templates/blank.html`.
2. The hero pattern did not include a navigation/brand row slot, so the page had to assemble that need separately.
3. Feature-grid number color still required manual markup/style adjustment for the design target. The pattern preserves number metadata, but the color affordance is not obvious enough.
4. No native split/two-column brief pattern exists, so the agent hand-wrote `core/columns` markup for the editorial split section.
5. `expected_current_hash` works, but multi-step write flows still rely on agents manually carrying hashes from `next_write_hints`.

### Roadmap Signal

Pilot #13 proves Resources are the right screenshot transport. Do not spend the next cycle on screenshot plumbing or broad `*** Add Pattern:` grammar. The next evidence-backed work is small Gutenberg/page-building ergonomics:

1. Add a `scaffold-theme` option for a blank landing-page template.
2. Add a native split/two-column brief pattern to reduce repeated raw `core/columns` assembly.
3. Expose feature-grid number color behavior clearly, either via `number_color` or a primary-color fallback.
4. Consider hero navigation slots only if the next benchmark repeats the need; it appeared once here but may be design-specific.

## Post-Pilot #13 Implementation — 2026-05-16

Shipped the bounded quick wins, but did not make another theme-lane pilot the next milestone:

- `openmira/scaffold-theme` now accepts `include_blank_template: true` for block themes, writes `templates/blank.html`, and registers the custom template in `theme.json`.
- `render-gutenberg-pattern` now supports a native `split` pattern for two-column editorial brief sections.
- Feature grids now expose `number_color` behavior and default number accents to the primary color when no explicit item color is provided.

Next benchmark: Test 3 should leave the theme/page lane and validate the IDE positioning against plugin work. Preferred path is fixing a small real bug in a compact open-source plugin; fallback is building a small plugin with CPT, taxonomy, meta box, settings page, and front-end output.
