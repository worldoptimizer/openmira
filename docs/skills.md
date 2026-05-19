---
title: Skills
description: Open Mira Skills are Markdown MCP Prompts for repeatable WordPress workflows.
---

# Skills

Open Mira ships starter Skills as MCP Prompts. Skills are Markdown documents at `includes/skills/<id>/SKILL.md`, registered as MCP Prompts that any client supporting `prompts/list` can discover. The three starter skills are listed in the Skills admin page; add your own by creating a new `<id>/SKILL.md` file.

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

The folder name is the skill ID. Open Mira accepts lowercase, hyphenated IDs and exposes the MCP prompt as `openmira.<id>` because the bundled MCP Adapter follows the MCP prompt-name grammar, which allows letters, numbers, underscores, dots, and hyphens.

## Admin view

Open **Open Mira → Skills** in WordPress to see installed skill titles, IDs, prompt names, descriptions, and read-only `SKILL.md` previews.
