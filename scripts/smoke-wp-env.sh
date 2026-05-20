#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${OPENMIRA_WP_ENV_BASE_URL:-http://localhost:8888}"
BASE_URL="${BASE_URL%/}"
USERNAME="${OPENMIRA_WP_ENV_USERNAME:-admin}"
PASSWORD="${OPENMIRA_WP_ENV_PASSWORD:-password}"
THEME_SLUG="${OPENMIRA_WP_ENV_THEME_SLUG:-openmira-wp-env-rest-smoke}"
COOKIE_JAR="${TMPDIR:-/tmp}/openmira-wp-env-smoke-cookies.txt"
LOGIN_HTML="${TMPDIR:-/tmp}/openmira-wp-env-login.html"
ADMIN_HTML="${TMPDIR:-/tmp}/openmira-wp-env-admin.html"
ACT_JSON="${TMPDIR:-/tmp}/openmira-wp-env-act.json"
THEME_JSON="${TMPDIR:-/tmp}/openmira-wp-env-theme.json"
ABSPATH_JSON="${TMPDIR:-/tmp}/openmira-wp-env-abspath.json"

require_command() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Missing required command: $1" >&2
    exit 1
  fi
}

require_command curl
require_command docker
require_command jq
require_command rg
require_command wp-env

rm -f "$COOKIE_JAR" "$LOGIN_HTML" "$ADMIN_HTML" "$ACT_JSON" "$THEME_JSON" "$ABSPATH_JSON"

wp-env start
wp-env run cli wp plugin activate openmira >/dev/null
wp-env run cli wp eval '
update_option("openmira_ai_abilities_enabled", "1");
update_option("openmira_ai_abilities_domain", (string) wp_parse_url(home_url(), PHP_URL_HOST));
' >/dev/null

curl -sS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/wp-login.php" > "$LOGIN_HTML"
curl -sS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
  --data-urlencode "log=${USERNAME}" \
  --data-urlencode "pwd=${PASSWORD}" \
  --data-urlencode "wp-submit=Log In" \
  --data-urlencode "redirect_to=${BASE_URL}/wp-admin/admin.php?page=openmira" \
  --data-urlencode "testcookie=1" \
  "$BASE_URL/wp-login.php" > "$ADMIN_HTML"

if ! rg -q 'wp-admin' "$ADMIN_HTML"; then
  echo "wp-env admin login failed for ${USERNAME}." >&2
  exit 1
fi

NONCE="$(rg -o 'createNonceMiddleware\( "[^"]+"' "$ADMIN_HTML" | sed -E 's/.*"([^"]+)"/\1/' | head -1)"
if [[ -z "$NONCE" ]]; then
  echo "Could not discover REST nonce from Open Mira admin page." >&2
  exit 1
fi

ability_post() {
  local ability="$1"
  local payload="$2"
  local output="$3"
  curl -sS -X POST -b "$COOKIE_JAR" -H "X-WP-Nonce: $NONCE" -H 'Content-Type: application/json' \
    "${BASE_URL}/wp-json/wp-abilities/v1/abilities/${ability}/run" --data "$payload" > "$output"
}

ability_post "openmira/set-safety-mode" \
  '{"input":{"mode":"act","ttl_minutes":30}}' \
  "$ACT_JSON"

if [[ "$(jq -r '.mode // empty' "$ACT_JSON")" != "act" ]]; then
  echo "Failed to enable Open Mira Act mode through REST." >&2
  cat "$ACT_JSON" >&2
  exit 1
fi

THEME_PAYLOAD="$(jq -nc --arg slug "$THEME_SLUG" '{input:{type:"block",slug:$slug,name:"Open Mira wp-env REST Smoke",description:"REST smoke theme scaffolded by Open Mira.",design_brief:"Minimal theme for wp-env REST integration coverage.",activate:true,overwrite:true,force_clean:true}}')"
ability_post "openmira/scaffold-theme" "$THEME_PAYLOAD" "$THEME_JSON"

if [[ "$(jq -r '.theme.slug // empty' "$THEME_JSON")" != "$THEME_SLUG" ]]; then
  echo "scaffold-theme REST smoke failed." >&2
  cat "$THEME_JSON" >&2
  exit 1
fi

wp-env run cli wp eval '
$slug = getenv("OPENMIRA_WP_ENV_THEME_SLUG") ?: "openmira-wp-env-rest-smoke";
$root = get_theme_root() . "/" . $slug;
$files = [
    "style.css",
    "functions.php",
    "theme.json",
    "templates/index.html",
    "parts/header.html",
    "parts/footer.html",
];
foreach ($files as $file) {
    if (!file_exists($root . "/" . $file)) {
        fwrite(STDERR, "Missing scaffolded theme file: " . $file . PHP_EOL);
        exit(1);
    }
}
echo wp_json_encode(["status" => "ok", "theme" => $slug, "files" => $files], JSON_PRETTY_PRINT) . PHP_EOL;
' > "$ABSPATH_JSON"

wp-env run cli wp eval-file wp-content/plugins/openmira/tests/smoke/scaffold-theme.php
wp-env run cli wp eval-file wp-content/plugins/openmira/tests/smoke/apply-theme-json-patch.php
wp-env run cli wp eval-file wp-content/plugins/openmira/tests/smoke/search-code-guardrails.php
wp-env run cli wp eval-file wp-content/plugins/openmira/tests/smoke/permission-controls.php
wp-env run cli wp eval-file wp-content/plugins/openmira/tests/smoke/execute-php-runaway.php
wp-env run cli wp eval-file wp-content/plugins/openmira/tests/smoke/audit-diff.php
wp-env run cli wp eval-file wp-content/plugins/openmira/tests/smoke/screenshot-url.php
wp-env run cli wp eval-file wp-content/plugins/openmira/tests/smoke/skills.php
wp-env run cli wp eval-file wp-content/plugins/openmira/tests/smoke/memory-import.php

jq -n \
  --arg theme "$THEME_SLUG" \
  --slurpfile rest "$THEME_JSON" \
  --slurpfile files "$ABSPATH_JSON" \
  '{status:"ok", rest_theme:$theme, scaffold_response:$rest[0].theme.slug, file_check:$files[0].status}'
