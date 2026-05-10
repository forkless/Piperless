#!/usr/bin/env bash
#
# Piperless WordPress Plugin — Build Script
#
# Creates a clean distribution ZIP file ready for WordPress installation.
#
# Usage:
#   chmod +x build.sh
#   ./build.sh
#

set -euo pipefail

PLUGIN_SLUG="piperless"
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

# ── Read version from plugin header ───────────────────────────────────────
VERSION=$( grep -oP '^\s*\*\s*Version:\s*\K[0-9.]+' "${SCRIPT_DIR}/${PLUGIN_SLUG}.php" | head -1 )

if [[ -z "${VERSION}" ]]; then
	echo "Error: could not determine plugin version from ${PLUGIN_SLUG}.php"
	exit 1
fi

BUILD_DIR="${SCRIPT_DIR}/build"
STAGING="${BUILD_DIR}/${PLUGIN_SLUG}"
ZIP_FILE="${SCRIPT_DIR}/${PLUGIN_SLUG}-${VERSION}.zip"

echo "=== Piperless Build Script ==="
echo "Version:  ${VERSION}"
echo "Output:   ${ZIP_FILE}"
echo ""

# ── Clean previous build ──────────────────────────────────────────────────
rm -rf "${BUILD_DIR}" "${ZIP_FILE}"
mkdir -p "${STAGING}"

# ── Copy plugin files ─────────────────────────────────────────────────────
echo "Copying files…"

# Files to include.
INCLUDE=(
	"${PLUGIN_SLUG}.php"
	"uninstall.php"
	"index.php"
	"includes"
	"assets"
	"languages"
)

for item in "${INCLUDE[@]}"; do
	src="${SCRIPT_DIR}/${item}"
	if [[ -e "${src}" ]]; then
		cp -r "${src}" "${STAGING}/"
	else
		echo "  Warning: ${item} not found, skipping."
	fi
done

# ── Remove development files ──────────────────────────────────────────────
echo "Cleaning development files…"
find "${STAGING}" -name '.DS_Store' -delete
find "${STAGING}" -name 'Thumbs.db'  -delete
find "${STAGING}" -name '*.map'      -delete

# ── Create ZIP ────────────────────────────────────────────────────────────
echo "Creating ZIP archive…"
cd "${BUILD_DIR}"
zip -r "${ZIP_FILE}" "${PLUGIN_SLUG}" > /dev/null
cd "${SCRIPT_DIR}"

# ── Verify ────────────────────────────────────────────────────────────────
ZIP_SIZE=$( du -h "${ZIP_FILE}" | cut -f1 )
PLUGIN_FILE="${STAGING}/${PLUGIN_SLUG}.php"

if [[ -f "${ZIP_FILE}" ]]; then
	echo ""
	echo "=== Build Complete ==="
	echo "  File: ${ZIP_FILE}"
	echo "  Size: ${ZIP_SIZE}"
	echo "  Files included:"
	unzip -l "${ZIP_FILE}" | tail -n +4 | head -n -2
else
	echo "Error: ZIP file was not created."
	exit 1
fi

# ── Clean staging (keep the zip) ──────────────────────────────────────────
rm -rf "${BUILD_DIR}"
echo ""
echo "Done. Install ${ZIP_FILE} via WordPress Admin → Plugins → Add New → Upload."
