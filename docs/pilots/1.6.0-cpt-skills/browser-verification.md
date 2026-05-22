# Open Mira 1.6.0 CPT Skills — Browser Verification Notes

Date: 2026-05-22

## Result

Browser verification was attempted against a disposable `wp-now` WordPress instance at `http://localhost:9401`, but a complete Skills admin walkthrough could not be executed locally because the runtime did not provide a stable WordPress Abilities API/MCP Adapter load path for Open Mira.

## What was attempted

1. Started `wp-now` on port `9401` with PHP 8.2.
2. Opened `/wp-admin/admin.php?page=openmira-skills` in a real browser.
3. Initial result: WordPress rendered `Sorry, you are not allowed to access this page` because Open Mira returns before registering admin menus when `WP_Ability` is unavailable.
4. Installed/activated local runtime dependencies for verification only:
   - `abilities-api` from `WordPress/abilities-api` release `v0.4.0`
   - `mcp-adapter` from `WordPress/mcp-adapter` release `v0.5.0`
   - `enable-abilities-for-mcp` from the WordPress plugin directory
5. Restarted `wp-now` and retried. The local server changed from plugin mode to wp-content mode after temporary dependency folders were added, which invalidated the plugin activation path.
6. Tried a must-use bootstrap shim to force dependency/menu load order for browser-only verification. `wp-now`'s virtual filesystem did not reliably pick up the shim changes without changing serving mode, so the real Skills admin page still could not be rendered.

## Local browser evidence

The repeated real-browser symptom was:

```text
/wp-admin/admin.php?page=openmira-skills
WordPress › Error
Sorry, you are not allowed to access this page.
```

This is an environment bootstrap failure, not evidence that the Skills page itself fails: static PHP syntax and Mago validation pass, and the Skills admin hook regression remains covered by `tests/smoke/skills.php` for the wp-env/CI runtime where Abilities API is available.

## Follow-up required before merge

Run the browser checklist in CI-backed `wp-env` or another WordPress environment where `abilities-api`, `mcp-adapter`, and Open Mira load in the expected order:

- Navigate to `Open Mira → Skills`.
- Create a CPT-backed custom skill.
- Toggle **Enable prompt** off/on.
- Customize the built-in `feedback` skill and confirm it becomes a CPT override.
- Edit the customized skill twice and confirm the **Revisions** link appears.
- Export and import a custom skill ZIP.
- Confirm browser console has no red errors.

