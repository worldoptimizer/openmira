# Open Mira

Open Mira is an AGPL WordPress MCP server that gives AI agents controlled development/staging access to WordPress through PHP execution, filesystem operations, builder context, and persistent project memory.

This fork removes upstream Pro upsells/private update coupling and adds clean-room abilities for:

- Gutenberg/block-editor discovery and implementation guidance
- Gutenberg block registry discovery for core and third-party blocks
- Server-side Gutenberg content validation for registered block names, attributes, and parent/ancestor constraints
- Browser-backed Gutenberg serialization jobs for exact editor-generated markup
- Browser-backed Gutenberg block profiling and cache records for safe/unsafe generation decisions
- Bricks Builder detection, template inventory, safe hook/API guidance
- ACF/SCF-compatible field-modeling guidance
- Persistent project memory across AI sessions

Note: WordPress PHP exposes registered block metadata, but exact static block saved HTML is produced by each block editor JavaScript `save()` implementation. Open Mira surfaces that boundary so agents can use server-side registry facts first and editor-side serialization when exact block markup is required.

## Gutenberg serialization flow

Agents can call `openmira/create-gutenberg-serialization-job` with an array of block specs, open the returned admin URL in an authenticated browser, then call `openmira/read-gutenberg-serialization-job` to retrieve exact `wp.blocks.serialize()` output. This avoids vendoring Gutenberg save logic while still supporting core and third-party blocks loaded by the running site.

## Gutenberg profiling flow

Agents can call `openmira/create-gutenberg-block-profile-job` with block names or a namespace filter, open the returned admin URL, then call `openmira/read-gutenberg-block-profile-job` or `openmira/list-gutenberg-block-profiles`. Profiles record whether a block can be safely serialized from an example or empty spec, which attributes are sourced from saved markup, and whether custom attribute generation should use a dedicated adapter instead of blind JSON. Profiles also include generation-quality signals so agents can distinguish valid-but-empty primitives from blocks that are safe and visually useful without a recipe.

## Project memory

Agents can call `openmira/read-memory`, `openmira/write-memory`, and `openmira/delete-memory` to persist durable project facts in WordPress options. Site administrators can review, edit, delete, clear, and export those entries from **Open Mira → Memory** in the WordPress admin.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[AGPL-3.0-or-later](https://www.gnu.org/licenses/agpl-3.0.html)
