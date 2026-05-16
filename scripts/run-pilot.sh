#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat >&2 <<'EOF'
Usage: scripts/run-pilot.sh [brief.md]

Runs a reusable Open Mira pilot through Claude Code as an external MCP client.

Environment:
  OPENMIRA_PILOT_BASE_URL          Default: http://127.0.0.1:9400
  OPENMIRA_PILOT_USERNAME          Default: admin
  OPENMIRA_PILOT_OUTPUT_DIR        Default: #runtime/pilot-runs/<timestamp>
  OPENMIRA_PILOT_MAX_BUDGET_USD    Default: 20
  OPENMIRA_PILOT_CLAUDE_MODEL      Optional Claude model alias/name
  OPENMIRA_PILOT_RESET_THEME_SLUG  Default: pilot-one; set empty to skip theme reset
  OPENMIRA_PILOT_RESET_PAGE_SLUG   Default: pilot-one-landing; set empty to skip page reset
  OPENMIRA_PILOT_DRY_RUN           Set to 1 to test auth/setup/cleanup only

The runner:
  1. Authenticates to the local WordPress admin session.
  2. Resets the configured pilot theme/page slugs for repeatable runs.
  3. Enables application passwords only if needed using a transient helper plugin.
  4. Sweeps prior openmira-pilot-* application passwords.
  5. Creates a temporary application password.
  6. Runs Claude Code with a strict temporary MCP config.
  7. Revokes temporary passwords and removes the helper plugin on exit.
EOF
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  usage
  exit 0
fi

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BRIEF_PATH="${1:-${ROOT_DIR}/docs/pilots/test-1-discovery-pilot.md}"
BASE_URL="${OPENMIRA_PILOT_BASE_URL:-http://127.0.0.1:9400}"
BASE_URL="${BASE_URL%/}"
USERNAME="${OPENMIRA_PILOT_USERNAME:-admin}"
RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)"
RUN_SLUG="${RUN_ID//[TZ]/}"
OUTPUT_DIR="${OPENMIRA_PILOT_OUTPUT_DIR:-${ROOT_DIR}/#runtime/pilot-runs/${RUN_ID}}"
MAX_BUDGET_USD="${OPENMIRA_PILOT_MAX_BUDGET_USD:-20}"
MCP_SERVER_NAME="openmira-pilot"
PILOT_PASSWORD_PREFIX="openmira-pilot-"
HELPER_PLUGIN_SLUG="openmira-pilot-app-passwords-${RUN_SLUG}"
RESET_THEME_SLUG="${OPENMIRA_PILOT_RESET_THEME_SLUG:-pilot-one}"
RESET_PAGE_SLUG="${OPENMIRA_PILOT_RESET_PAGE_SLUG:-pilot-one-landing}"

COOKIE_JAR="${OUTPUT_DIR}/admin-cookies.txt"
ADMIN_HTML="${OUTPUT_DIR}/admin.html"
MCP_CONFIG="${OUTPUT_DIR}/mcp-config.json"
PROMPT_FILE="${OUTPUT_DIR}/prompt.md"
TRANSCRIPT_JSONL="${OUTPUT_DIR}/claude-stream.jsonl"
SUMMARY_TXT="${OUTPUT_DIR}/summary.txt"
APP_PASSWORD_JSON="${OUTPUT_DIR}/application-password.json"
BRIDGE_ENV_FILE="${OUTPUT_DIR}/bridge-env.sh"
MUTATION_LOG="${OUTPUT_DIR}/cleanup.log"

APP_PASSWORD_UUID=""
APP_PASSWORD_SECRET=""
CREATED_HELPER_PLUGIN="0"
REST_NONCE=""

require_command() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Missing required command: $1" >&2
    exit 1
  fi
}

shell_single_quote() {
  local value="${1//\'/\'\"\'\"\'}"
  printf "'%s'" "$value"
}

redact_file() {
  local path="$1"
  if [[ -n "$APP_PASSWORD_SECRET" && -f "$path" ]]; then
    APP_PASSWORD_SECRET="$APP_PASSWORD_SECRET" perl -0pi -e 'BEGIN { $s = $ENV{"APP_PASSWORD_SECRET"} // ""; } s/\Q$s\E/***REDACTED***/g if $s ne "";' "$path"
  fi
}

admin_get_nonce() {
  curl -sSL -c "$COOKIE_JAR" -b "$COOKIE_JAR" "${BASE_URL}/wp-admin/admin.php?page=openmira-connect" > "$ADMIN_HTML"
  REST_NONCE="$((rg -o 'createNonceMiddleware\( "[^"]+"' "$ADMIN_HTML" || true) | sed -E 's/.*"([^"]+)"/\1/' | head -1)"
  if [[ -z "$REST_NONCE" ]]; then
    echo "Could not discover WordPress REST nonce from ${BASE_URL}/wp-admin/admin.php?page=openmira-connect" >&2
    exit 1
  fi
}

enable_ai_abilities() {
  if rg -q 'name="openmira_ai_abilities_enabled"[^>]*checked' "$ADMIN_HTML"; then
    return
  fi

  local settings_nonce
  settings_nonce="$((rg -o 'name="_wpnonce" value="[^"]+"' "$ADMIN_HTML" || true) | sed -E 's/.*value="([^"]+)".*/\1/' | head -1)"
  if [[ -z "$settings_nonce" ]]; then
    echo "Could not discover Open Mira settings nonce from ${BASE_URL}/wp-admin/admin.php?page=openmira-connect" >&2
    exit 1
  fi

  curl -sSL -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    -H 'Content-Type: application/x-www-form-urlencoded' \
    --data-urlencode "_wpnonce=${settings_nonce}" \
    --data-urlencode "_wp_http_referer=/wp-admin/admin.php?page=openmira-connect" \
    --data-urlencode "openmira_ai_abilities_enabled=1" \
    --data-urlencode "openmira_submit=Save Settings" \
    "${BASE_URL}/wp-admin/admin.php?page=openmira-connect" \
    > "${OUTPUT_DIR}/enable-ai-abilities.html"
}

ability_post_cookie() {
  local ability="$1"
  local payload="$2"
  curl -sS -X POST -b "$COOKIE_JAR" \
    -H "X-WP-Nonce: ${REST_NONCE}" \
    -H 'Content-Type: application/json' \
    "${BASE_URL}/wp-json/wp-abilities/v1/abilities/${ability}/run" \
    --data "$payload"
}

wp_rest_cookie() {
  local method="$1"
  local path="$2"
  local body="${3:-}"
  if [[ -n "$body" ]]; then
    curl -sS -X "$method" -b "$COOKIE_JAR" \
      -H "X-WP-Nonce: ${REST_NONCE}" \
      -H 'Content-Type: application/json' \
      "${BASE_URL}${path}" \
      --data "$body"
  else
    curl -sS -X "$method" -b "$COOKIE_JAR" \
      -H "X-WP-Nonce: ${REST_NONCE}" \
      "${BASE_URL}${path}"
  fi
}

set_act_mode() {
  ability_post_cookie 'openmira/set-safety-mode' '{"input":{"mode":"act","ttl_minutes":60}}' \
    > "${OUTPUT_DIR}/set-act-mode.json"
}

execute_php_cookie() {
  local code="$1"
  local payload
  payload="$(jq -nc --arg code "$code" '{input:{code:$code}}')"
  ability_post_cookie 'openmira/execute-php' "$payload"
}

reset_pilot_state() {
  if [[ -z "$RESET_THEME_SLUG" && -z "$RESET_PAGE_SLUG" ]]; then
    echo '{"skipped":true}' > "${OUTPUT_DIR}/preflight-reset.json"
    return
  fi

  local code
  code='require_once ABSPATH . "wp-admin/includes/theme.php";
$theme_slug = "'"${RESET_THEME_SLUG}"'";
$page_slug = "'"${RESET_PAGE_SLUG}"'";
$result = ["theme_slug" => $theme_slug, "page_slug" => $page_slug, "deleted_pages" => 0, "theme_deleted" => false, "theme_dir_exists" => false, "active_theme" => get_stylesheet()];

if ($theme_slug !== "" && get_stylesheet() === $theme_slug) {
    $fallback = "";
    foreach (wp_get_themes() as $candidate_slug => $theme) {
        if ((string) $candidate_slug !== $theme_slug && $theme->exists()) {
            $fallback = (string) $candidate_slug;
            break;
        }
    }
    if ($fallback !== "") {
        switch_theme($fallback);
        $result["switched_to"] = $fallback;
    }
}

if ($page_slug !== "") {
    $pages = get_posts([
        "post_type" => "page",
        "post_status" => "any",
        "name" => $page_slug,
        "numberposts" => -1,
    ]);
    foreach ($pages as $page) {
        if (wp_delete_post($page->ID, true) !== false) {
            $result["deleted_pages"]++;
        }
    }
    if (get_option("show_on_front") === "page") {
        $front_id = (int) get_option("page_on_front");
        if ($front_id > 0 && get_post_field("post_name", $front_id) === $page_slug) {
            update_option("show_on_front", "posts");
            update_option("page_on_front", 0);
            $result["front_page_reset"] = true;
        }
    }
}

$delete_tree = static function (string $path) use (&$delete_tree): bool {
    clearstatcache(true, $path);
    if (!file_exists($path) && !is_link($path)) {
        return true;
    }
    if (is_link($path) || is_file($path)) {
        @chmod($path, 0666);
        return @unlink($path);
    }
    if (!is_dir($path)) {
        return false;
    }
    $items = scandir($path);
    if (!is_array($items)) {
        return false;
    }
    foreach ($items as $item) {
        if ($item === "." || $item === "..") {
            continue;
        }
        if (!$delete_tree($path . DIRECTORY_SEPARATOR . $item)) {
            return false;
        }
    }
    @chmod($path, 0777);
    return @rmdir($path);
};

if ($theme_slug !== "") {
    $theme_dir = trailingslashit(get_theme_root()) . $theme_slug;
    $result["theme_path"] = $theme_dir;
    $result["theme_deleted"] = $delete_tree($theme_dir);
    wp_clean_themes_cache(true);
    clearstatcache(true, $theme_dir);
    $result["theme_dir_exists"] = file_exists($theme_dir) || is_dir($theme_dir) || is_link($theme_dir);
    $result["active_theme"] = get_stylesheet();
    if ($result["theme_dir_exists"]) {
        return new WP_Error("pilot_reset_failed", "Could not remove pilot theme directory before run.", $result);
    }
}

return $result;'

  execute_php_cookie "$code" > "${OUTPUT_DIR}/preflight-reset.json"
  if jq -e '.code == "pilot_reset_failed" or .success == false' "${OUTPUT_DIR}/preflight-reset.json" >/dev/null; then
    cat "${OUTPUT_DIR}/preflight-reset.json" >&2
    echo "Pilot preflight reset failed." >&2
    exit 1
  fi
}

enable_application_passwords_if_needed() {
  local probe
  probe="$(wp_rest_cookie POST '/wp-json/wp/v2/users/me/application-passwords' '{"name":"openmira-pilot-probe"}')"
  local probe_uuid
  probe_uuid="$(jq -r '.uuid // empty' <<<"$probe")"
  if [[ -n "$probe_uuid" ]]; then
    wp_rest_cookie DELETE "/wp-json/wp/v2/users/me/application-passwords/${probe_uuid}" >/dev/null
    return
  fi

  if [[ "$(jq -r '.code // empty' <<<"$probe")" != "application_passwords_disabled" ]]; then
    echo "$probe" >&2
    echo "Application password probe failed unexpectedly." >&2
    exit 1
  fi

  local code
  code='require_once ABSPATH . "wp-admin/includes/plugin.php"; $slug = "'"${HELPER_PLUGIN_SLUG}"'"; $dir = WP_PLUGIN_DIR . "/" . $slug; if (!is_dir($dir)) { mkdir($dir, 0777, true); } $file = $dir . "/" . $slug . ".php"; $bytes = file_put_contents($file, "<?php\n/* Plugin Name: Open Mira Pilot Application Passwords */\nadd_filter( '\''wp_is_application_passwords_available'\'', '\''__return_true'\'', 999 );\n"); $rel = $slug . "/" . $slug . ".php"; $activated = activate_plugin($rel); return ["created"=>$bytes !== false && file_exists($file), "bytes"=>$bytes, "activated"=>is_wp_error($activated) ? $activated->get_error_message() : true, "active"=>is_plugin_active($rel), "path"=>$file];'
  execute_php_cookie "$code" > "${OUTPUT_DIR}/enable-app-passwords.json"
  CREATED_HELPER_PLUGIN="1"
}

sweep_pilot_passwords() {
  local list_json
  list_json="$(wp_rest_cookie GET '/wp-json/wp/v2/users/me/application-passwords')"
  if [[ "$(jq -r 'type' <<<"$list_json")" != "array" ]]; then
    echo "$list_json" >> "$MUTATION_LOG"
    return
  fi

  jq -r --arg prefix "$PILOT_PASSWORD_PREFIX" '.[] | select((.name // "") | startswith($prefix)) | .uuid' <<<"$list_json" |
  while IFS= read -r uuid; do
    [[ -z "$uuid" ]] && continue
    wp_rest_cookie DELETE "/wp-json/wp/v2/users/me/application-passwords/${uuid}" >> "$MUTATION_LOG" || true
    echo >> "$MUTATION_LOG"
  done
}

create_application_password() {
  local name="${PILOT_PASSWORD_PREFIX}${RUN_ID}"
  wp_rest_cookie POST '/wp-json/wp/v2/users/me/application-passwords' "$(jq -nc --arg name "$name" '{name:$name}')" \
    > "$APP_PASSWORD_JSON"
  APP_PASSWORD_UUID="$(jq -r '.uuid // empty' "$APP_PASSWORD_JSON")"
  APP_PASSWORD_SECRET="$(jq -r '.password // empty' "$APP_PASSWORD_JSON")"

  if [[ -z "$APP_PASSWORD_UUID" || -z "$APP_PASSWORD_SECRET" ]]; then
    cat "$APP_PASSWORD_JSON" >&2
    echo "Could not create temporary application password." >&2
    exit 1
  fi

  chmod 600 "$APP_PASSWORD_JSON"
}

cleanup() {
  set +e
  mkdir -p "$OUTPUT_DIR"
  {
    echo "cleanup_started=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    if [[ -n "${APP_PASSWORD_UUID:-}" ]]; then
      echo "revoking_uuid=${APP_PASSWORD_UUID}"
      wp_rest_cookie DELETE "/wp-json/wp/v2/users/me/application-passwords/${APP_PASSWORD_UUID}"
      echo
    fi
    if [[ -n "${REST_NONCE:-}" ]]; then
      sweep_pilot_passwords
    fi
    if [[ "${CREATED_HELPER_PLUGIN:-0}" == "1" ]]; then
      echo "removing_helper_plugin=${HELPER_PLUGIN_SLUG}"
      execute_php_cookie 'require_once ABSPATH . "wp-admin/includes/plugin.php"; $slug = "'"${HELPER_PLUGIN_SLUG}"'"; $rel = $slug . "/" . $slug . ".php"; if (is_plugin_active($rel)) { deactivate_plugins($rel); } $dir = WP_PLUGIN_DIR . "/" . $slug; $file = $dir . "/" . $slug . ".php"; @unlink($file); @rmdir($dir); return ["active"=>is_plugin_active($rel), "exists"=>file_exists($file), "dir_exists"=>is_dir($dir)];'
      echo
    fi
    echo "cleanup_finished=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  } >> "$MUTATION_LOG" 2>&1
  redact_file "$MCP_CONFIG"
  redact_file "$PROMPT_FILE"
  redact_file "$TRANSCRIPT_JSONL"
  redact_file "$SUMMARY_TXT"
  redact_file "$MUTATION_LOG"
  redact_file "$APP_PASSWORD_JSON"
  redact_file "$BRIDGE_ENV_FILE"
}
trap cleanup EXIT

require_command claude
require_command curl
require_command jq
require_command npx
require_command perl
require_command rg

mkdir -p "$OUTPUT_DIR"
touch "$MUTATION_LOG"

if [[ ! -f "$BRIEF_PATH" ]]; then
  echo "Brief file not found: $BRIEF_PATH" >&2
  exit 1
fi

admin_get_nonce
enable_ai_abilities
admin_get_nonce
set_act_mode
reset_pilot_state
enable_application_passwords_if_needed
admin_get_nonce
sweep_pilot_passwords
create_application_password

cat > "$MCP_CONFIG" <<JSON
{
  "mcpServers": {
    "${MCP_SERVER_NAME}": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "${BASE_URL}/wp-json/mcp/mcp-adapter-default-server",
        "WP_API_USERNAME": "${USERNAME}",
        "WP_API_PASSWORD": "${APP_PASSWORD_SECRET}",
        "OAUTH_ENABLED": "false",
        "CUSTOM_HEADERS": "{\"Cookie\":\"playground_auto_login_already_happened=1\"}"
      }
    }
  }
}
JSON
chmod 600 "$MCP_CONFIG"

BRIDGE_SCRIPT="${ROOT_DIR}/scripts/openmira-complete-screenshot-job.sh"
SCREENSHOT_DIR="${OUTPUT_DIR}/screenshots"
mkdir -p "$SCREENSHOT_DIR"
{
  printf 'export OPENMIRA_BASE_URL=%s\n' "$(shell_single_quote "$BASE_URL")"
  printf 'export OPENMIRA_USERNAME=%s\n' "$(shell_single_quote "$USERNAME")"
  printf 'export OPENMIRA_APP_PASSWORD=%s\n' "$(shell_single_quote "$APP_PASSWORD_SECRET")"
} > "$BRIDGE_ENV_FILE"
chmod 600 "$BRIDGE_ENV_FILE"

cat > "$PROMPT_FILE" <<EOF
You are running an Open Mira pilot through an external MCP client.

Use the Open Mira MCP server named \`${MCP_SERVER_NAME}\`.

Pilot brief:
$(cat "$BRIEF_PATH")

Browser screenshot bridge:
When you create an Open Mira screenshot job and receive a job_id, complete it by running this exact local helper with Bash:

\`\`\`bash
set +x
source "${BRIDGE_ENV_FILE}"
"${BRIDGE_SCRIPT}" "<job_id>" "${SCREENSHOT_DIR}"
\`\`\`

Do not print, cat, inline, or echo the contents of \`${BRIDGE_ENV_FILE}\`.
Do not inline \`OPENMIRA_APP_PASSWORD\` in a command.

Constraints:
- Use Open Mira MCP abilities for WordPress reads/writes whenever possible.
- Do not edit repository files directly except for invoking the screenshot bridge helper.
- Keep metrics and a friction log as specified in the brief.
- Screenshot loop capability is part of the test: create at least one screenshot job, complete it through the bridge, then use the result to decide whether iteration is needed.
- Prefer image_url/resource_uri. If a protected URL cannot be fetched by your client, request include_image=true only for that job.
- Stop at the first coherent result; do not keep polishing.

Return only:
1. Metrics table.
2. Friction log.
3. Final page URL.
4. Screenshot capability notes.
EOF
chmod 600 "$PROMPT_FILE"

if [[ "${OPENMIRA_PILOT_DRY_RUN:-0}" == "1" ]]; then
  cat > "$SUMMARY_TXT" <<EOF
Dry run complete.

Created temporary MCP config, temporary application password, and pilot prompt.
Claude Code was not invoked because OPENMIRA_PILOT_DRY_RUN=1.
EOF
  redact_file "$SUMMARY_TXT"
  redact_file "$MCP_CONFIG"
  redact_file "$PROMPT_FILE"
  redact_file "$BRIDGE_ENV_FILE"
  echo '{}' > "$TRANSCRIPT_JSONL"
  cat <<EOF
Open Mira pilot dry run complete.
output_dir: ${OUTPUT_DIR}
summary: ${SUMMARY_TXT}
transcript: ${TRANSCRIPT_JSONL}
cleanup_log: ${MUTATION_LOG}
EOF
  exit 0
fi

CLAUDE_ARGS=(
  -p
  --mcp-config "$MCP_CONFIG"
  --strict-mcp-config
  --permission-mode bypassPermissions
  --output-format stream-json
  --verbose
  --max-budget-usd "$MAX_BUDGET_USD"
)
if [[ -n "${OPENMIRA_PILOT_CLAUDE_MODEL:-}" ]]; then
  CLAUDE_ARGS+=(--model "$OPENMIRA_PILOT_CLAUDE_MODEL")
fi

set +e
claude "${CLAUDE_ARGS[@]}" "$(cat "$PROMPT_FILE")" | tee "$TRANSCRIPT_JSONL"
CLAUDE_EXIT=${PIPESTATUS[0]}
set -e

redact_file "$TRANSCRIPT_JSONL"

python3 - "$TRANSCRIPT_JSONL" "$SUMMARY_TXT" <<'PY'
import json
import pathlib
import sys

transcript = pathlib.Path(sys.argv[1])
summary = pathlib.Path(sys.argv[2])
texts = []
for line in transcript.read_text(errors="replace").splitlines():
    try:
        event = json.loads(line)
    except json.JSONDecodeError:
        continue
    if event.get("type") == "result" and isinstance(event.get("result"), str):
        texts.append(event["result"])
    message = event.get("message")
    if isinstance(message, dict):
        for item in message.get("content", []):
            if isinstance(item, dict) and item.get("type") == "text":
                texts.append(item.get("text", ""))
summary.write_text("\n\n".join(t for t in texts if t).strip() + "\n")
PY

redact_file "$SUMMARY_TXT"
redact_file "$MCP_CONFIG"
redact_file "$PROMPT_FILE"

cat <<EOF
Open Mira pilot run complete.
exit_code: ${CLAUDE_EXIT}
output_dir: ${OUTPUT_DIR}
summary: ${SUMMARY_TXT}
transcript: ${TRANSCRIPT_JSONL}
cleanup_log: ${MUTATION_LOG}
EOF

exit "$CLAUDE_EXIT"
