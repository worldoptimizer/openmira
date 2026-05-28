# 1.7.0 Block Ref Spike

Date: 2026-05-28

Status: pass.

## Question

Can a custom Open Mira block reference stored at `attrs.metadata._openmira_ref` survive both:

1. PHP `parse_blocks()` -> `serialize_blocks()` -> save -> re-parse.
2. A real Gutenberg editor open -> content edit -> Save cycle.

This is the release gate for the in-content ref persistence mechanism. If the editor strips `_openmira_ref`, the 1.7 block-editing implementation must stop and re-evaluate sidecar post meta.

## Deterministic PHP Smoke

Added `tests/smoke/block-ref-roundtrip.php`.

The smoke creates a post with nested blocks carrying:

- `omr_smoke_group`
- `omr_smoke_heading`
- `omr_smoke_paragraph`

It then parses, serializes, saves, re-parses, and confirms all three refs remain at the expected logical paths. It also duplicates the parsed group block, serializes and re-parses the duplicate tree, and confirms duplicate-ref detection can identify all three duplicated refs.

Result:

```json
{
  "status": "ok",
  "post_id": 4,
  "refs": {
    "omr_smoke_group": [0],
    "omr_smoke_heading": [0, 0],
    "omr_smoke_paragraph": [0, 1]
  },
  "duplicate_refs_detected": [
    "omr_smoke_group",
    "omr_smoke_heading",
    "omr_smoke_paragraph"
  ]
}
```

Validation:

```sh
php -l tests/smoke/block-ref-roundtrip.php
```

Result: no syntax errors.

## Gutenberg Editor Save Gate

Environment: local WordPress Playground at `http://127.0.0.1:9400`, Open Mira mounted from this worktree and activated.

Steps:

1. Opened the smoke post in the Gutenberg editor: `/wp-admin/post.php?post=4&action=edit`.
2. Inserted a new Paragraph block through the editor UI to force a real content save.
3. Clicked the editor Save button.
4. Re-read `post_content` through WordPress and parsed it with `parse_blocks()`.

Result:

```json
{
  "ok": true,
  "post_id": 4,
  "modified_gmt": "2026-05-28 09:08:57",
  "refs": {
    "omr_smoke_group": [0],
    "omr_smoke_heading": [0, 0],
    "omr_smoke_paragraph": [0, 1]
  },
  "missing": []
}
```

Post-save content excerpt:

```html
<!-- wp:group {"metadata":{"_openmira_ref":"omr_smoke_group"}} -->
<div class="wp-block-group"><!-- wp:heading {"metadata":{"_openmira_ref":"omr_smoke_heading"}} -->
<h2 class="wp-block-heading">Open Mira ref smoke heading</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"metadata":{"_openmira_ref":"omr_smoke_paragraph"}} -->
<p>Open Mira ref smoke paragraph.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:paragraph -->
<p></p>
<!-- /wp:paragraph -->
```

## Conclusion

`metadata._openmira_ref` survives both the PHP round-trip and a real Gutenberg editor content-save cycle in the tested WordPress Playground environment. The in-content persisted-on-write mechanism remains viable for Step 2.

Proceeding condition: build `openmira/read-blocks` next, keeping reads side-effect-free.
