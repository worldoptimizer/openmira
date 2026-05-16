# Open Mira Test 2 — Pilot #13 Resource Verification

## Purpose

Measure whether Open Mira can build the same design-heavy WordPress page while naturally using MCP Resources for screenshots and context instead of inline base64 image payloads.

This is not a polish exercise. Stop when the page is coherent and measurable.

## Input

Read the reference files through Open Mira MCP file abilities, not local shell:

- `wp-content/plugins/open-mira/docs/pilots/fixtures/test-2-reference.html`
- `wp-content/plugins/open-mira/docs/pilots/fixtures/test-2-reference.css`

Use those files as the source design. The intended output is a WordPress block-theme landing page with comparable structure, spacing, color system, typography, and section rhythm.

## Required Build

- Scaffold or reuse a block theme with slug `pilot-thirteen-resources`.
- Pass `force_clean: true`, `overwrite: true`, and `activate: true` when scaffolding the theme.
- Create a published page with slug `pilot-thirteen-resources-landing`.
- Set that page as the front page.
- Use Open Mira's WordPress-aware patch/edit abilities where they are the best fit for the change.
- Use Gutenberg/core-block-compatible content unless a stronger Open Mira ability is available.
- Prefer Open Mira pattern rendering when it fits naturally; specifically verify feature-grid item `number` metadata and testimonial background/text color behavior if those sections are used.
- Keep custom CSS minimal and theme-scoped.
- Create at least one screenshot job, complete it with the bridge, then prefer the returned the legacy resource URI / MCP Resource channel or bridge screenshot file for visual review. Do not request the legacy inline-image option unless Resource reading and local file review are both unavailable.

## Success Criteria

- Front page renders without PHP fatals.
- No lint or syntax failures.
- Screenshot loop completes.
- No screenshot read uses the legacy inline-image option unless explicitly reported as a client limitation.
- At least one screenshot is reviewed via Resource or bridge file path without context overflow.
- Page includes hero, two-column brief section, three-card grid, testimonial/quote band, and CTA/footer-like close.
- Feature-grid number metadata renders if feature-grid pattern rendering is used.
- Testimonial background/text colors render if testimonial pattern rendering is used.
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
- Whether the legacy inline-image option was used.
- Whether `resources/read` was used for any `openmira://` resource.
- Whether `openmira/apply-patch` was used and for what.
- Whether Open Mira pattern rendering was used and for what.
- Files created/edited by path.

## Friction Log Format

For each friction point:

- Tool or ability involved.
- What the agent tried.
- What was missing, unclear, or too verbose.
- What Open Mira capability would have reduced the tool calls.

The main benchmark output is the friction log. Visual quality matters, but roadmap signal matters more.
