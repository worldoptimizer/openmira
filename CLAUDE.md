## What This Is

Novamira gives AI agents **unrestricted control over a WordPress installation** through the WordPress Abilities API + MCP Adapter. The core idea: with arbitrary PHP execution and full filesystem access, an agent can do *anything* WordPress can do — install plugins, modify themes, query the database, call external APIs, create custom functionality on the fly. The abilities are intentionally unconstrained building blocks; the plugin's value is that it turns a WordPress site into a fully programmable environment for AI.

Requires WordPress 6.9+. The MCP Adapter is bundled as a Composer dependency (`wordpress/mcp-adapter`, at `vendor/wordpress/mcp-adapter/`).

## Code Quality

All code changes must pass these before committing:

```sh
./vendor/bin/mago format    # auto-format (print-width 120)
./vendor/bin/mago lint      # lint checks
./vendor/bin/mago analyze   # static analysis (PHP 8.0, includes WP stubs)
```

Mago config is in `mago.toml`. Source paths: `includes/` and `novamira.php`. WordPress stubs are in `vendor/php-stubs/wordpress-stubs` and `stubs/`.


NEVER modify release-info.json by hand unless explicitly instructed to do so, that is modified programmatically
