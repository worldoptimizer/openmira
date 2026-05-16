---
title: Architecture
description: Technical overview of Open Mira's MCP and WordPress-aware design.
---

# Architecture

Open Mira is a WordPress plugin that exposes a WordPress-aware development surface over MCP. It builds on the WordPress Abilities API and the WordPress MCP Adapter, then adds Open Mira abilities for project context, safe file edits, screenshots, WordPress navigation, and agent memory.

## MCP and Abilities API

Open Mira registers abilities such as project-map, read-file, edit-file, search-code, screenshot-url, and apply-patch. MCP clients call those abilities through the WordPress REST API using Application Password authentication.

The canonical MCP endpoint is:

```text
/wp-json/mcp/openmira
```

## Project map

The project map gives agents a bounded overview of the WordPress install: active theme, parent/child relationship, plugins, writable locations, build tooling, rules, and relevant file inventory. It is exposed as both an ability and a compact MCP resource.

## Patch grammar

Open Mira includes a V4A-style patch grammar with WordPress semantics. The first validated operation is `*** Update theme.json`, including bulk path merges and selectors for design-system values.

Example shape:

```text
*** Begin Patch
*** Update theme.json (paths, mode: merge):
{
  "settings.color.palette": [...],
  "styles.elements.button": {...}
}
*** End Patch
```

The server handles path resolution, validation, stale-write checks, backups, audit diffs, and dry-run behavior.

## Browser-assisted jobs

Some WordPress facts only exist in the browser: Gutenberg static block serialization and actual visual output. Open Mira uses browser-assisted jobs for those cases. The agent creates a job, a browser-capable client completes it, and Open Mira stores the result as a protected image or markup resource.

## Safety layer

Destructive actions flow through shared helpers for permissions, production blocking, Plan/Act mode, file hashes, backups, syntax checks, diagnostics, and audit logging.

## Development and validation

The repo includes wp-env smoke tests and pilot documents. CI runs the smoke suite and release ZIP build on push and pull request.
