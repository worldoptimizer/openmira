# 1.7.0 Dynamic Block Editing Checkpoint

Date: 2026-05-28

## Scope

Checkpoint after implementing:

- `openmira/read-blocks`
- dynamic `openmira/patch-blocks`
- block hunks in `openmira/apply-patch`
- compact-discovery demotion of `openmira/patch-gutenberg-blocks`

## Local validation

Static checks:

```text
php -l <changed PHP files>
./vendor/bin/mago format
./vendor/bin/mago analyze
./vendor/bin/mago lint
```

Result: pass. `mago analyze` and `mago lint` report existing advisory warnings but exit successfully.

General Playground smoke:

```text
npm run smoke:playground
```

Result:

```json
{
  "status": "ok",
  "theme": "openmira-smoke-theme-1779961728-1479",
  "block": "openmira-smoke-1779961728-1479/feature-card",
  "page_id": "18",
  "execute_php_guard_count": 1
}
```

Targeted REST smoke against local Playground:

1. Created a page containing the dynamic core block `core/latest-posts`.
2. Called `openmira/read-blocks`.
3. Received virtual ref `v:0:a16e2bc68d41`.
4. Called `openmira/patch-blocks` with `expected_etag`, updating `postsToShow` from `3` to `2`.
5. Verified the returned block had a durable `omr_` ref and updated attributes.

Result:

```json
{
  "status": "ok",
  "post_id": 16,
  "first_ref": "v:0:a16e2bc68d41"
}
```

## wp-env status

`npm run smoke:wp-env:all` is blocked locally because Docker is not installed:

```text
Missing required command: docker
```

The new wp-env smokes are wired into `scripts/smoke-wp-env.sh` for CI:

- `tests/smoke/block-editing.php`
- `tests/smoke/wpcli-async.php`

## Static runtime boundary

Static/core block edits intentionally return `block_runtime_required` in this slice. See `docs/pilots/1.7.0-block-editing/static-runtime-decision.md`.
