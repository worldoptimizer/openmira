---
title: Capabilities
description: Validated Open Mira capability claims.
---

# Capabilities

This table is the source of truth for what is actually shipped and validated. Open Mira does not claim a universal WordPress automation layer; capabilities are added only when pilots or smoke tests justify them.

| Workflow | Status | Evidence shape |
| --- | --- | --- |
| Theme and landing-page development | Validated | Repeated external-client pilots built and refined Gutenberg/block-theme pages. |
| WordPress `theme.json` patch grammar | Validated | Bulk path merges and WordPress-aware selectors reduced repeated file rewrites. |
| Browser-assisted screenshot feedback loop | Validated | Agents created screenshot jobs, consumed image resources, and iterated visually. |
| Vision-based design intake from screenshots | Validated | Agent rebuilt a page from a screenshot without reading source HTML. |
| Plugin bug fixing in real third-party plugins | Validated | Agent diagnosed, patched, linted, and verified real plugin issues. |
| Hook conflict navigation and repair | Validated | Agent used hook registrant discovery to locate callback priorities and fix conflicts. |
| Plugin creation in the Open Mira sandbox | Validated | Agent created working sandbox plugins before promotion. |
| Sandbox plugin promotion and activation | Validated | Agent promoted sandbox plugins into `wp-content/plugins`, activated, and verified output. |
| Persistent project memory | Validated | Memory abilities and admin UI persist durable project facts across sessions. |

## Explicit non-claim

Hook navigation is useful. A dedicated `*** Add Hook Callback:` patch operation is not currently justified for v1. In plugin pilots, normal `edit-file` was sufficient once Open Mira identified the relevant callback line.

## Useful ability groups

- Project context: `openmira/get-project-map`, memory resources, project rules.
- Safe file work: read/write/edit/delete, hash guards, backups, audit diffs.
- WordPress navigation: hook callers, hook registrants, template resolution, code search.
- Theme work: scaffold theme, scaffold block, `theme.json` patch grammar.
- Verification: lint file, WP-CLI allowlist, probe URL, screenshot URL resources.
- Sandbox: execute PHP, sandbox plugins, graduate sandbox plugin.
