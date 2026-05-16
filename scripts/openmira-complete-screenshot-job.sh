#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat >&2 <<'EOF'
Usage: scripts/openmira-complete-screenshot-job.sh <job-id> [output-dir]

Environment:
  OPENMIRA_BASE_URL       WordPress site URL, e.g. http://127.0.0.1:9400
  OPENMIRA_USERNAME       WordPress username for application-password auth
  OPENMIRA_APP_PASSWORD   WordPress application password

Completes an Open Mira screenshot-url job by reading job metadata, capturing the
target URL with Playwright CLI, and uploading PNG bytes back to Open Mira.
EOF
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" || $# -lt 1 ]]; then
  usage
  exit 0
fi

require_command() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Missing required command: $1" >&2
    exit 1
  fi
}

require_env() {
  local name="$1"
  if [[ -z "${!name:-}" ]]; then
    echo "Missing required environment variable: ${name}" >&2
    exit 1
  fi
}

require_command curl
require_command jq
require_command npx
require_command python3

require_env OPENMIRA_BASE_URL
require_env OPENMIRA_USERNAME
require_env OPENMIRA_APP_PASSWORD

JOB_ID="$1"
OUTPUT_DIR="${2:-${TMPDIR:-/tmp}/openmira-screenshots}"
BASE_URL="${OPENMIRA_BASE_URL%/}"
ABILITIES_URL="${BASE_URL}/wp-json/wp-abilities/v1/abilities"

mkdir -p "$OUTPUT_DIR"

READ_JSON="${OUTPUT_DIR}/screenshot-job-${JOB_ID}.json"
SCREENSHOT_FILE="${OUTPUT_DIR}/screenshot-job-${JOB_ID}.png"
PAYLOAD_JSON="${OUTPUT_DIR}/screenshot-job-${JOB_ID}-complete-payload.json"
COMPLETE_JSON="${OUTPUT_DIR}/screenshot-job-${JOB_ID}-complete.json"
PLAYWRIGHT_INSTALL_LOG="${OUTPUT_DIR}/playwright-install.log"

curl -sS -G -u "${OPENMIRA_USERNAME}:${OPENMIRA_APP_PASSWORD}" \
  -H 'Cookie: playground_auto_login_already_happened=1' \
  "${ABILITIES_URL}/openmira/read-screenshot-url-job/run" \
  --data-urlencode "input[job_id]=${JOB_ID}" > "$READ_JSON"

if [[ "$(jq -r '.code // empty' "$READ_JSON")" != "" ]]; then
  cat "$READ_JSON" >&2
  exit 1
fi

TARGET_URL="$(jq -r '.job.target_url // empty' "$READ_JSON")"
VIEWPORT_WIDTH="$(jq -r '.job.viewport_width // 1440' "$READ_JSON")"
VIEWPORT_HEIGHT="$(jq -r '.job.viewport_height // 1000' "$READ_JSON")"
FULL_PAGE="$(jq -r '.job.full_page // false' "$READ_JSON")"

if [[ -z "$TARGET_URL" ]]; then
  echo "Screenshot job ${JOB_ID} does not include target_url." >&2
  cat "$READ_JSON" >&2
  exit 1
fi

PLAYWRIGHT_ARGS=(
  -y
  playwright
  screenshot
  --browser chromium
  --viewport-size "${VIEWPORT_WIDTH},${VIEWPORT_HEIGHT}"
  --wait-for-timeout 750
)
if [[ "$FULL_PAGE" == "true" ]]; then
  PLAYWRIGHT_ARGS+=(--full-page)
fi
PLAYWRIGHT_ARGS+=("$TARGET_URL" "$SCREENSHOT_FILE")

npx -y playwright install chromium > "$PLAYWRIGHT_INSTALL_LOG" 2>&1 || {
  cat "$PLAYWRIGHT_INSTALL_LOG" >&2
  exit 1
}
npx "${PLAYWRIGHT_ARGS[@]}" >/dev/null

python3 - "$JOB_ID" "$SCREENSHOT_FILE" > "$PAYLOAD_JSON" <<'PY'
import base64
import json
import pathlib
import sys

job_id = sys.argv[1]
path = pathlib.Path(sys.argv[2])
payload = {
    "input": {
        "job_id": job_id,
        "mime_type": "image/png",
        "image_base64": base64.b64encode(path.read_bytes()).decode("ascii"),
    }
}
json.dump(payload, sys.stdout)
PY

curl -sS -X POST -u "${OPENMIRA_USERNAME}:${OPENMIRA_APP_PASSWORD}" \
  -H 'Cookie: playground_auto_login_already_happened=1' \
  -H 'Content-Type: application/json' \
  "${ABILITIES_URL}/openmira/complete-screenshot-url-job/run" \
  --data-binary "@${PAYLOAD_JSON}" > "$COMPLETE_JSON"

if [[ "$(jq -r '.complete // false' "$COMPLETE_JSON")" != "true" ]]; then
  cat "$COMPLETE_JSON" >&2
  exit 1
fi

jq -n \
  --arg job_id "$JOB_ID" \
  --arg target_url "$TARGET_URL" \
  --arg screenshot_file "$SCREENSHOT_FILE" \
  --arg complete_json "$COMPLETE_JSON" \
  --arg image_url "$(jq -r '.image_url // empty' "$COMPLETE_JSON")" \
  --arg resource_uri "$(jq -r '.resource_uri // empty' "$COMPLETE_JSON")" \
  '{
    status: "ok",
    job_id: $job_id,
    target_url: $target_url,
    screenshot_file: $screenshot_file,
    complete_json: $complete_json,
    image_url: $image_url,
    resource_uri: $resource_uri
  }'
