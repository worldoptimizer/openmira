---
title: "WP-Aware Editing Workflow"
description: "Use Open Mira's abilities safely and efficiently when editing a WordPress codebase."
---

# WP-Aware Editing Workflow

This skill is the canonical workflow for editing a WordPress codebase through Open Mira. It explains when to use which ability, in what order, and how to recover when something goes wrong. Follow it whenever the user asks you to change, create, or fix code in their WordPress install.

## Phase 1 — Discover before editing

Before you change anything, build a picture of the codebase:

1. Call `openmira/get-project-map` with `fields: ["site", "theme", "plugins", "build_tools", "writable_locations"]`. This gives you the active theme, plugin list, and writable directories without dumping the full file inventory.
2. Call `openmira/read-project-rules`. Project rules live in `.openmirarules.json` at the site root and define naming conventions, text domain, preferred block vs classic theme type, and other project-specific defaults. These rules override your assumptions about how the user wants things named.
3. If the task involves an existing plugin or theme file you have not seen, call `openmira/read-file` on the relevant entry point first. Open Mira's safety layer enforces read-before-write — you cannot edit a file you have not read in the current session, and reading also captures a content hash for stale-write protection.

Do not skip this phase even for "small" changes. The cost is 1–3 tool calls; the value is avoiding wrong assumptions.

## Phase 2 — Choose the right edit ability

Open Mira exposes four editing surfaces. Pick the one that matches the change:

| Change shape | Ability | Why |
|---|---|---|
| Surgical edit to one or two specific lines in an existing file | `openmira/edit-file` | Anchored `old_string`/`new_string` replacement. Returns a unified diff in the response. |
| Creating a new file from scratch | `openmira/write-file` | Pass `expected_current_hash` only if the file already exists (overwriting). For new files, no hash needed. |
| Bulk update to `theme.json` design tokens | `openmira/apply-patch` with `*** Update theme.json (paths, mode: merge):` | The bulk paths form merges many JSON paths in one call. Strongly prefer this over rewriting the whole `theme.json` via `write-file`. |
| Generic find/replace across one file | `openmira/edit-file` with `replace_all: true` | Use when the same string appears multiple times intentionally. |

If the task is creating a new theme, block, or plugin, use the dedicated scaffolders before touching individual files:

- `openmira/scaffold-theme` for block, classic, or child themes
- `openmira/scaffold-block` for PHP-rendered dynamic blocks inside the active theme
- `openmira/graduate-sandbox-plugin` to promote a sandbox plugin into `wp-content/plugins/`

Scaffolders produce activatable output and return `next_write_hints` with `expected_current_hash` values for the files they just wrote. Pass those hashes back when you make follow-up edits.

## Phase 3 — Use `expected_current_hash`

Open Mira's safety layer enforces read-before-write AND content-hash freshness. If a file changed between your read and your write, the edit is rejected.

Two ways to satisfy the hash check:

1. **Read first, edit second.** Open Mira records the hash from your `read-file` call automatically. Subsequent `edit-file` / `write-file` / `apply-patch` in the same session succeed as long as the file hasn't changed in the meantime.
2. **Use the scaffolder's `next_write_hints`.** When `scaffold-theme` or similar returns, the response includes hashes for the files just written. Pass `expected_current_hash` explicitly on your next call to those files — you do not need to re-read.

If you receive a stale-write error, re-read the file and retry. Do not bypass the safety layer.

## Phase 4 — Plan/Act mode

Open Mira may be in Plan mode. In Plan mode, destructive operations (`write-file`, `edit-file`, `delete-file`, `execute-php`, `apply-patch`, the scaffolders) return a structured error telling you to call `openmira/set-safety-mode` with `mode: "act"` first.

When you encounter the Plan-mode error:

1. Show the user a summary of the changes you are about to make.
2. Ask them to confirm.
3. If they confirm, call `openmira/set-safety-mode` with `mode: "act"` and `ttl_minutes: 30` (or longer for substantial work).
4. Retry the edit.

Do not call `set-safety-mode` proactively at the start of a session. Wait until you actually have a change to make and the user has confirmed it.

## Phase 5 — Verify

After every edit, verify before claiming success:

1. `openmira/lint-file` on any PHP file you wrote or edited. It catches syntax errors and runs PHPCS if available. If lint fails, Open Mira's safety layer has already rolled the file back to the pre-write version.
2. If the change affects front-end output, verify in one of three ways:
   - `openmira/probe-url` for a same-site URL — returns status, headers, and a body excerpt. Use `body_search` to confirm specific text appears.
   - Your MCP client's native browser screenshot tool against the public URL (Claude Desktop, Cursor with browser MCP, etc.). This is the recommended path for visual verification.
   - Capture-to-disk via Open Mira's Playwright bridge if you are in a headless environment.
3. If the change involves hooks, call `openmira/find-hook-registrants` for the hook you touched to confirm your callback is registered with the expected priority and source.

## Phase 6 — Recover if needed

Every destructive Open Mira operation creates a backup before it writes. If you make a mistake:

1. Call `openmira/list-file-backups` for the affected path to see recent backups.
2. Call `openmira/restore-file-backup` with the backup ID to revert.
3. The audit log records the restore as a new event; the original mistake is preserved in the log for diagnosis.

You do not need to apologize to the user before recovering. Recover first, then explain.

## What this skill does NOT cover

- Building from a visual reference (use vision intake via your client's native screenshot tool)
- Builder-specific work (Bricks, Elementor) — those require their own deep abilities not yet shipped
- Field-plugin work (ACF, JetEngine, etc.) — not yet shipped
- Patch grammar beyond `theme.json` — only `theme.json` has a patch op today

For those, work with the user to describe the desired outcome and use the general edit abilities (`write-file`, `edit-file`) on the relevant files.
