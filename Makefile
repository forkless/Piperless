# Piperless — Makefile
# =============================================================================

SHELL := /bin/bash

.PHONY: all build clean translations translation-status lock-translations unlock-translations check-translations json2po help

# ── Default ──────────────────────────────────────────────────────────────────
all: translations

# ── Build ────────────────────────────────────────────────────────────────────
build:
	@echo "=== Building Piperless ==="
	./tools/build.sh

# ── Translations ─────────────────────────────────────────────────────────────
translations:
	@echo "=== Extracting strings from .pot ==="
	./tools/pot2json.sh
	@echo ""
	@echo "=== Syncing locale files ==="
	./tools/sync-translations.sh

# ── Translation locks ────────────────────────────────────────────────────────
lock-translations:
	./tools/lock-translations.sh lock-all

unlock-translations:
	./tools/lock-translations.sh unlock-all

translation-status:
	./tools/lock-translations.sh status

# ── JSON → PO conversion ────────────────────────────────────────────────────
json2po:
	@for locale in de_DE fr_FR es_ES ja nl_NL; do \
		./tools/json2po.sh "languages/piperless-$${locale}.json"; \
	done

# ── Validation ───────────────────────────────────────────────────────────────
check-translations:
	@echo "=== Checking translation integrity ==="
	@errors=0; \
	for locale in de_DE fr_FR es_ES ja nl_NL; do \
		file="languages/piperless-$${locale}.json"; \
		if [[ ! -f "$$file" ]]; then \
			echo "  MISSING: $$file"; \
			errors=$$((errors + 1)); \
			continue; \
		fi; \
		locked=$$(jq -r '._meta.locked // false' "$$file"); \
		total=$$(jq -r '._meta.total_strings // 0' "$$file"); \
		done=$$(jq -r '._meta.translated // 0' "$$file"); \
		remaining=$$((total - done)); \
		if [[ "$$locked" == "true" ]]; then \
			echo "  $$locale: LOCKED — $$done/$$total ($$remaining remaining)"; \
			echo "  WARNING: $$locale is locked. Release may contain partial translations."; \
			errors=$$((errors + 1)); \
		elif [[ $$remaining -gt 0 ]]; then \
			echo "  $$locale: $$done/$$total ($$remaining untranslated)"; \
		else \
			echo "  $$locale: complete ($$done/$$total)"; \
		fi; \
	done; \
	if [[ $$errors -gt 0 ]]; then \
		echo ""; \
		echo "Translation check: $$errors issue(s) found."; \
		exit 1; \
	else \
		echo "Translation check: OK"; \
	fi

# ── Clean ────────────────────────────────────────────────────────────────────
clean:
	rm -rf build/ *.zip

# ── Help ─────────────────────────────────────────────────────────────────────
help:
	@echo "Piperless Makefile"
	@echo ""
	@echo "  make translations        Extract .pot → translations.json + sync locales"
	@echo "  make translation-status   Show lock/translation status for all locales"
	@echo "  make lock-translations    Lock all locale files for translation work"
	@echo "  make unlock-translations  Release all translation locks"
	@echo "  make check-translations   Validate translation integrity & lock state"
	@echo "  make json2po             Convert all locale JSON files → .po files"
	@echo "  make build               Create distribution ZIP via build.sh"
	@echo "  make release             Build, tag, push, and upload a GitHub release"
	@echo "  make clean               Remove build artifacts"
	@echo ""
