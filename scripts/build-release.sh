#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-}"
SLUG="openmira"
PLUGIN_DIR="${ROOT_DIR}/dist/${SLUG}"
VERSIONED_ZIP_PATH="${ROOT_DIR}/dist/${SLUG}-${VERSION}.zip"
EVERGREEN_ZIP_PATH="${ROOT_DIR}/dist/${SLUG}.zip"

if [[ -z "${VERSION}" ]]; then
  echo "usage: scripts/build-release.sh <version>" >&2
  exit 1
fi

HEADER_VERSION="$(grep -E '^[[:space:]]*\*[[:space:]]*Version:' "${ROOT_DIR}/openmira.php" | head -n 1 | sed -E 's/.*Version:[[:space:]]*//')"
CONSTANT_VERSION="$(sed -nE "s/.*OPENMIRA_VERSION.*value: '([^']+)'.*/\1/p" "${ROOT_DIR}/openmira.php" | head -n 1)"

if [[ "${HEADER_VERSION}" != "${VERSION}" || "${CONSTANT_VERSION}" != "${VERSION}" ]]; then
  echo "Version mismatch: requested ${VERSION}, header ${HEADER_VERSION}, constant ${CONSTANT_VERSION}" >&2
  exit 1
fi

command -v composer >/dev/null || { echo "composer is required" >&2; exit 1; }
command -v zip >/dev/null || { echo "zip is required" >&2; exit 1; }

rm -rf "${ROOT_DIR}/dist"
mkdir -p "${PLUGIN_DIR}"

copy_path() {
  local source="$1"
  if [[ -e "${ROOT_DIR}/${source}" ]]; then
    if [[ -d "${ROOT_DIR}/${source}" ]]; then
      mkdir -p "${PLUGIN_DIR}/${source}"
      rsync -a --delete "${ROOT_DIR}/${source}/" "${PLUGIN_DIR}/${source}/"
    else
      mkdir -p "$(dirname "${PLUGIN_DIR}/${source}")"
      rsync -a "${ROOT_DIR}/${source}" "${PLUGIN_DIR}/${source}"
    fi
  fi
}

copy_path "openmira.php"
copy_path "includes"
copy_path "assets"
copy_path "stubs"
copy_path "LICENSE"
copy_path "README.md"
copy_path "CHANGELOG.txt"
copy_path "release-info.json"
copy_path "composer.json"
copy_path "composer.lock"

composer install \
  --working-dir="${PLUGIN_DIR}" \
  --no-dev \
  --optimize-autoloader \
  --no-interaction \
  --prefer-dist

rm -f "${PLUGIN_DIR}/composer.json" "${PLUGIN_DIR}/composer.lock"
find "${PLUGIN_DIR}" -name '.DS_Store' -delete
find "${PLUGIN_DIR}" -type d -empty -delete

(
  cd "${ROOT_DIR}/dist"
  zip -qr "${VERSIONED_ZIP_PATH}" "${SLUG}" -x '*.DS_Store'
  cp "${VERSIONED_ZIP_PATH}" "${EVERGREEN_ZIP_PATH}"
)

echo "Built ${VERSIONED_ZIP_PATH}"
echo "Built ${EVERGREEN_ZIP_PATH}"
