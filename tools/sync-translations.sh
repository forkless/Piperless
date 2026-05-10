#!/usr/bin/env bash
#
# sync-translations.sh — Generate / update per-locale JSON translation files
#                        from the master translations.json.
#
# Usage:
#   ./tools/sync-translations.sh [locale...]
#
# With no arguments: syncs all known locales.
# With one or more locale codes: syncs only those (e.g. de_DE fr_FR).
#
# The master file (languages/translations.json) keys on the original English
# string.  Each locale file gets a _meta block and a "strings" object.
# Existing translations are preserved; new keys from master are added with
# empty values.  Removed keys are dropped.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
LANG_DIR="${PROJECT_DIR}/languages"
MASTER="${LANG_DIR}/translations.json"

# ── Known target locales ──────────────────────────────────────────────
DEFAULT_LOCALES=("de_DE" "fr_FR" "es_ES" "ja" "nl_NL")

if [[ $# -gt 0 ]]; then
	LOCALES=("$@")
else
	LOCALES=("${DEFAULT_LOCALES[@]}")
fi

# ── Validate master ───────────────────────────────────────────────────
if [[ ! -f "$MASTER" ]]; then
	echo "Error: master translations.json not found. Run pot2json.sh first." >&2
	exit 1
fi

if ! command -v jq &>/dev/null; then
	echo "Error: jq is required. Install with: sudo apt install jq" >&2
	exit 1
fi

TODAY=$(date +%Y-%m-%d)

# ── Count keys in master ──────────────────────────────────────────────
MASTER_COUNT=$(jq 'keys | length' "$MASTER")

for LOCALE in "${LOCALES[@]}"; do
	LOCALE_FILE="${LANG_DIR}/piperless-${LOCALE}.json"

	if [[ -f "$LOCALE_FILE" ]]; then
		# ── Merge: keep existing translations, add new keys, drop removed ──
		OLD_TRANSLATED=$(jq '._meta.translated // 0' "$LOCALE_FILE")
		OLD_LOCKED=$(jq '._meta.locked // false' "$LOCALE_FILE")

		# Build a new strings object: for every key in master, use the
		# existing translation if present; otherwise empty string.
		jq -n --slurpfile master "$MASTER" --slurpfile local "$LOCALE_FILE" '
			($local[0].strings // {}) as $existing |
			{
				_meta: {
					locale: "'"$LOCALE"'",
					source: "translations.json",
					generated: "'"$TODAY"'",
					total_strings: ($master[0] | keys | length),
					translated: 0,
					locked: '"$OLD_LOCKED"'
				},
				strings: ($master[0] | to_entries | map({
					key: .key,
					value: ($existing[.key] // "")
				}) | from_entries)
			}
		' > "${LOCALE_FILE}.tmp"

		# Re-count translated (non-empty values).
		NEW_TRANSLATED=$(jq '[.strings[] | select(. != "")] | length' "${LOCALE_FILE}.tmp")
		jq --argjson t "$NEW_TRANSLATED" '._meta.translated = $t' "${LOCALE_FILE}.tmp" > "$LOCALE_FILE"
		rm -f "${LOCALE_FILE}.tmp"

		echo "Updated ${LOCALE}: ${NEW_TRANSLATED} / ${MASTER_COUNT} translated (was ${OLD_TRANSLATED})"

	else
		# ── Create new locale file ─────────────────────────────────────
		jq -n --slurpfile master "$MASTER" '
			{
				_meta: {
					locale: "'"$LOCALE"'",
					source: "translations.json",
					generated: "'"$TODAY"'",
					total_strings: ($master[0] | keys | length),
					translated: 0,
					locked: false
				},
				strings: ($master[0] | to_entries | map({
					key: .key,
					value: .value
				}) | from_entries)
			}
		' > "$LOCALE_FILE"

		echo "Created ${LOCALE}: 0 / ${MASTER_COUNT} translated"
	fi
done

echo ""
echo "Done. Locale files in ${LANG_DIR}/"
