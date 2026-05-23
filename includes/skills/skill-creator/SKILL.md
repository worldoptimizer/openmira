---
title: "Skill Creator"
description: "Guidance for creating and refining Open Mira skills for WordPress-aware agent workflows."
enable_prompt: true
---

<!--
SPDX-FileCopyrightText: 2024 Anthropic, PBC
SPDX-FileCopyrightText: 2026 Open Mira contributors
SPDX-License-Identifier: Apache-2.0 AND AGPL-3.0-or-later

Adapted from Anthropic's skill-creator skill for Open Mira's flat SKILL.md format.
-->

# Skill Creator

This skill helps create or refine Open Mira Skills. A Skill is a Markdown document that teaches the agent a reusable workflow: when to use it, what context to gather, which Open Mira abilities to call, what output to produce, and where to stop. Open Mira stores built-in skills as flat `SKILL.md` files and custom skills as CPT-backed records, so this workflow assumes one self-contained document rather than bundled scripts, references, or assets.

Use this skill when the user asks to:

- Create a new Open Mira Skill
- Improve an existing Skill description or workflow
- Turn a repeated WordPress-agent procedure into reusable guidance
- Review whether a Skill triggers at the right time
- Trim a Skill that has grown too broad or too verbose

Do not use this skill to invent product capabilities. A Skill can guide the agent through abilities that exist today; it cannot make unshipped tools reliable by describing them optimistically.

## Core principles

### Concise is key

The agent reads Skill bodies into context when the Skill triggers. Long Skills crowd out the project context, code snippets, audit trail, and user instructions needed to do the work. Keep the document short enough to be useful in the middle of a real task. Prefer direct workflow instructions over explanation.

Good Skills usually include:

- The exact trigger context
- Required inputs
- A short ordered workflow
- Ability-choice guidance
- Verification steps
- Stop conditions

Avoid background essays, duplicated documentation, and exhaustive edge-case catalogs. If the workflow needs a large reference, it probably belongs in Open Mira documentation or a future ability, not inside a Skill body.

### Description as trigger

The frontmatter `description` is the primary selection signal for MCP clients that expose prompts. It should say what the Skill does and when to use it. Be slightly explicit; under-triggering is more common than over-triggering for workflow Skills.

Weak:

> Helps with WordPress content.

Better:

> Use when creating or revising WordPress post excerpts in bulk through Open Mira, including reading project rules, editing content safely, and verifying output.

The body can restate the trigger briefly, but the description must carry the selection burden.

### Set appropriate degrees of freedom

A Skill should constrain the parts of the workflow that matter and leave room where judgment is useful. Over-constrained Skills produce brittle behavior; under-constrained Skills become vague advice.

Constrain:

- Safety requirements, such as read-before-write and verification
- Required output formats, such as a bug report template
- Preferred Open Mira abilities for a known change shape
- Stop conditions that prevent endless polishing

Leave flexible:

- Exact copy, unless the user supplied it
- Design details, unless the Skill is about a specific design system
- Whether to ask a clarifying question when the missing input is genuinely blocking

### Principle of lack of surprise

The Skill must do what its title and description imply. Do not hide destructive behavior, network transmission, credential handling, or data export inside a Skill. If a workflow sends data outside the WordPress install, the Skill must explicitly require user confirmation and make the user the gatekeeper.

## Creating a Skill

### 1. Capture intent

Start by extracting as much as possible from the current conversation before asking questions. The user may have already described the repeated workflow, the mistakes they corrected, and the desired output.

Confirm only the minimum missing inputs:

1. What should this Skill help the agent do?
2. When should it trigger?
3. What Open Mira abilities should it prefer or avoid?
4. What does successful output look like?
5. What should the agent verify before stopping?

For example, if the user says "make a Skill for debugging shortcode plugins," ask whether the workflow is for fixing existing plugins, creating new shortcodes, or both. Those are different Skills.

### 2. Choose a narrow name and ID

Use a short lowercase ID with hyphens, dots, or underscores, starting with a letter or number. Keep it stable; IDs become prompt names.

Examples:

- `shortcode-debugging`
- `bulk-excerpt-rewrite`
- `staging-release-check`
- `block-theme-first-pass`

Avoid brand repetition such as `openmira-feedback` when the prompt namespace already adds `openmira.`.

### 3. Write frontmatter

Use this shape:

```markdown
---
title: "Short Human Title"
description: "Use when the agent should perform a specific WordPress-aware workflow through Open Mira."
enable_prompt: true
---
```

Set `enable_prompt: false` only when the Skill should remain installed but hidden from MCP prompt registration.

### 4. Write the body

Structure the body for use during an active task:

```markdown
# Skill Title

One paragraph defining the workflow.

## When to use this skill

- Concrete trigger
- Concrete trigger

## Inputs

What the agent needs before acting.

## Workflow

1. Discover context
2. Choose the right ability
3. Edit or create
4. Verify
5. Report

## Stop conditions

When to stop and hand back to the user.
```

Prefer Open Mira-specific ability names where they matter: `openmira/get-project-map`, `openmira/read-project-rules`, `openmira/read-file`, `openmira/edit-file`, `openmira/write-file`, `openmira/apply-patch`, `openmira/probe-url`, `openmira/lint-file`, and hook/search abilities when relevant. Do not mention abilities that are not shipped.

### 5. Use WordPress-flavored examples

Examples should match the environment where Open Mira operates.

Good examples:

- Bulk-rewrite WordPress post excerpts while preserving tone and length
- Add a shortcode attribute to a small plugin and verify rendered output
- Build a first-pass block theme landing page
- Compose a sanitized Open Mira bug report from audit entries

Avoid generic file-system examples unless the Skill is truly about generic files.

## Refining a Skill

Read the Skill as if the agent has already triggered it in the middle of a real WordPress task. Ask:

- Does the description make the trigger obvious?
- Does the body tell the agent what to do first?
- Are required abilities named accurately?
- Are safety checks explicit?
- Is there a clear stop condition?
- Can any paragraph be deleted without changing behavior?

If the Skill caused the agent to make a bad choice, fix the exact ambiguity that led to the choice. Do not add a broad warning section unless the failure is likely to repeat.

## Testing a Skill

Use 2–3 realistic prompts. Prefer prompts a user would actually type:

- "Create a reusable workflow for fixing shortcode rendering bugs in small plugins."
- "Turn our release checklist into an Open Mira Skill."
- "Make a Skill for building a first-pass landing page theme, but stop before visual polish."

Run the workflow mentally or in a controlled test install. Check whether the Skill triggers naturally, calls the right Open Mira abilities, avoids unshipped capabilities, and stops at the right point. If it does not, revise the description first, then the body.

## Final checklist

Before saving the Skill:

- The ID is stable and not redundant with `openmira.`
- Frontmatter has title, description, and `enable_prompt`
- The description states when to use the Skill
- The body is self-contained and flat
- No references to missing scripts, assets, or reference files remain
- Examples are WordPress-specific
- Verification and stop conditions are explicit
- The Skill does not claim Open Mira can do something it cannot do today
