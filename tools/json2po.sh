#!/usr/bin/env bash
#
# json2po.sh — Convert a per-locale JSON translation file into a WordPress
#             .po file, using the original .pot for file-reference comments.
#
# Usage:
#   ./tools/json2po.sh <locale_json> [pot_path] [output_po]
#
# Defaults:
#   pot_path    = languages/piperless.pot
#   output_po   = languages/piperless-<locale>.po
#
# The script only emits entries with non-empty translations.
# Untranslated strings are skipped — WordPress falls back to the English
# msgid at runtime.

set -euo pipefail

LOCALE_JSON="${1:?Usage: $0 <locale_json> [pot_path] [output_po]}"
POT="${2:-languages/piperless.pot}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

# Resolve paths relative to project root.
[[ "$LOCALE_JSON" != /* ]] && LOCALE_JSON="${PROJECT_DIR}/${LOCALE_JSON}"
[[ "$POT"        != /* ]] && POT="${PROJECT_DIR}/${POT}"

if [[ ! -f "$LOCALE_JSON" ]]; then
	echo "Error: locale JSON not found: $LOCALE_JSON" >&2
	exit 1
fi
if [[ ! -f "$POT" ]]; then
	echo "Error: .pot file not found: $POT" >&2
	exit 1
fi
if ! command -v jq &>/dev/null; then
	echo "Error: jq is required." >&2
	exit 1
fi

# ══════════════════════════════════════════════════════════════════════
# Helper: escape a string for PO msgid/msgstr double-quoted format.
# ══════════════════════════════════════════════════════════════════════
po_escape() {
	local s="$1"
	s="${s//\\/\\\\}"
	s="${s//\"/\\\"}"
	printf '%s' "$s"
}

# ── Determine locale and output path ──────────────────────────────────
LOCALE=$(jq -r '._meta.locale // "unknown"' "$LOCALE_JSON")

if [[ -n "${3:-}" ]]; then
	OUT_PO="${3}"
	[[ "$OUT_PO" != /* ]] && OUT_PO="${PROJECT_DIR}/${OUT_PO}"
else
	OUT_PO="${LOCALE_JSON%.json}.po"
fi

# ── Build msgid → references map from the .pot ────────────────────────
#    Format: msgid<TAB>#: file:line file:line
declare -A refs
current_refs=""
current_msgid=""
in_msgid=false

while IFS= read -r line || [[ -n "$line" ]]; do
	if [[ "$line" =~ ^#:\ (.*) ]]; then
		current_refs="#: ${BASH_REMATCH[1]}"
	elif [[ "$line" =~ ^#:\  ]]; then
		# Continuation of references (e.g. "#: piperless.php:47 piperless.php:56")
		:  # handled by the regex above
	elif [[ "$line" =~ ^msgid\ \"(.*)\"$ ]]; then
		current_msgid="${BASH_REMATCH[1]}"
		in_msgid=true
	elif $in_msgid && [[ "$line" =~ ^\"(.*)\"$ ]]; then
		current_msgid+="${BASH_REMATCH[1]}"
	elif [[ "$line" =~ ^msgstr ]]; then
		in_msgid=false
		if [[ -n "$current_msgid" && -n "$current_refs" ]]; then
			refs["$current_msgid"]="$current_refs"
		fi
		current_refs=""
		current_msgid=""
	fi
done < "$POT"

# ── Counters ──────────────────────────────────────────────────────────
TOTAL=$(jq '._meta.total_strings // 0' "$LOCALE_JSON")
TRANSLATED=$(jq '[.strings[] | select(. != "")] | length' "$LOCALE_JSON")
TODAY=$(date +%Y-%m-%d)

# ── PO header ─────────────────────────────────────────────────────────
LANG_CODE="${LOCALE%_*}"          # e.g. "de" from "de_DE"
LANG_NAME="${LOCALE}"             # full locale as fallback

# Try to get a human-readable language name.
case "$LANG_CODE" in
	de) LANG_DISPLAY="German" ;;
	fr) LANG_DISPLAY="French" ;;
	es) LANG_DISPLAY="Spanish" ;;
	ja) LANG_DISPLAY="Japanese" ;;
	nl) LANG_DISPLAY="Dutch" ;;
	*)  LANG_DISPLAY="$LANG_CODE" ;;
esac

# ── Generate .po ──────────────────────────────────────────────────────
{
	cat <<HEADER
# Piperless — Audio Transcripts
# Copyright (C) 2024 Piperless Team
# This file is distributed under the MIT license.
#
msgid ""
msgstr ""
"Project-Id-Version: Piperless 1.0.0\\n"
"Report-Msgid-Bugs-To: https://github.com/example/piperless/issues\\n"
"POT-Creation-Date: 2024-01-01 00:00+0000\\n"
"PO-Revision-Date: ${TODAY} 00:00+0000\\n"
"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n"
"Language-Team: ${LANG_DISPLAY} <LL@li.org>\\n"
"Language: ${LOCALE}\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"Plural-Forms: nplurals=2; plural=(n != 1);\\n"

HEADER

	# ── Emit translated entries ───────────────────────────────────────
	emitted=0
	while IFS=$'\t' read -r key val; do
		# Skip empty translations.
		[[ -z "$val" ]] && continue

		# Emit file-reference comment if known.
		ref="${refs["$key"]:-}"
		if [[ -n "$ref" ]]; then
			echo "$ref"
		fi

		# msgid — escape for PO format.
		printf 'msgid "%s"\n' "$(po_escape "$key")"

		# msgstr — escape for PO format.
		printf 'msgstr "%s"\n' "$(po_escape "$val")"
		echo ""
		emitted=$((emitted + 1))
	done < <(jq -r '.strings | to_entries[] | "\(.key)\t\(.value)"' "$LOCALE_JSON")

} > "$OUT_PO"

# ── Compile .po → .mo ─────────────────────────────────────────────────
# WordPress loads .mo files at runtime, not .po.
OUT_MO="${OUT_PO%.po}.mo"
if command -v msgfmt &>/dev/null; then
	msgfmt -o "$OUT_MO" "$OUT_PO" 2>/dev/null
	MO_OK=true
else
	MO_OK=false
fi

# ── Report ────────────────────────────────────────────────────────────
echo "json2po: ${LOCALE} → ${OUT_PO}"
echo "  translated : ${TRANSLATED} / ${TOTAL}"
echo "  emitted    : ${emitted:-0} entries"
if [[ "$MO_OK" == true ]]; then
	echo "  compiled   : ${OUT_MO}"
else
	echo "  compiled   : skipped (msgfmt not found)"
fi
if [[ ${emitted:-0} -eq 0 ]]; then
	echo "  WARNING: no translations found. .po file contains headers only."
fi
