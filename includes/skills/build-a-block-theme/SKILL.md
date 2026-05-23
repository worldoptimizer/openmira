---
title: "Build a Block Theme"
description: "End-to-end workflow for scaffolding and styling a block theme with Open Mira."
enable_prompt: true
---

# Build a Block Theme

This skill is the end-to-end workflow for creating a working WordPress block theme from a brief through Open Mira's abilities. Use it when the user asks you to build a new theme, including landing pages, portfolio sites, or any starting point where they want a block theme as the foundation.

## Inputs

Before starting, confirm with the user:

1. **Theme slug** (lowercase, hyphen-separated, no spaces). If not provided, derive from the project or ask.
2. **Theme name** (human-readable). Default to a title-cased version of the slug.
3. **Design direction** — one or two sentences. "Editorial, warm palette, generous whitespace" is enough; do not over-specify. The user can refine after seeing the first result.
4. **Whether to activate immediately** after scaffolding. Default: yes.

If the user has not provided enough to proceed, ask only the bare minimum, then start.

## Phase 1 — Discover

Call `openmira/get-project-map` with `fields: ["site", "theme", "writable_locations", "rules"]`. Confirm:

- The site is reachable
- `wp-content/themes/` is writable
- Project rules include a `preferred_theme_type` (should be `"block"` for this skill)
- Project rules include a `text_domain` (use it; do not invent one)

If `preferred_theme_type` is `"classic"`, ask the user to confirm they want a block theme before proceeding — the project rules suggest otherwise.

## Phase 2 — Scaffold

Call `openmira/scaffold-theme` with:

- `type: "block"`
- `slug: <theme-slug>`
- `name: <theme-name>`
- `description: <one-sentence summary derived from the design direction>`
- `design_brief: <the user's design direction>`
- `activate: true` (or false if the user opted out)
- `include_blank_template: true` (gives you a starting `templates/index.html` that's not empty)

The response includes `next_write_hints` with `expected_current_hash` values for the key files (`theme.json`, `style.css`, theme CSS). Hold onto those — you'll use them for follow-up edits.

If `scaffold-theme` returns an error, the most common causes are:

- Slug collision with an existing theme directory. Pick a different slug or add `force_clean: true` (which deletes the existing directory of the same slug — only do this if the user confirms).
- Non-writable themes directory. Tell the user to check filesystem permissions.

## Phase 3 — Design system via `apply-patch`

Update `theme.json` in one bulk call rather than many small edits. Use `openmira/apply-patch` with the bulk paths form:

```
*** Begin Patch
*** Update theme.json (paths, mode: merge):
{
  "settings.color.palette": [
    {"slug": "primary", "color": "<hex>", "name": "Primary"},
    {"slug": "secondary", "color": "<hex>", "name": "Secondary"},
    {"slug": "base", "color": "<hex>", "name": "Base"},
    {"slug": "contrast", "color": "<hex>", "name": "Contrast"}
  ],
  "settings.typography.fontFamilies": [
    ...
  ],
  "styles.elements.button.color.background": "var:preset|color|primary",
  "styles.elements.button.color.text": "var:preset|color|base"
}
*** End Patch
```

Pass `expected_current_hash` from the scaffold response's `next_write_hints` to satisfy the safety layer without re-reading.

Choose colors from the user's design direction. If the brief says "editorial, warm palette", pick warm-toned hex values. If the brief says "minimal, monochrome", lean on grays. Do not over-engineer the palette — four colors are enough for a first pass.

## Phase 4 — Templates and patterns

The scaffolded theme has a starting `templates/index.html`. For a landing-page-style site, you'll typically also want a `templates/front-page.html` for the homepage and one or two patterns.

Use `openmira/render-gutenberg-pattern` to compose section markup:

- `hero` for the top section
- `feature-grid` for a three-card section
- `split` or `two-column` for a brief/image section
- `cta` for the closing call-to-action

Each pattern accepts design inputs (colors, headings, body text, button labels) and returns valid Gutenberg block markup. Compose the page by concatenating pattern outputs into the `<!-- wp:` block markup of your target template.

Use `openmira/write-file` to save the assembled template file. Pass `expected_current_hash` from the scaffold hints.

## Phase 5 — Front page

If the user wants a published landing page (not just the theme):

1. Call `openmira/create-gutenberg-page` with `title: "Home"`, `template: "front-page"`, and `sections` containing the assembled block markup.
2. Call `openmira/set-front-page` with the resulting page ID to make it the site's homepage.

`set-front-page` is the safe alternative to `wp option update show_on_front` and `wp option update page_on_front` — use it instead of broadening `run-wpcli`.

## Phase 6 — Verify

Once everything is saved, verify:

1. `openmira/lint-file` on `functions.php` and any PHP you wrote (templates are HTML, so lint doesn't apply).
2. `openmira/probe-url` with the site root URL. Confirm `status: 200`. Use `body_search` to confirm key text from the hero section appears in the rendered HTML.
3. For visual verification, use your MCP client's native browser screenshot tool against the site root. Compare to the user's design direction. If the client doesn't have a native screenshot, suggest the user view the site in their own browser.

## Phase 7 — Iterate

Do not over-polish in the first pass. Stop at "the page renders coherently and matches the design direction at a structural level." Show the result to the user and ask what they want to adjust.

Common iteration requests and the right ability for each:

| User says | You do |
|---|---|
| "Change the primary color to X" | `openmira/apply-patch` updating `settings.color.palette[primary].color` |
| "The hero is too tall" | `openmira/edit-file` on the template, adjust the hero section's spacing attributes |
| "Add a second testimonial" | Re-render the testimonial pattern with the new content, update the template via `edit-file` |
| "The footer doesn't match" | `openmira/render-gutenberg-pattern` for the footer pattern, write to `parts/footer.html` |

## Common pitfalls

- **Don't write `theme.json` via `write-file` after `apply-patch` has touched it.** The hash will be stale. Use another `apply-patch` for the next change.
- **Don't try to capture a visual screenshot through Open Mira for verification.** Use your client's browser tool. Open Mira's screenshot ability is intended for headless/CI workflows, not interactive verification.
- **Don't activate the theme before scaffolding completes.** `scaffold-theme` handles activation atomically; don't race it.
- **Don't pick more than four palette colors initially.** Designers add complexity over time; agents should not pre-emptively bloat the palette.

## Stop conditions

Stop when:

- The site root URL returns 200 with the expected sections in the body
- `lint-file` passes on all written PHP
- The user has seen the result and confirmed it's a coherent first pass

Do not continue refining without explicit user direction. Visual polish is the user's call, not yours.
