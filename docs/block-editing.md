---
title: Block Editing
description: Ref-addressed Gutenberg block editing in Open Mira.
---

# Block Editing

Open Mira 1.7 adds ref-addressed Gutenberg block editing for focused changes to existing post content. Instead of replacing an entire `post_content` value, agents can read a post as a block tree, target specific blocks by ref, and apply an atomic batch of dynamic-block operations.

## What ships in 1.7

| Capability | Status |
| --- | --- |
| `openmira/read-blocks` | Reads a side-effect-free block tree with refs, attrs, dynamic/static labels, and an ETag. |
| `openmira/patch-blocks` | Applies `update`, `insert`, and `delete` batches for dynamic/server-rendered blocks in PHP. |
| Block hunks in `openmira/apply-patch` | Routes `*** Update Block`, `*** Insert Block`, and `*** Delete Block` hunks through `patch-blocks`. |
| Static/core block runtime | Deferred. Static blocks return `block_runtime_required` rather than risking invalid Gutenberg markup. |
| `move` operation | Deferred. Use delete plus insert until direct move coverage is justified. |

## Refs and side-effect-free reads

`read-blocks` never writes to the post. Blocks that already have a durable Open Mira ref return refs such as:

```text
omr_cd4411a6c8164f94b1a68099b262a2fb
```

Blocks without a durable ref return a virtual ref bound to the current ETag:

```text
v:0:d47e11afcab2
```

Virtual refs are safe for the first write only when the patch call also includes the exact `expected_etag` from `read-blocks`. On a successful write, Open Mira persists durable refs under Gutenberg's supported `metadata` attribute:

```html
<!-- wp:group {"metadata":{"_openmira_ref":"omr_..."}} -->
```

The 1.7 spike verified that Gutenberg preserves custom keys inside `metadata` through a real editor open → edit → save cycle.

## Dynamic patch operations

`patch-blocks` accepts an atomic operations array. If any operation fails, nothing is written. A successful batch creates one WordPress revision.

### Update

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
        "heading": "Updated heading"
      }
    }
  ]
}
```

### Insert

```json
{
  "operation": "insert",
  "after": "omr_abc123",
  "block_markup": "<!-- wp:my-plugin/feature-card {\"heading\":\"New\"} /-->"
}
```

### Delete

```json
{
  "operation": "delete",
  "ref": "omr_abc123"
}
```

## Patch grammar

Agents can also use block hunks through `openmira/apply-patch`:

```text
*** Begin Patch
*** Update Block (ref: omr_abc123):
{"attrs":{"heading":"Updated heading"}}
*** Insert Block (after: omr_abc123):
<!-- wp:my-plugin/feature-card {"heading":"New"} /-->
*** Delete Block (ref: omr_def456):
*** End Patch
```

Pass `post_id` and `expected_etag` alongside the patch. Do not mix block hunks with `theme.json` hunks in one patch.

## Concurrency

`patch-blocks` uses an ETag derived from post modified time plus content hash. If the post changed since the read, Open Mira returns `block_etag_conflict`; re-read and retry against the new block tree.

Duplicate refs are treated conservatively. If a user copy/pastes a block with an existing ref, persisted-ref writes fail with `duplicate_block_ref` until the agent re-reads and targets the duplicate's virtual ref. On the next successful write, duplicates are rewritten with unique durable refs.

## Static blocks

Static/core blocks are not patched in PHP in 1.7. Returning `block_runtime_required` is intentional: static block saved HTML belongs to Gutenberg's JavaScript serializer, and forcing PHP serialization can produce editor validation warnings. Use full-content replacement only when the user explicitly wants a wholesale rewrite, or wait for the browser-backed Block Editor Runtime path.
