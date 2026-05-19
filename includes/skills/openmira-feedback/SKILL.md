---
title: "Open Mira Feedback Report"
description: "Compose a sanitized issue report from recent agent activity in Open Mira."
---

# Open Mira Feedback Report

This skill helps you compose a clean, sanitized issue report when the user wants to share feedback about Open Mira. The report is designed for the user to paste into a GitHub issue or a support email — it must contain enough detail to diagnose the problem, and zero personally identifiable or site-sensitive content.

## When to invoke this skill

Invoke this skill when the user asks for any of:

- "Report a bug to Open Mira"
- "Something didn't work — write up what happened"
- "Send feedback about [an ability that misbehaved]"
- "Generate an issue I can file"

Do not invoke this skill for general questions about Open Mira's capabilities. This skill is for post-hoc bug reporting, not documentation.

## Inputs you need

Before composing the report, gather the following from Open Mira's own abilities — do not ask the user for any of this; pull it from the system:

1. The last 5–10 audit log entries via `openmira/read-audit-log` (this ability exists in Open Mira's safety layer and records every destructive action with timestamp, ability name, status, duration, and diff summary).
2. The current `openmira/get-project-map` summary, filtered to `site` and `theme` fields only.
3. Site WordPress version, PHP version, and Open Mira plugin version from the project map.

If any of those abilities are not available in the current session, note their absence in the report rather than asking the user.

## Redaction rules

Strip all of the following from the report:

- Site hostnames and URLs (replace with `[site-url]`)
- WordPress usernames and email addresses (replace with `[user]`)
- Application passwords, API keys, tokens, secrets (these should never appear in audit logs but verify before sending)
- Post content, comment text, or any user-authored content (replace with `[content-excerpt-redacted]`)
- Plugin license keys (replace with `[license-redacted]`)
- File paths that include the user's home directory or absolute server paths (truncate to relative paths under the WordPress root)

Do not strip:

- WordPress version, PHP version, Open Mira version
- Active theme slug and active plugin slugs (these are public-ish information that helps reproduce)
- Ability names and error codes
- Diff summaries (the small `+N -M` form, not full diff bodies)

## Report format

Compose the report as Markdown using this exact structure:

```
## What I was trying to do

[One sentence describing the user's intent, derived from context]

## What happened

[1–3 sentences describing the observed failure, derived from the audit log entries
and the user's own description of the problem]

## Expected behavior

[1–2 sentences describing what should have happened, inferred from the ability's
documented contract]

## Environment

- Open Mira version: [from project map]
- WordPress: [version]
- PHP: [version]
- Active theme: [slug]
- Active relevant plugins: [list slugs only — no versions unless directly relevant]

## Recent Open Mira activity

[Bullet list of the last 5–10 audit log entries, redacted per rules above.
Format each as: `<timestamp> — <ability> — <status> — <diff summary or error code>`]

## Diagnosis attempt

[1–3 sentences with the agent's hypothesis about the root cause. Be honest
about uncertainty. If unknown, say "Root cause not yet identified."]
```

## What to do with the report

Return the composed Markdown to the user. Tell them:

> "Here's a sanitized report you can review and send to Open Mira support. I have not transmitted this anywhere. Please read through it once to confirm no site-specific information slipped through, then paste it into a GitHub issue at the Open Mira repository or your preferred support channel."

Do not attempt to send the report yourself. Do not call any network ability to transmit it. The user is the gatekeeper.

## Tone

Factual. No apologies. No marketing language. The user is filing a bug, not writing a review.
