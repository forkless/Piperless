#!/usr/bin/env bash
#
# lock-translations.sh — Acquire / release / inspect translation locks.
#
# A lock marks a locale file as "being translated" so no two translators
# work on the same file simultaneously.  The lock is recorded in two places:
#   1. .translations-lock.json  (project-level registry)
#   2. Each locale JSON's _meta.locked flag
#
# Usage:
#   ./tools/lock-translations.sh lock    <locale> [who]
#   ./tools/lock-translations.sh unlock  <locale>
#   ./tools/lock-translations.sh lock-all      [who]
#   ./tools/lock-translations.sh unlock-all
#   ./tools/lock-translations.sh status
#
# Exit codes:
#   0 — success
#   1 — general error
#   2 — locale already locked
#   4 — locale not locked (on unlock)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
LOCKFILE="${PROJECT_DIR}/.translations-lock.json"
LANG_DIR="${PROJECT_DIR}/languages"

# ── Ensure lock file exists ───────────────────────────────────────────
init_lockfile() {
	if [[ ! -f "$LOCKFILE" ]]; then
		cat > "$LOCKFILE" <<'EOF'
{
  "version": 1,
  "locks": {
    "de_DE": null,
    "fr_FR": null,
    "es_ES": null,
    "ja": null,
    "nl_NL": null
  }
}
EOF
	fi
}

# ── Read lock state for a locale ──────────────────────────────────────
get_lock_state() {
	local locale="$1"
	# jq -r outputs the literal string "null" for JSON null.
	jq -r ".locks[\"$locale\"]" "$LOCKFILE"
}

# ── Set lock state ────────────────────────────────────────────────────
set_lock_state() {
	local locale="$1"
	local who="$2"
	local ts
	ts=$(date -u +%Y-%m-%dT%H:%M:%SZ)

	if [[ "$who" == "null" ]]; then
		jq --arg loc "$locale" '.locks[$loc] = null' "$LOCKFILE" > "${LOCKFILE}.tmp"
	else
		jq --arg loc "$locale" --arg who "$who" --arg ts "$ts" \
			'.locks[$loc] = {locked_by: $who, locked_at: $ts}' \
			"$LOCKFILE" > "${LOCKFILE}.tmp"
	fi
	mv "${LOCKFILE}.tmp" "$LOCKFILE"
}

# ── Sync _meta.locked in the locale JSON ──────────────────────────────
sync_locale_meta() {
	local locale="$1"
	local locked="$2"   # "true" or "false"
	local file="${LANG_DIR}/piperless-${locale}.json"

	if [[ -f "$file" ]]; then
		jq --argjson l "$locked" '._meta.locked = $l' "$file" > "${file}.tmp"
		mv "${file}.tmp" "$file"
	fi
}

# ── Status table ──────────────────────────────────────────────────────
cmd_status() {
	init_lockfile

	printf "%-8s  %-10s  %-6s  %s\n" "LOCALE" "TRANS" "LOCKED" "HELD BY"
	printf "%-8s  %-10s  %-6s  %s\n" "------" "------" "------" "-------"

	for locale in de_DE fr_FR es_ES ja nl_NL; do
		local file="${LANG_DIR}/piperless-${locale}.json"
		local translated=0 total=0
		if [[ -f "$file" ]]; then
			translated=$(jq -r '._meta.translated // 0' "$file")
			total=$(jq -r '._meta.total_strings // 0' "$file")
		fi

		local lock_info
		lock_info=$(jq -r ".locks[\"$locale\"]" "$LOCKFILE")
		local locked="no"
		local who="-"

		if [[ "$lock_info" != "null" ]]; then
			locked="YES"
			who=$(echo "$lock_info" | jq -r '.locked_by // "?"')
		fi

		printf "%-8s  %3s/%-6s  %-6s  %s\n" \
			"$locale" "$translated" "$total" "$locked" "$who"
	done
}

# ── Lock ──────────────────────────────────────────────────────────────
cmd_lock() {
	local locale="$1"
	local who="${2:-${USER:-unknown}}"
	init_lockfile

	# Validate locale exists.
	local file="${LANG_DIR}/piperless-${locale}.json"
	if [[ ! -f "$file" ]]; then
		echo "Error: locale file not found: $file" >&2
		exit 1
	fi

	# Check if already locked.
	local current
	current=$(get_lock_state "$locale")
	if [[ "$current" != "null" ]]; then
		local by
		by=$(echo "$current" | jq -r '.locked_by // "?"')
		echo "Error: $locale is already locked by $by." >&2
		echo "Use 'unlock $locale' first, or contact $by." >&2
		exit 2
	fi

	set_lock_state "$locale" "$who"
	sync_locale_meta "$locale" true
	echo "Locked $locale → held by $who"
}

# ── Unlock ────────────────────────────────────────────────────────────
cmd_unlock() {
	local locale="$1"
	init_lockfile

	local current
	current=$(get_lock_state "$locale")
	if [[ "$current" == "null" ]]; then
		echo "Error: $locale is not locked." >&2
		exit 4
	fi

	set_lock_state "$locale" "null"
	sync_locale_meta "$locale" false
	echo "Unlocked $locale"
}

# ── Lock all ──────────────────────────────────────────────────────────
cmd_lock_all() {
	local who="${1:-${USER:-unknown}}"
	init_lockfile

	for locale in de_DE fr_FR es_ES ja nl_NL; do
		local current
		current=$(get_lock_state "$locale")
		if [[ "$current" != "null" ]]; then
			local by
			by=$(echo "$current" | jq -r '.locked_by // "?"')
			echo "Skipping $locale (already locked by $by)"
			continue
		fi
		set_lock_state "$locale" "$who"
		sync_locale_meta "$locale" true
		echo "Locked $locale"
	done
}

# ── Unlock all ────────────────────────────────────────────────────────
cmd_unlock_all() {
	init_lockfile

	for locale in de_DE fr_FR es_ES ja nl_NL; do
		set_lock_state "$locale" "null"
		sync_locale_meta "$locale" false
		echo "Unlocked $locale"
	done
}

# ══════════════════════════════════════════════════════════════════════
# Main
# ══════════════════════════════════════════════════════════════════════

CMD="${1:-status}"
shift || true

case "$CMD" in
	status)      cmd_status "$@";;
	lock)        cmd_lock "$@";;
	unlock)      cmd_unlock "$@";;
	lock-all)    cmd_lock_all "$@";;
	unlock-all)  cmd_unlock_all "$@";;
	*)
		echo "Usage: $0 {status|lock|unlock|lock-all|unlock-all} [locale] [who]" >&2
		exit 1
		;;
esac
