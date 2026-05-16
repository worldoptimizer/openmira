---
title: Open Mira — WordPress-aware IDE for AI agents
description: WordPress-aware MCP development toolkit for AI agents.
---

<section class="hero">
  <div>
    <h1>WordPress-aware development tools for AI agents.</h1>
    <p class="lead">Open Mira turns a local or staging WordPress site into an MCP server with WordPress-native abilities for themes, plugins, hooks, screenshots, project memory, and guarded file edits.</p>
    <div class="actions">
      <a class="button primary" href="https://github.com/worldoptimizer/openmira/releases/latest">Download latest release</a>
      <a class="button" href="{{ '/install/' | relative_url }}">Install guide</a>
      <a class="button" href="https://github.com/worldoptimizer/openmira#readme">README</a>
    </div>
  </div>
  <div class="hero-art">
    <picture>
      <source srcset="{{ '/assets/brand/openmira-mascot-900.webp' | relative_url }}" type="image/webp">
      <img src="{{ '/assets/brand/openmira-mascot-900.png' | relative_url }}" alt="Open Mira mascot" width="900" height="900">
    </picture>
  </div>
</section>

## Who it is for

Open Mira is for developers and evaluators who want AI agents to work inside WordPress with WordPress context, not just generic file access. It is intended for local and staging sites, with explicit guardrails for production-looking installs.

<div class="grid">
  <div class="card">
    <h3>Build WordPress sites</h3>
    <p>Scaffold themes and blocks, write CSS and templates, update <code>theme.json</code>, and validate front-end output through screenshots.</p>
  </div>
  <div class="card">
    <h3>Maintain plugins</h3>
    <p>Search scoped code, inspect hooks, patch PHP, lint files, probe anonymous URLs, and promote tested sandbox plugins.</p>
  </div>
  <div class="card">
    <h3>Keep safety visible</h3>
    <p>Use Plan/Act mode, hash-guarded writes, backups, expandable audit diffs, capability filters, and runaway protection.</p>
  </div>
</div>

## Current source of truth

The shipped capability claims are intentionally narrow and evidence-based. See [Capabilities]({{ '/capabilities/' | relative_url }}) for the validation table and [Safety]({{ '/safety/' | relative_url }}) for deployment guardrails.
