# Open Mira

Open Mira is an AGPL WordPress MCP server for AI-assisted WordPress development. It gives capable AI agents a WordPress-aware IDE surface for staging and local sites: inspect the project, edit files safely, build themes and blocks, fix plugins, work with hooks, capture screenshots, and keep durable project memory.

Open Mira is not a generic WordPress shell. It keeps the generic escape hatches available, but its value is the WordPress-specific layer around them: hash-guarded writes, backups, audit diffs, project maps, hook navigation, theme scaffolding, `theme.json` patch grammar, browser-assisted screenshots, and production safety controls.

## Install

Install Open Mira from a built release ZIP, not GitHub's source-code ZIP.

1. Download `openmira-<version>.zip` from the [GitHub Releases page](https://github.com/worldoptimizer/openmira/releases).
2. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**.
3. Upload the ZIP, activate Open Mira, then open **Open Mira → Configuration**.

The release ZIP includes Composer dependencies under `vendor/`. A source checkout does not; if you clone the repo for development, run Composer before activating the plugin.

## Setup

1. Enable **AI Abilities** on a local or staging copy.
2. Create or paste a WordPress Application Password.
3. Copy the generated MCP client configuration from **Open Mira → Configuration** into your AI client.
4. Keep your AI client in a confirmation/approval mode for write operations.

Open Mira exposes the canonical MCP endpoint at `/wp-json/mcp/openmira`. The previous `/wp-json/mcp/mcp-adapter-default-server` alias remains available for compatibility.

## Validated Capabilities

The current surface has been validated through repeatable local pilots and wp-env smoke coverage:

| Workflow | Status |
| --- | --- |
| Theme and landing-page development | Validated |
| WordPress `theme.json` patch grammar | Validated |
| Browser-assisted screenshot feedback loop | Validated |
| Vision-based design intake from screenshots | Validated |
| Plugin bug fixing in real third-party plugins | Validated |
| Hook conflict navigation and repair | Validated |
| Plugin creation in the Open Mira sandbox | Validated |
| Sandbox plugin promotion and activation | Validated |
| Persistent project memory | Validated |

Open Mira intentionally does not claim a universal patch operation for every WordPress concept. The patch grammar is currently strongest where it earned its place: `theme.json` design-system updates.

## Safety Model

Open Mira is designed for development and staging environments.

- **Production guard:** define `OPENMIRA_BLOCK_PRODUCTION` to block abilities on production-looking sites.
- **Capability filters:** site owners can change the required capability per ability with `openmira_ability_capability`.
- **Plan/Act gate:** destructive abilities can require an explicit temporary Act mode.
- **Hash-guarded writes:** file edits can require a fresh read hash or expected current hash.
- **Backups and audit log:** file-changing abilities keep rollback points and expandable full diffs.
- **PHP execution guardrails:** `execute-php` has per-user rate limits and memory-delta protection.
- **Search guardrails:** broad project scans are capped or rejected unless explicitly allowed.

These controls reduce risk; they do not make live-site agent automation safe by default. Make changes on a staging copy, review them, then deploy normally.

## Gutenberg and Browser-Assisted Workflows

WordPress PHP exposes registered block metadata, but exact static block saved HTML is produced by each block editor JavaScript `save()` implementation. Open Mira surfaces that boundary instead of vendoring Gutenberg internals.

Agents can use browser-backed jobs to serialize blocks, profile loaded block libraries, and capture screenshots through authenticated WordPress admin pages. Screenshot results are exposed as MCP resources to avoid sending large base64 images through normal tool output.

## Project Memory

Agents can call `openmira/read-memory`, `openmira/write-memory`, and `openmira/delete-memory` to persist durable project facts in WordPress options. Site administrators can review, edit, delete, clear, and export entries from **Open Mira → Memory**.

## Development

For local development:

```bash
composer install
npm ci
./vendor/bin/mago format && ./vendor/bin/mago analyze && ./vendor/bin/mago lint
npm run smoke:wp-env:all
```

The wp-env smoke suite runs automatically in GitHub Actions on pushes and pull requests. Pilot briefs and results live in [`docs/pilots/`](docs/pilots/) for evaluators who want to inspect how the current capability claims were tested.

To build an installable ZIP:

```bash
scripts/build-release.sh 1.3.0
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[AGPL-3.0-or-later](https://www.gnu.org/licenses/agpl-3.0.html)
