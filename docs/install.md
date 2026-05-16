---
title: Install Open Mira
description: Install and connect Open Mira to an MCP-capable AI client.
---

# Install Open Mira

Install from the built release ZIP. Do not use GitHub's source-code ZIP; source ZIPs do not include the bundled Composer dependencies required by the MCP Adapter.

<div class="callout">
  <strong>Latest release:</strong> <a href="https://github.com/worldoptimizer/openmira/releases/latest">https://github.com/worldoptimizer/openmira/releases/latest</a>
</div>

## 1. Download the release ZIP

Download the evergreen release ZIP: [`openmira.zip`](https://github.com/worldoptimizer/openmira/releases/latest/download/openmira.zip). This URL always points at the latest published release asset.

## 2. Upload to WordPress

1. In WordPress admin, go to **Plugins → Add New Plugin → Upload Plugin**.
2. Choose the `openmira.zip` file.
3. Click **Install Now**.
4. Activate **Open Mira**.

## 3. Enable AI Abilities

Go to **Open Mira → Configuration**.

1. Turn on **AI Abilities**.
2. Confirm the warning only if this is a local or staging copy.
3. Keep AI Abilities off on production. For a hard block, define `OPENMIRA_BLOCK_PRODUCTION` in `wp-config.php`.

## 4. Create or paste an Application Password

Open Mira uses WordPress Application Passwords for MCP authentication.

- If WordPress can generate one, use the **Application Password** step in the Configuration page.
- If you already have one, paste it into the existing-password field.
- If Application Passwords are disabled by a security plugin or environment rule, the Configuration page shows the specific blocker.

## 5. Configure your MCP client

Use the generated client instructions on **Open Mira → Configuration**. The canonical endpoint is:

```text
/wp-json/mcp/openmira
```

The legacy endpoint remains available for older configs:

```text
/wp-json/mcp/mcp-adapter-default-server
```

Set your AI client to require confirmation before write operations. Open Mira can execute PHP and change files; treat it like giving an agent shell access to a WordPress staging site.

## 6. Smoke-test the connection

Ask your AI client to:

1. Read Open Mira memory.
2. Get the project map.
3. List available Open Mira abilities.
4. Stay in Plan mode until you explicitly approve Act mode.

If those succeed, the MCP connection is working.
