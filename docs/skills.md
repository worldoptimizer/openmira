---
title: Skills
description: Open Mira Skills are Markdown MCP Prompts for repeatable WordPress workflows.
---

# Skills

Open Mira ships starter Skills as MCP Prompts. Skills are Markdown documents registered as MCP Prompts that any client supporting `prompts/list` can discover.

## Installed starter skills

| Skill ID | Prompt name | Purpose |
| --- | --- | --- |
| `feedback` | `openmira.feedback` | Compose a sanitized issue report from recent Open Mira activity. |
| `wp-aware-editing` | `openmira.wp-aware-editing` | Follow the canonical safe-edit workflow for WordPress code changes. |
| `build-a-block-theme` | `openmira.build-a-block-theme` | Scaffold and style a WordPress block theme end to end. |

## File format

Built-in skills live at:

```text
includes/skills/<id>/SKILL.md
```

The file starts with YAML-style frontmatter followed by the Markdown prompt body:

```markdown
---
title: "Human-readable title"
description: "Short one-line description for prompt-selection UIs"
---

# Skill Body

Markdown workflow guidance goes here.
```

The folder name is the skill ID. Open Mira accepts lowercase IDs with letters, numbers, dots, underscores, and hyphens, and exposes the MCP prompt as `openmira.<id>` because the bundled MCP Adapter follows the MCP prompt-name grammar.

## Customizing skills

Open Mira loads skills from two built-in sources:

```text
filesystem: includes/skills/<id>/SKILL.md
CPT:        openmira_skill posts
```

Built-in skills live in the plugin directory and update with Open Mira. Custom skills live as private `openmira_skill` posts, survive plugin updates, and get native WordPress revisions. The admin page exposes a **Revisions** action for custom skills once WordPress has at least one revision to show.

If a custom CPT skill uses the same ID as a built-in skill, the custom version wins. The admin page labels that state as **Custom (overrides built-in)** so it is clear that the plugin copy is still present but shadowed.

Use **Open Mira → Skills** to create, edit, delete, import, and export custom skills. Built-in skills are read-only; click **Customize** to create a CPT-backed custom copy before editing. Custom skills also include an **Enable prompt** toggle; disabled skills remain visible in the admin and abilities, but are not registered as MCP Prompts.

Open Mira 1.6.0 migrates legacy 1.5.x files from `wp-content/openmira-skills/<id>/SKILL.md` into CPT storage on first boot. The files are left on disk for one minor version as a safety fallback, but Open Mira no longer loads custom prompts directly from that writable filesystem path.

## Export and import format

Single-skill exports are plain `SKILL.md` files.

Bulk exports are ZIP archives with one top-level folder per custom skill ID:

```text
openmira-skills.zip
├── feedback/
│   └── SKILL.md
└── wp-aware-editing/
    └── SKILL.md
```

This ZIP shape is the stable interchange format for sharing Open Mira skills across local and staging sites. Importing either a single `SKILL.md` file or a ZIP creates or updates CPT-backed custom skills.

## Admin view

Open **Open Mira → Skills** in WordPress to see installed skill titles, IDs, source, prompt names, prompt enablement, descriptions, revision links, and `SKILL.md` previews.
