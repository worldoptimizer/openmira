# 1.7.0 Static Runtime Decision

Date: 2026-05-28

## Decision

Defer the browser-backed Block Editor Runtime from the first 1.7.0 implementation slice.

`openmira/read-blocks`, dynamic `openmira/patch-blocks`, block hunks in `openmira/apply-patch`, and async WP-CLI can ship as the validated 1.7.0 surface. Static/core block patch attempts return a structured `block_runtime_required` error with `runtime_status: not_implemented`.

## Reason

The release's riskiest foundation was stable in-content refs. That spike passed: Gutenberg preserved custom keys under `attrs.metadata._openmira_ref` through a real editor open → edit → save cycle.

The next risk is different: static/core block saved markup belongs to Gutenberg's JavaScript serializer. Open Mira should not synthesize static saved HTML in PHP and risk editor "invalid content" warnings. The dynamic-block fast path is deterministic in PHP and already covers server-rendered blocks, including Open Mira's scaffolded dynamic blocks.

## Implemented in this slice

- `openmira/read-blocks` returns a side-effect-free block tree with durable or virtual refs.
- `openmira/patch-blocks` supports atomic update, insert, and delete for dynamic blocks.
- Virtual refs are accepted only when paired with the current `expected_etag`.
- Durable refs are persisted only on write under `attrs.metadata._openmira_ref`.
- Duplicate persisted refs fail conservatively for direct persisted-ref writes and are normalized on the next successful write.
- `openmira/apply-patch` supports block hunks routed through `patch-blocks`.

## Deferred

- Persistent Block Editor Runtime admin page.
- Static/core block patch finalization through `wp.blocks.parse()` and `wp.blocks.serialize()`.
- `openmira/get-block-patch-job` polling surface, because no static jobs are created yet.
- Direct `move` operation. Agents can use delete plus insert until move semantics are tested.

## Verification expectation

The dynamic path is covered by PHP smoke tests and a local Playground REST smoke. The deferred static runtime still needs the browser verification gate before it is implemented in a later slice.
