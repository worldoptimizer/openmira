---
title: Safety
description: Safety controls for evaluating and operating Open Mira.
---

# Safety

Open Mira gives AI agents powerful access to WordPress. The correct deployment target is a local or staging copy. The safety model makes risk visible and recoverable; it does not make production automation safe by default.

## Production block

Define `OPENMIRA_BLOCK_PRODUCTION` in `wp-config.php` to block abilities on production-looking sites. Environment detection is filterable for teams with custom staging domains.

```php
define('OPENMIRA_BLOCK_PRODUCTION', true);
```

## Per-ability capability filters

Site owners can restrict individual abilities with `openmira_ability_capability`.

```php
add_filter('openmira_ability_capability', function ($capability, $ability_name) {
    if ($ability_name === 'openmira/execute-php') {
        return 'manage_network_options';
    }
    return $capability;
}, 10, 2);
```

## Plan/Act mode

Plan/Act mode can require an explicit temporary Act state before destructive abilities run. This covers writes, deletes, restores, PHP execution, builder writes, and patch operations.

## Hash-guarded writes

Open Mira tracks file hashes and can reject stale writes when a file changed after the agent read it. This prevents accidental overwrites from old context.

## Backups and audit diffs

File-changing abilities create rollback points and audit events. The Audit Log shows targets, statuses, backup IDs, and expandable full diffs.

## Runaway protection

`execute-php` has per-user call limits and memory-delta guards. Responses include guard metadata so agents can see when they are approaching the cap.

## Search guardrails

Broad code scans are capped or rejected unless explicitly allowed. This prevents runaway scans from closing MCP connections or overloading clients.

## Practical rule

Use Open Mira on staging, inspect diffs and screenshots, then deploy through your normal release process.
