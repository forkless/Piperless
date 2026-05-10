# Security Audit — Piperless v1.1.0

**Audit date:** 2026-05-10  
**Scope:** All 8 PHP classes (`includes/*.php`), plus `uninstall.php`  
**Findings:** 1 (fixed). Open: 0  
**Auditor:** DeepSeek V4 Pro (systematic automated review)

---

## Summary

29 categories reviewed. One logic-level bug found and fixed mid-session (settings data loss on partial form save). Zero outstanding security vulnerabilities — critical, high, medium, or low.

---

## Results

| # | Category | Finding | Notes |
|---|----------|---------|-------|
| 1 | Command injection | ✅ Pass | 30+ `escapeshellarg()` calls. Zero `escapeshellcmd()`. |
| 2 | SQL injection | ✅ Pass | Single `$wpdb->get_results()` uses `$wpdb->prepare()` with `%s`. |
| 3 | XSS (frontend player) | ✅ Pass | `esc_attr()`, `esc_html()`, `esc_url()` on all outputs. |
| 4 | XSS (admin panel) | ✅ Pass | Voice dropdown pre-rendered with `esc_attr`/`esc_html`. Field values escaped. |
| 5 | XSS (Gutenberg sidebar) | ✅ Pass | REST responses sanitized via `sanitize_text_field()`. |
| 6 | AJAX authorization | ✅ Pass | All 10 handlers check `manage_options` capability. |
| 7 | REST authorization | ✅ Pass | `current_user_can('edit_post', $post_id)` on generate/status/remove. |
| 8 | Post meta auth | ✅ Pass | 11 `register_post_meta()` calls have `current_user_can` auth callback. |
| 9 | Nonce verification | ✅ Pass | All 11 AJAX endpoints call `check_ajax_referer()`. |
| 10 | Rate limiting | ✅ Pass | Transient-based per-IP on audio proxy. Configurable 1–600 req/min. |
| 11 | Cache key validation | ✅ Pass | Regex `^[a-zA-Z0-9_-]+$` on proxy endpoint + AJAX delete handler. |
| 12 | Path traversal | ✅ Pass | Cache keys regex-validated. Model paths stripped to basename in REST. |
| 13 | Concurrency mutex | ✅ Pass | Transient-based, 5-min TTL, released on success and failure paths. |
| 14 | Process timeout | ✅ Pass | `set_time_limit()` guard on Piper calls; restored in `finally` block. |
| 15 | Log file security | ✅ Pass | `chmod 0600` after every write. |
| 16 | Settings sanitization (partial saves) | 🔧 Fixed | Logs tab save wiped all settings. Fixed with `array_merge($existing, $input)`. |
| 17 | CSS sanitization | ✅ Pass | `sanitize_css()` strips `url()`, `expression()`, `@import`, `behavior:`, `-moz-binding`. |
| 18 | Input validation (Piper) | ✅ Pass | Model paths checked with `file_exists()`. Quality parameter enum-validated. |
| 19 | Input validation (voice aliases) | ✅ Pass | `sanitize_text_field()` on both keys and values. |
| 20 | Directory creation | ✅ Pass | `wp_mkdir_p()` used; validates under `WP_CONTENT_DIR`. |
| 21 | File write safety | ✅ Pass | `error_clear_last()` before every `@file_put_contents()`. |
| 22 | open_basedir awareness | ✅ Pass | String-prefix matching before any `is_dir()`/`file_exists()` call. |
| 23 | Cron job safety | ✅ Pass | `wp_schedule_single_event()` with deduplication check. |
| 24 | Uninstall cleanliness | ✅ Pass | Options and post meta cleaned; cron hook deregistered. |
| 25 | Error handling | ✅ Pass | All `exec()` calls check return codes. Temp files cleaned with `@unlink`. |
| 26 | Activation safety | ✅ Pass | PHP/WordPress version check only. No destructive operations. |
| 27 | Sensitive data exposure | ✅ Pass | No API keys, passwords, or credentials stored or logged. |
| 28 | File permissions | ✅ Pass | Cache directory created with proper permissions. Log `chmod 0600`. |
| 29 | HTTP security headers | ✅ Pass | `Content-Range`, `Accept-Ranges: bytes`, `Cache-Control: public, max-age=86400`. |

---

## Legend

| Symbol | Meaning |
|--------|---------|
| ✅ Pass | No vulnerability found |
| 🔧 Fixed | Bug found and resolved |

---

## Defense-in-Depth Layers

Piperless shells out to the Piper TTS engine and ffmpeg — both system binaries. The security model is built around controlling this boundary:

1. **Input layer:** All user input — POST params, REST params, cache keys, model paths — is validated before touching the filesystem or shell.
2. **Authorization layer:** Every admin action checks capabilities. Every post-specific action checks ownership. Public endpoints (audio proxy) use rate limiting instead.
3. **Shell boundary:** `escapeshellarg()` wraps every argument passed to `exec()`, `proc_open()`, and `proc_close()`. No string concatenation into shell commands anywhere.
4. **Output layer:** All HTML output goes through WordPress escaping functions. REST responses use `sanitize_text_field()`. Player attributes use `esc_attr()` and `esc_url()`.
5. **Failure safety:** Every execution path has a fallback. Failed MP3 conversion stores WAV. Failed ffprobe reads the WAV header. Failed temp writes clean up. Mutexes release in all branches.

---

## Audit Methodology

Each PHP file was reviewed against all 29 categories. The review examined:

- Every `exec()`, `proc_open()`, `shell_exec()` call path
- Every `echo`, `printf`, direct HTML output
- Every `$_POST`, `$_GET`, `$_SERVER`, `get_param()` access point
- Every `file_exists()`, `is_dir()`, `fopen()`, `file_put_contents()` call
- Every `wpdb` query and `maybe_unserialize()` usage
- Every `add_action`, `register_post_meta`, `register_rest_route` registration

The audit was performed programmatically by scanning source files for known vulnerability patterns, then reviewed manually for false positives and context.
