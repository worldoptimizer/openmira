---
title: "Block Editing Workflow"
description: "Use Open Mira's block-level editing abilities for surgical Gutenberg content changes."
enable_prompt: true
---

# Block Editing Workflow

This skill is the canonical workflow for editing Gutenberg post content at block level through Open Mira. In 1.7.0, block-level patching is intentionally limited to dynamic/server-rendered blocks. Static/core blocks such as paragraphs, headings, images, groups, columns, and buttons require the browser-backed Block Editor Runtime, which is not shipped in this slice.

Use this workflow when the user asks you to change an existing page, post, template, or reusable content area and the change can be expressed as updating, inserting, or deleting dynamic/server-rendered blocks.

Use this workflow instead of replacing the whole `post_content` when the user wants a focused edit such as:

- Change one card title in a dynamic/server-rendered block.
- Add a new dynamic block after an existing block.
- Delete one server-rendered block from a page.
- Update attributes across a small set of known blocks.
- Preserve the user's manual edits elsewhere in the post.

Keep `openmira/write-gutenberg-content` for cases where a full rewrite is genuinely intended, such as replacing a generated draft page wholesale. For static/core block edits before the Block Editor Runtime ships, use a full rewrite only when the user explicitly accepts that broader replacement.

## Phase 1 — Read blocks first

Start with `openmira/read-blocks` for the target post ID. Request attributes when you need to plan an edit:

```json
{
  "post_id": 123,
  "include_attrs": true,
  "max_depth": 6
}
```

The response returns:

- `etag` — optimistic concurrency token for the current post content.
- `blocks` — a tree of blocks.
- `ref` — stable block reference for each block.
- `ref_source` — `persisted`, `virtual`, or `virtual_duplicate`.
- `dynamic` — whether Open Mira can patch the block directly in PHP.

Reads are side-effect-free. If a block does not yet have a persisted Open Mira ref, `read-blocks` returns a virtual ref like `v:0:d47e11afcab2`. Virtual refs are valid only with the exact `expected_etag` from the read response.

## Phase 2 — Choose the edit path

There are two write paths:

| Block type | Path | What to do |
|---|---|---|
| Dynamic/server-rendered block | `openmira/patch-blocks` | Preferred. Open Mira patches the parsed block tree in PHP and writes one revision. |
| Static/core block | Block Editor Runtime | Required for static serialization. If unavailable, report that the static runtime is needed instead of forcing a PHP serialization. |
| Whole content rewrite | `openmira/write-gutenberg-content` | Use only when replacing the entire post content is the right operation. |

Dynamic blocks include server-rendered blocks registered with a render callback. Open Mira's scaffolded blocks are usually dynamic. Core paragraph, heading, group, columns, image, and button blocks are static and may require browser-backed serialization.

## Phase 3 — Patch dynamic blocks atomically

Call `openmira/patch-blocks` with `post_id`, `expected_etag`, and an `operations` array. A batch is all-or-nothing: if any ref is stale, duplicated, missing, or static-runtime-only, the entire batch aborts before writing. A successful batch creates exactly one WordPress revision.

### Update attributes

```json
{
  "post_id": 123,
  "expected_etag": "omr:...",
  "operations": [
    {
      "operation": "update",
      "ref": "omr_abc123",
      "attrs_mode": "merge",
      "attrs": {
        "heading": "Updated heading",
        "buttonText": "Get started"
      }
    }
  ]
}
```

Use `attrs_mode: "merge"` by default. Use `replace` only when you intentionally want to replace the block's attribute object. Open Mira preserves its internal `metadata._openmira_ref` even when attributes are replaced.

### Insert a dynamic block

Use serialized block markup from the desired dynamic block:

```json
{
  "operation": "insert",
  "after": "omr_abc123",
  "block_markup": "<!-- wp:my-plugin/feature-card {\"heading\":\"New card\"} /-->"
}
```

Insert supports `before`, `after`, or `parent_ref` plus `index`. Prefer `before` or `after` when possible because it is easier for agents to reason about.

### Delete a dynamic block

```json
{
  "operation": "delete",
  "ref": "omr_abc123"
}
```

Do not delete a static/core block through `patch-blocks`. If the ability returns `block_runtime_required`, stop and explain that this edit needs the Block Editor Runtime path.

## Phase 4 — Patch grammar option

For agents already using `openmira/apply-patch`, block hunks can route to `patch-blocks`:

```text
*** Begin Patch
*** Update Block (ref: omr_abc123):
{"attrs":{"heading":"Updated heading"}}
*** Insert Block (after: omr_abc123):
<!-- wp:my-plugin/feature-card {"heading":"New card"} /-->
*** Delete Block (ref: omr_def456):
*** End Patch
```

Pass `post_id` and `expected_etag` alongside the patch call. Do not mix block hunks and `theme.json` hunks in the same patch.

## Phase 5 — Concurrency and stale refs

If `patch-blocks` returns `block_etag_conflict`, the post changed since your read. Re-read with `read-blocks`, re-plan against the new tree, then retry. Do not bypass the conflict.

If it returns `duplicate_block_ref`, the user probably copied a block that already had a ref. Re-read. Open Mira reports duplicate occurrences with virtual refs and rewrites duplicates to new durable refs on the next successful write.

If you use a virtual ref without `expected_etag`, the patch is rejected. This is intentional: virtual refs are only safe when bound to the read snapshot that produced them.

## Phase 6 — Verify

After a patch:

1. Call `openmira/read-blocks` again and confirm the target block attributes changed as intended.
2. If the block is front-end visible, use `openmira/probe-url` to confirm the page still returns 200 and contains expected text where possible.
3. Use your MCP client's native browser/screenshot tool for visual inspection. Open Mira's screenshot jobs are for headless or CI capture-to-disk, not direct agent vision.

## Stop conditions

Stop when the intended block-level edit is applied, the post still renders, and unrelated blocks were not rewritten. Do not keep polishing surrounding content unless the user asks for another pass.
