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

Each skill lives at:

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

Open Mira loads skills from two locations:

```text
includes/skills/<id>/SKILL.md
wp-content/openmira-skills/<id>/SKILL.md
```

Built-in skills live in the plugin directory and update with Open Mira. Custom skills live under `wp-content/openmira-skills/`, survive plugin updates, and are the right place for site-specific workflows.

If a custom skill uses the same ID as a built-in skill, the custom version wins. The admin page labels that state as **Custom (overrides built-in)** so it is clear that the plugin copy is still present but shadowed.

Use **Open Mira → Skills** to create, edit, delete, import, and export custom skills. Built-in skills are read-only; click **Customize** to copy a built-in `SKILL.md` into `wp-content/openmira-skills/<id>/SKILL.md` before editing.

## Export and import format

Single-skill exports are plain `SKILL.md` files.

Bulk exports are ZIP archives with one top-level folder per skill ID:

```text
openmira-skills.zip
├── feedback/
│   └── SKILL.md
└── wp-aware-editing/
    └── SKILL.md
```

This ZIP shape is the stable interchange format for sharing Open Mira skills across local and staging sites.

## Admin view

Open **Open Mira → Skills** in WordPress to see installed skill titles, IDs, prompt names, descriptions, source badges, and `SKILL.md` previews.
