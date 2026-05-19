#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${OPENMIRA_SMOKE_BASE_URL:-http://127.0.0.1:9400}"
ADMIN_URL="${BASE_URL}/wp-admin/admin.php?page=openmira"
ABILITIES_URL="${BASE_URL}/wp-json/wp-abilities/v1/abilities"
COOKIE_JAR="${TMPDIR:-/tmp}/openmira-smoke-cookies.txt"
ADMIN_HTML="${TMPDIR:-/tmp}/openmira-smoke-admin.html"
RUN_SUFFIX="$(date -u +%s)-$$"
THEME_SLUG="openmira-smoke-theme-${RUN_SUFFIX}"
BLOCK_NAMESPACE="openmira-smoke-${RUN_SUFFIX}"
PAGE_SLUG="openmira-smoke-page-${RUN_SUFFIX}"
rm -f "$COOKIE_JAR" "$ADMIN_HTML"

require_command() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Missing required command: $1" >&2
    exit 1
  fi
}

require_command curl
require_command jq
require_command rg

curl -sS -c "$COOKIE_JAR" -b "$COOKIE_JAR" -I "$BASE_URL/" >/dev/null
curl -sSL -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$ADMIN_URL" > "$ADMIN_HTML"
NONCE="$(rg -o 'createNonceMiddleware\( "[^"]+"' "$ADMIN_HTML" | sed -E 's/.*"([^"]+)"/\1/' | head -1)"
if [[ -z "$NONCE" ]]; then
  echo "Could not discover WordPress REST nonce from ${ADMIN_URL}" >&2
  exit 1
fi

ability_get() {
  local ability="$1"
  local output="$2"
  shift 2
  curl -sS -G -b "$COOKIE_JAR" -H "X-WP-Nonce: $NONCE" \
    "$ABILITIES_URL/${ability}/run" "$@" > "$output"
}

ability_post() {
  local ability="$1"
  local payload="$2"
  local output="$3"
  curl -sS -X POST -b "$COOKIE_JAR" -H "X-WP-Nonce: $NONCE" -H 'Content-Type: application/json' \
    "$ABILITIES_URL/${ability}/run" --data "$payload" > "$output"
}

ability_post "openmira/set-safety-mode" \
  '{"input":{"mode":"act","ttl_minutes":30}}' \
  "${TMPDIR:-/tmp}/openmira-smoke-act.json"

THEME_PAYLOAD="$(jq -nc --arg slug "$THEME_SLUG" '{input:{type:"block",slug:$slug,name:"Open Mira Smoke Theme",description:"Smoke test theme scaffolded by Open Mira.",design_brief:"Clean smoke-test theme with visible generated sections.",activate:true,overwrite:true,force_clean:true}}')"
ability_post "openmira/scaffold-theme" "$THEME_PAYLOAD" "${TMPDIR:-/tmp}/openmira-smoke-theme.json"

BLOCK_NAME="${BLOCK_NAMESPACE}/feature-card"
BLOCK_PAYLOAD="$(jq -nc --arg name "$BLOCK_NAME" '{input:{name:$name,title:"Smoke Feature Card",description:"PHP-rendered smoke-test block.",design_brief:"Compact feature card for integration smoke testing.",overwrite:true}}')"
ability_post "openmira/scaffold-block" "$BLOCK_PAYLOAD" "${TMPDIR:-/tmp}/openmira-smoke-block.json"

BLOCK_MARKUP="<!-- wp:${BLOCK_NAME} {\"eyebrow\":\"Smoke test\",\"heading\":\"Dynamic block rendered\",\"text\":\"This content comes from a PHP-rendered block scaffolded by Open Mira.\",\"buttonText\":\"Inspect result\",\"buttonUrl\":\"#inspect\"} /-->"
PAGE_PAYLOAD="$(jq -nc --arg slug "$PAGE_SLUG" --arg markup "$BLOCK_MARKUP" '{input:{title:"Open Mira Smoke Page",slug:$slug,status:"publish",sections:[{block_markup:$markup}]}}')"
ability_post "openmira/create-gutenberg-page" "$PAGE_PAYLOAD" "${TMPDIR:-/tmp}/openmira-smoke-page.json"

PAGE_ID="$(jq -r '.post.id // empty' "${TMPDIR:-/tmp}/openmira-smoke-page.json")"
if [[ -z "$PAGE_ID" ]]; then
  cat "${TMPDIR:-/tmp}/openmira-smoke-page.json" >&2
  exit 1
fi

FRONT_HTML="${TMPDIR:-/tmp}/openmira-smoke-front.html"
curl -sSL -c "$COOKIE_JAR" -b "$COOKIE_JAR" "${BASE_URL}/?page_id=${PAGE_ID}" > "$FRONT_HTML"
rg -q 'Dynamic block rendered' "$FRONT_HTML"
rg -q 'openmira-dynamic-block' "$FRONT_HTML"

PHP_SMOKE_PAYLOAD="$(jq -nc '{input:{code:"return [\"ok\" => true, \"value\" => 42];"}}')"
ability_post "openmira/execute-php" "$PHP_SMOKE_PAYLOAD" "${TMPDIR:-/tmp}/openmira-smoke-execute-php.json"
jq -e '.success == true and .return_value.ok == true and .runaway_guard.max_calls >= 1' \
  "${TMPDIR:-/tmp}/openmira-smoke-execute-php.json" >/dev/null

ability_get "openmira/list-skills" "${TMPDIR:-/tmp}/openmira-smoke-skills.json"
jq -e '.count == 3 and ([.skills[].id] | sort) == ["build-a-block-theme","feedback","wp-aware-editing"]' \
  "${TMPDIR:-/tmp}/openmira-smoke-skills.json" >/dev/null

jq -n \
  --arg theme "$(jq -r '.theme.slug' "${TMPDIR:-/tmp}/openmira-smoke-theme.json")" \
  --arg block "$(jq -r '.block.name' "${TMPDIR:-/tmp}/openmira-smoke-block.json")" \
  --arg page_id "$PAGE_ID" \
  --arg execute_php_calls "$(jq -r '.runaway_guard.count' "${TMPDIR:-/tmp}/openmira-smoke-execute-php.json")" \
  '{status:"ok", theme:$theme, block:$block, page_id:$page_id, execute_php_guard_count:($execute_php_calls|tonumber)}'
