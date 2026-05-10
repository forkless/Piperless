#!/usr/bin/env bash
#
# pot2json.sh — Extract translatable strings from a WordPress .pot file
#              and produce a clean JSON keyed by msgid.
#
# Usage:
#   ./tools/pot2json.sh [pot_path] [output_json]
#
# Defaults:
#   pot_path    = languages/piperless.pot
#   output_json = languages/translations.json

set -euo pipefail

POT="${1:-languages/piperless.pot}"
OUT="${2:-languages/translations.json}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

POT="${PROJECT_DIR}/${POT#./}"
OUT="${PROJECT_DIR}/${OUT#./}"

if [[ ! -f "$POT" ]]; then
	echo "Error: .pot file not found at $POT" >&2
	exit 1
fi

# ── Extract msgid/msgstr pairs into a tmp file ────────────────────────
TMP="$(mktemp)"
trap 'rm -f "$TMP"' EXIT

current_msgid=""
current_msgstr=""
in_msgid=false
in_msgstr=false

while IFS= read -r line || [[ -n "$line" ]]; do
	[[ "$line" =~ ^# ]] && continue

	if [[ "$line" =~ ^msgid\ \"(.*)\"$ ]]; then
		current_msgid="${BASH_REMATCH[1]}"
		in_msgid=true
		in_msgstr=false
		current_msgstr=""
	elif [[ "$line" =~ ^msgstr\ \"(.*)\"$ ]]; then
		current_msgstr="${BASH_REMATCH[1]}"
		in_msgid=false
		in_msgstr=true
		if [[ -n "$current_msgid" ]]; then
			# Write key<tab>value to tmp file (sorted later).
			printf '%s\t%s\n' "$current_msgid" "$current_msgstr" >> "$TMP"
		fi
	elif $in_msgid && [[ "$line" =~ ^\"(.*)\"$ ]]; then
		current_msgid+="${BASH_REMATCH[1]}"
	elif $in_msgstr && [[ "$line" =~ ^\"(.*)\"$ ]]; then
		current_msgstr+="${BASH_REMATCH[1]}"
	fi
done < "$POT"

# ── Build JSON from sorted pairs ──────────────────────────────────────
{
	echo "{"
	first=true
	sort "$TMP" | while IFS=$'\t' read -r key val; do
		# Escape for JSON.
		esc_key=$(printf '%s' "$key" | sed 's/\\/\\\\/g; s/"/\\"/g')
		esc_val=$(printf '%s' "$val" | sed 's/\\/\\\\/g; s/"/\\"/g')

		if $first; then
			first=false
		else
			echo ","
		fi
		printf '  "%s": "%s"' "$esc_key" "$esc_val"
	done
	echo ""
	echo "}"
} > "$OUT"

count=$(wc -l < "$TMP")
echo "Extracted ${count} strings → ${OUT}"
