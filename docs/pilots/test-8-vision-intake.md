# Test 8 — Vision-Based Design Intake

## Purpose

Validate the remaining untested flagship lane: an external MCP agent uses Open Mira to inspect a visual reference and build a working WordPress page from it.

This is not a theme.json patch benchmark and not a source-copy benchmark. The question is whether the agent can use the screenshot/resource loop as design intake, then build and iterate inside WordPress.

## Setup

The reference page is prepared before the pilot:

`http://127.0.0.1:9400/openmira-vision-reference/`

Target page to build:

`http://127.0.0.1:9400/openmira-vision-build/`

## Brief

Build a WordPress page that visually matches the reference page.

Use the Open Mira MCP server only.

Required workflow:

1. Use `screenshot-url` on the reference URL and inspect the screenshot/resource as the primary design input.
2. Do not read or copy the reference page source/content directly. This is a visual-intake test.
3. Build a new published page with slug `openmira-vision-build`.
4. Use Gutenberg-native blocks where practical. Use custom HTML/CSS only when it materially improves visual fidelity.
5. Use the screenshot loop on the built page at least once, compare against the reference, and make one improvement pass if needed.
6. Verify the final page with `probe-url` and a screenshot.
7. Stop after the first coherent verified result.

## Visual Criteria

The reference contains:

- Dark editorial hero with small eyebrow text, large two-line headline, body copy, and two buttons.
- Three metric cards below the hero.
- A light section with two text columns and a rounded image placeholder/card.
- A dark CTA band near the bottom.

The built page should preserve the main visual hierarchy, contrast, spacing, and section order. Exact copy and exact pixel matching are less important than a recognizably similar layout.

## Constraints

- Do not edit repository files directly.
- Do not inspect the reference page HTML, REST content, or database content.
- Do not use `execute-php` for page content writes unless first-class page/Gutenberg abilities fail.
- Keep the build to one page; do not scaffold a plugin.

## Success Criteria

- Reference screenshot is captured and inspected through the Resource/image path, not inline base64.
- Target page exists at `openmira-vision-build`.
- Target page renders without PHP fatals.
- Final screenshot shows the same section order and recognizable design language.
- `probe-url` returns 200 for the target page.
- No schema/tool failures above 1, no rollbacks, no syntax/lint failures.

## Metrics To Report

- MCP ability calls.
- Schema/tool failures.
- Rollbacks/stale-write retries.
- Screenshot jobs and whether Resources were used.
- Whether the agent obeyed the visual-only reference constraint.
- Whether it made an improvement pass after viewing its own output.
- Main friction items.

## Decision Gate

- If the agent uses screenshots as actual design input and reaches a coherent page in ≤18 MCP calls with 0-1 failures, vision intake is validated for v1.
- If it tries to read/copy source, tighten ability descriptions and pilot prompt before adding new tools.
- If it cannot reason from screenshots, add a first-class `compare-screenshots` / visual-diff helper before running more design-intake pilots.
- If the result is structurally correct but visually weak, prioritize section-level builder/pattern primitives only if the friction log shows repeated manual block composition.

## Result

Pilot run: `#runtime/pilot-runs/20260516T113229Z`

Summary:

- Vision intake passed: the agent captured the reference screenshot, inspected it visually, built a matching WordPress page, viewed its own first output, and made a targeted improvement pass.
- Final page: `http://127.0.0.1:9400/openmira-vision-build/`
- The agent obeyed the visual-only constraint. It did not read the reference page HTML, REST content, or database content.
- Final page matched the major visual hierarchy: dark editorial hero, yellow eyebrow/buttons, three metric cards, split text + image-card section, and dark CTA band.

Metrics:

| Metric | Value |
| --- | --- |
| MCP ability calls | 14 |
| Schema/tool failures | 1 |
| Rollbacks / stale-write retries | 0 |
| Screenshot jobs | 3 |
| Improvement pass | yes |
| `probe-url` final status | 200 OK |

Friction:

- `read-screenshot-url-job` schema-rejected `inline_image_max_bytes: 2097152` because the schema capped the value at 1 MB. The callback already clamps internally, so this should be accepted and clamped instead of schema-failing.
- The `split` pattern was close but not flexible enough for the reference's left text + right image-card layout. The agent composed custom markup for that section.
- The `feature-grid` pattern emitted empty heading/body blocks when blank values were passed intentionally. Pattern rendering should skip explicitly blank heading/body fields.

Follow-up fixes:

- `read-screenshot-url-job` no longer schema-caps `inline_image_max_bytes`; oversized requests reach the callback and are clamped by the existing safety cap.
- `feature-grid` now skips explicitly blank heading/body values instead of emitting empty blocks.
