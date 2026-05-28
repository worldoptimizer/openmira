---
title: Architecture
description: Technical overview of Open Mira's MCP and WordPress-aware design.
---

# Architecture

Open Mira is a WordPress plugin that exposes a WordPress-aware development surface over MCP. It builds on the WordPress Abilities API and the WordPress MCP Adapter, then adds Open Mira abilities for project context, safe file edits, dynamic Gutenberg block patches, async WP-CLI jobs, external screenshot jobs, WordPress navigation, and agent memory.

## MCP and Abilities API

Open Mira registers abilities such as project-map, read-file, edit-file, search-code, read-blocks, patch-blocks, run-wpcli, screenshot-url, and apply-patch. MCP clients call those abilities through the WordPress REST API using Application Password authentication.

The canonical MCP endpoint is:

```text
/wp-json/mcp/openmira
```

## Project map

The project map gives agents a bounded overview of the WordPress install: active theme, parent/child relationship, plugins, writable locations, build tooling, rules, and relevant file inventory. It is exposed as both an ability and a compact MCP resource.

## Patch grammar

Open Mira includes a V4A-style patch grammar with WordPress semantics. Validated operations include Update theme.json, including bulk path merges and selectors for design-system values, and block hunks for dynamic Gutenberg block patches.

Example shape:

<pre><code>&#42;&#42;&#42; Begin Patch
&#42;&#42;&#42; Update theme.json (paths, mode: merge):
{
  "settings.color.palette": [...],
  "styles.elements.button": {...}
}
&#42;&#42;&#42; End Patch</code></pre>

The server handles path resolution, validation, stale-write checks, backups, audit diffs, and dry-run behavior.

## Block editing

`openmira/read-blocks` reads a post as a side-effect-free block tree. Blocks with durable refs use `attrs.metadata._openmira_ref`; untagged blocks receive virtual refs bound to the current ETag so the first patch can still target them safely.

`openmira/patch-blocks` applies update, insert, and delete operations to dynamic/server-rendered blocks as one atomic batch and one WordPress revision. Static/core blocks return `block_runtime_required` until the browser-backed Block Editor Runtime ships, because PHP should not guess Gutenberg's JavaScript saved markup.

## Async WP-CLI

`openmira/run-wpcli` supports synchronous execution for short allowlisted commands and asynchronous execution for longer jobs. Async jobs store state under `wp-content/openmira-wpcli-jobs/`; `openmira/get-wpcli-job` returns incremental logs and completion status. WP-CLI execution is treated as destructive because WordPress bootstrap and plugin code can run even for read-like commands.

## External screenshot jobs

Open Mira can create screenshot jobs for same-site URLs, but it does not deliver image content directly to MCP agents. The Playwright bridge at `scripts/openmira-complete-screenshot-job.sh` completes jobs and stores PNG/JPEG output under `wp-content/openmira-screenshots/` for human or CI inspection. Use your MCP client’s native browser or vision tooling for agent-visible screenshots.

## Safety layer

Destructive actions flow through shared helpers for permissions, production blocking, Plan/Act mode, file hashes, backups, syntax checks, diagnostics, and audit logging.

## Development and validation

The repo includes wp-env smoke tests and pilot documents. CI runs the smoke suite and release ZIP build on push and pull request.
