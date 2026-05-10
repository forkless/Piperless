# Changelog

All notable changes to the Piperless WordPress plugin.

## [1.1.1] — 2026-05-10

### Fixed

- **Player not rendering for logged-out users** — removed `in_the_loop()` guard from `maybe_prepend_player()`. Some themes override `in_the_loop()` in non-admin contexts, causing the player HTML to be suppressed for logged-out visitors. `is_main_query()` is retained as the sole duplicate-check guard.

### Changed

- **Audio Format field moved above MP3 Bitrate** — the Piper tab now shows Audio Format first, then the relevant bitrate selectors.

## [1.1.0] — 2026-05-10

### Added

- **Opus audio format** — new "Audio Format" selector (MP3 / Opus) in the Piper tab. Opus encodes with `libopus` (`-application voip`) for better quality at lower bitrates. Separate bitrate selector for Opus: 24k (standard), 16k (compact), 12k (minimal). Cache key includes format so switching regenerates files.
- **Gutenberg sidebar format support** — preview player uses `<source>` with explicit `type="audio/ogg"` for Opus files. Format tracked in `_piperless_audio_format` post meta and returned in REST responses.
- **ffprobe-based duration detection** — for MP3 and Opus files, uses `find_ffprobe()` (same directory as resolved ffmpeg) to read exact duration. Falls back to WAV header calculation.
- **Cache entry deletion clears post meta** — deleting an entry now removes all related meta fields from the owning post.
- **Server-side cache sorting** — Size and Created columns now sort the entire dataset before pagination, not just the current page.
- **Professional pagination** — Previous/Next/First/Last buttons, smart page numbers with ellipsis, "X items" count, placed at both top and bottom of the cache browser.

### Changed

- **FFmpeg Binaries Path** — field renamed from "FFmpeg Binary Path" to reflect that the directory is used for the full toolchain (ffmpeg, ffprobe). Description updated to mention MP3/Opus.
- **Cache scanning includes `.opus`** — all cache methods (`get_entries`, `clear`, `delete`, `stats`, `clear_orphans`) now handle `.opus` alongside `.mp3` and `.wav`.
- **Opus cache badge** — blue badge (#1565c0) in the cache browser Format column.
- **Translations** — all 8 locales at 117/117 strings.

## [1.0.0] — 2026-05-09

### Added

- **Voice model aliases** — assign custom display names to voice models from the Piper tab. Aliases appear in admin dropdowns and the Gutenberg sidebar, making technical model names like "en_US-lessac-low" readable as "American Female". Stored in `piperless_settings['voice_aliases']`.
- **Accessibility landmark** — player container exposes `role="region"` + `aria-label="Audio transcript: Post Title"`. Screen readers announce the player's purpose and allow keyboard navigation as a landmark region.
- **Configurable player max-width** — new "Player Max Width" field in the Styling tab. Set to any pixel value (default 680) or 0 for 100% width. Injected as inline CSS; works across all 6 themes and admin preview.
- **"Alias" string** translated to all 5 locales (de_DE, fr_FR, es_ES, nl_NL, zh_CN).

### Changed

- **Voice column** in model table shows original lowercase names (`amy`, `lessac`) instead of `ucfirst()`.
- **Gutenberg voice dropdown** wired to use aliases from the REST API.
- **Title moved inside player container** — `__title` div is now a flex child of `.piperless-player`, using `flex-wrap: wrap` + `flex: 0 0 100%`. Eliminates gap between title and controls.
- **Translations** — all 5 locales at 100/100 strings including "Audio transcript: %s" accessibility label.

## [0.8.2] — 2026-05-09

### Fixes

- **Player `max-width: 680px` restored** after earlier 100% width experiment.
- **CSS cache bust**

## [0.8.1] — 2026-05-09

### Changed

- **Title moved inside player flex container** — `__title` is now a child of `.piperless-player` with `flex-wrap: wrap`. Applies to all 6 themes.
- **Base player margin** reduced from `1.5em` to `0.5em` vertical.

## [0.8.0] — 2026-05-09

### Fixed

- **Title spacing gap** — `.piperless-player__title` `margin-bottom` set to `0`, player `margin` reduced to close gap between title and player on themed frontends.

## [0.7.9] — 2026-05-09

### Fixed

- **Title margin** — `.piperless-player__title` removed `margin-bottom: 4px`.
- Removed unused `.piperless-player__title + .piperless-player` sibling collapse rule (never matched DOM structure).

## [0.7.8] — 2026-05-09

### Security

- **`escapeshellcmd` → `escapeshellarg`** on ffmpeg binary path in `Cache_Manager::put()`. All shell calls now use `escapeshellarg` — zero `escapeshellcmd()` calls remain in the codebase.
- **Removed `FOLLOW_SYMLINKS`** from model directory scanner to prevent symbolic links from escaping the allowed models directory.
- **Removed duplicate database query** in `Cache_Manager::get_entries()` — dead code that re-queried `_piperless_cache_key` meta into an unused variable.

## [0.7.7] — 2026-05-09

### Added

- **Player Preview field label** — "Player Preview" now appears as a proper left-aligned label in the Styling tab.
- **Show Duration preview live** — toggling the checkbox instantly shows/hides the duration in the player preview, no save required.

### Changed

- **Model table layout** — removed the Name column. "Available models" now appears as a field label to the left. Columns are shrink-wrapped (`table-layout: auto`, `max-width: 620px`) so the Preview button stays near the data on wide screens.
- **Model table sorting** — Voice and Quality columns are click-to-sort. Voice sorts alphabetically; Quality sorts by tier priority (low → medium → high). Sort arrows initialize on page load.
- **Voice names capitalized** in model table.
- **Minimal player style** — removed the bottom border divider for a cleaner look.

## [0.7.6] — 2026-05-09

### Changed

- _Incremental CSS/JS cache-bust bumps._

## [0.7.2] — 2026-05-09

### Added

- **Sortable model table** — Voice and Quality columns in the model preview table are now click-to-sort. Voice sorts alphabetically; Quality sorts by tier priority (low → medium → high). Sort arrows show active column and direction on load.

### Changed

- **Voice names capitalized** — model table voice column now displays with the first letter uppercased (e.g., "Amy").
- **Per-post overrides** — Gutenberg sidebar gained Player Style and Placement dropdowns in a new Display Settings panel, separate from Voice Settings. Per-post meta (`_piperless_style`, `_piperless_placement`) overrides global settings.

## [0.7.1] — 2026-05-09

### Added

_Nothing new — version skipped due to uploader glitch._

## [0.7.0] — 2026-05-09

### Added

- **NewsViews Classic player style** — based on the NewsViews theme with `#233452` dark navy replacing the burgundy accent.
- **Above & below content** placement option — renders the player both before and after the post content.
- **Dropdown separators** — visual divider lines in the Player Style and Placement dropdowns, separating preset options from Custom CSS / Manual.

### Changed

- **Style dropdown reordered** — sorted alphabetically (Classic, Minimal, Modern Dark, NewsViews, NewsViews Classic) with Custom CSS remaining last.
- **Player width** — removed `max-width: 680px` from base styles. Players now fill 100% of their container.

### Fixed

- **Audio player progress bar, seeking, and time display** — all three now work on WAV-only setups. The REST proxy gained HTTP `Range` request support (`206 Partial Content` + `Content-Range`), which browsers require for duration detection and seeking. Removed the hardcoded `type="audio/wav"` attribute so the browser auto-detects MIME type from the proxy's `Content-Type` header. Preload changed from `metadata` to `auto`.
- **`is_path_accessible()` fatal crash** — when `realpath()` returned `false` on parent directories outside `open_basedir`, the method fell back to a non-string causing `str_starts_with()` TypeError.
- **`file_exists('/')` warning** — parent-directory walker in `is_path_accessible()` now stops at filesystem root.
- **ffmpeg `open_basedir` warnings** — `find_ffmpeg()` now suppresses `file_exists()`/`is_executable()` on default and custom paths.

## [0.6.3] — 2026-05-09

### Fixed

- **Audio player progress bar, seeking, and time display** — all three now work on WAV-only setups. The REST proxy gained HTTP `Range` request support (`206 Partial Content` + `Content-Range`), which browsers require for duration detection and seeking. Removed the hardcoded `type="audio/wav"` attribute so the browser auto-detects MIME type from the proxy's `Content-Type` header. Preload changed from `metadata` to `auto` to ensure the browser reads enough data for duration.
- **`is_path_accessible()` fatal crash** — when `realpath()` returned `false` on parent directories outside `open_basedir`, `$real_path` became `false` instead of a string, crashing `str_starts_with()`. Now falls back to the original path string.
- **`file_exists('/')` warning** — the parent-directory walker in `is_path_accessible()` now stops at filesystem root (`'/' !== $parent`) and suppresses all `file_exists()`/`realpath()` calls with `@`.
- **ffmpeg `open_basedir` warnings** — `find_ffmpeg()` now uses `@` suppression on all `file_exists()`/`is_executable()` calls for both custom and default paths.

### Changed

- **Player width** — removed `max-width: 680px` from player base styles. Players now fill 100% of their container.

## [0.6.2] — 2026-05-09

### Fixed

- **`file_exists('/')` warning on `open_basedir`** — `is_path_accessible()` parent-directory walker now stops at filesystem root (`'/' !== $parent`) and suppresses `file_exists()` and `realpath()` with `@`. Prevents the walker from checking `/` when the configured path doesn't exist on the server.

## [0.6.1] — 2026-05-09

### Fixed

- **Admin panel timeout on `open_basedir`** — `check_binary_status()`, `scan_models()`, and `synthesise()` now check `open_basedir` with a pure string-path comparison before any filesystem call. On hosts where `file_exists()`/`is_dir()` hang on blocked paths (stale NFS mounts, slow network filesystems), the page now loads instantly. Filesystem calls also use `@` suppression as a second layer against warning-log delays.

## [0.6.0] — 2026-05-09

### Added

- **Performance tab** — configurable Piper process timeout (30–3600s, default 300) and audio endpoint rate limit (1–600 req/min per IP, default 60). Both clamp to safe ranges and pre-fill with existing defaults.

### Changed

- **Block text extraction rewritten** — the Skip Embedded Content path now extracts text directly from the block tree (`innerHTML` recursion) instead of calling `render_block()` + `the_content`. Fixes a regression where paragraphs and headings were lost, leaving only the post title.
- **Raw PCM synthesis streams to temp file** — Piper's stdout is now streamed in 64KB chunks to a temp file instead of loaded entirely into memory via `stream_get_contents`. Avoids 25+ MB peaks on long posts.

### Security

- **REST endpoints** — `rest_status`, `rest_generate`, and `rest_remove_audio` now verify per-post ownership (`current_user_can('edit_post', $post_id)`) in addition to the `edit_posts` capability gate.
- **Shell escaping** — all `escapeshellcmd` calls replaced with `escapeshellarg` for binary paths across all three synthesis modes and `run_help()`. Zero `escapeshellcmd` remaining.
- **Error capture** — `error_clear_last()` called before every `@`-suppressed filesystem operation that's followed by `log_last_error()`, making error attribution deterministic.
- **CSS sanitization** — custom CSS now strips lines containing `url()`, `expression()`, `@import`, `behavior:`, and `-moz-binding` in addition to HTML tag removal.
- **Rate limiting** — audio proxy endpoint now rate-limited per IP via transient (configurable in Performance tab).
- **Synthesis mutex** — only one Piper process per cache key at a time; concurrent requests for the same content receive a "generation already in progress" response.
- **Auto-generate deferred** — `maybe_auto_generate` now schedules a `wp_schedule_single_event` cron job instead of running Piper synchronously on publish, preventing browser hangs.
- **Log file permissions** — `chmod 0600` applied after every log write, restricting read to the file owner on servers without `.htaccess` protection.
- **REST models endpoint** — absolute filesystem paths stripped from the response; only the model basename is exposed.
- **Shutdown hooks** — `stream_file()` now fires `do_action('shutdown')` before `die()`, allowing WordPress cron spawners and cleanup callbacks to execute.

## [0.5.0] — 2026-05-09

### Added

- **`set_time_limit()` guard** on all Piper synthesis calls — bumps the PHP execution time limit to 300s during `proc_open`, then restores it. Prevents `max_execution_time` kills on long posts.
- **Logger error fallback** — when the dedicated `piperless.log` can't be written (unwritable uploads directory), log entries fall through to PHP's `error_log()`, ensuring diagnostics are never completely lost.
- **`log_last_error()` helper** — captures `error_get_last()` after `@`-suppressed filesystem operations (failed `file_put_contents`, `mkdir`, `file_get_contents`) and writes the OS-level error to the log. Wired into `Cache_Manager` and `Piper` model validation.

### Changed

- **`scan_models()` cached per request** — the models directory is now scanned once and the result reused across admin page load (previously 3×), model preview table, and JS localisation.

## [0.4.0] — 2026-05-09

### Added

- **Binary pre-flight check** — on every admin page load, the plugin checks whether the Piper binary is configured, exists, and is executable using only filesystem calls (no binary invocation, no timeout risk). A dismissible WordPress admin notice warns the user with a specific message for each state: not configured, not found, or not executable.

## [0.3.1] — 2026-05-09

### Changed

- **Logging Level** moved from Content tab to Logs tab — now appears as the first setting above the log viewer, with its own Save button.

## [0.3.0] — 2026-05-09

### Added

- **Skip Embedded Content** setting — when enabled and no manual excerpt exists, Gutenberg embed blocks (core/embed, core-embed/*, extensible via `piperless_skip_blocks` filter) are stripped from the body before text extraction.
- **nl_NL (Dutch) translation** — full locale support with 84 translated strings, compiled .po/.mo.
- **Translation toolchain** — `tools/pot2json.sh`, `tools/sync-translations.sh`, `tools/json2po.sh`, `tools/lock-translations.sh`, and `Makefile` targets for extracting, syncing, converting, and locking per-locale JSON translation files.

### Changed

- **Admin panel restructured** — the single Configuration tab is now three: **Piper** (TTS engine settings), **Content** (auto-generate, skip embeds, logging), and **Styling** (player appearance/placement). All three share one form.
- Model preview table and player preview moved within their respective tabs.

## [1.1.6] — 2026-05-08

### Fixed

- Model enumeration regression from 1.1.5: models without a `.onnx.json` companion file are no longer excluded. Only models where the `.json` exists but is broken (LFS pointer, empty, malformed JSON) are filtered out. Missing `.json` is allowed since native Piper reads config from the `.onnx` file itself.

## [1.1.5] — 2026-05-08

### Fixed

- `scan_models()` now validates each model's `.onnx.json` companion file before listing it. Detects: missing JSON, empty files, Git LFS pointer files (common on Hugging Face without git-lfs), and malformed JSON. Models with broken configs are excluded from the UI and logged with specific warnings in the Debug Log.

## [1.1.4] — 2026-05-08

### Fixed

- Quality tier detection expanded to recognize `lite`, `small`, `fast`, `quality` in addition to `low`/`medium`/`high`. Filterable via `piperless_quality_tiers`.
- `scan_models()` now warns in the Debug Log when a model's companion `.onnx.json` file is missing or empty — the leading cause of `JSONDecodeError` with the Python Piper wheel.
- Default Quality dropdown in admin now includes Lite and Small options.

## [1.1.3] — 2026-05-08

### Added

- **open_basedir detection**: all filesystem checks (binary path, models directory) now detect when the path is outside PHP's `open_basedir` restriction and show a clear, actionable error message. The Piper Configuration section header displays the current open_basedir status.
- Admin page load now writes an INFO-level log entry, so the Debug Log always shows at least one entry when the page is accessed — proving the logger works.

### Changed

- `scan_models()` logs a WARNING when the models directory is outside open_basedir.
- Test Connection response now includes `open_basedir` info.

## [1.1.2] — 2026-05-08

### Fixed

- Debug Log viewer was non-functional — it called the wrong AJAX endpoint and never displayed log data. Now renders server-side on page load (`tail 50`) and refreshes via the new `piperless_log_tail` AJAX handler.
- Test Connection error for missing binary now includes the exact path that was checked, making misconfiguration immediately visible.

## [1.1.1] — 2026-05-08

### Fixed

- `scan_models()` now wraps the recursive directory iterator in try-catch and logs the scanned directory path + file count. Permission errors or unreadable subdirectories no longer silently return an empty list — the error appears in the Debug Log.
- Switched from `SplFileInfo::getExtension()` to `pathinfo(…, PATHINFO_EXTENSION)` for broader PHP compatibility with the recursive iterator.
- Added `FOLLOW_SYMLINKS` flag so symlinked model directories are followed.

## [1.1.0] — 2026-05-08

### Added

- **Positional wrapper mode** — for custom scripts like `/opt/bin/run-piper` that take `binary model_path "text"` as positional arguments and write `output.wav` to CWD. Choose "Positional" in the new Piper Interface Mode setting.
- `piper_interface` setting (Auto-detect / Standard / Positional) — overrides auto-detection for non-standard wrappers. Test Connection reports the effective mode.

### Changed

- `scan_models()` now scans the models directory recursively using `RecursiveDirectoryIterator`, so models nested in subdirectories are discovered.
- The Default Voice text field is now a dropdown populated from discovered model voice names, with an "— Use first available —" empty option.
- Default `models_directory` changed to `/opt/var/piper/voices`.

## [1.0.4] — 2026-05-08

### Fixed

- Test Connection no longer treats a non-zero `--help` exit as a hard failure. Python wheel wrappers that don't implement `--help` now report OK (binary found) with the detected output mode. The `--help` invocation is also cached so `detect_output_mode()` and `test()` share a single call.

## [1.0.3] — 2026-05-08

### Added

- Auto-detection of Piper output mode: `Piper` class now probes `--help` to determine whether the binary supports `--output-raw` (native CLI) or `--output_file` (Python wheel wrappers like `/opt/bin/run-piper`). Falls back to file mode for unknown wrappers. Test Connection button now shows the detected mode.

### Changed

- Default Piper binary path is now empty (force user to configure), with an updated description mentioning Python wheel wrappers.

## [1.0.2] — 2026-05-08

### Fixed

- Removed invalid module-level `useDispatch()` call in gutenberg.js that crashed the script with "Invalid hook call", preventing the Gutenberg sidebar from appearing at all. `createNotice` was already correctly obtained inside the component.

## [1.0.1] — 2026-05-08

### Fixed

- Autoloader now converts underscores to hyphens in class names, fixing "Class 'Piperless\\Cache_Manager' not found" fatal error on plugin activation.

## [1.0.0] — 2024-01-01

### Added

- Piper TTS integration via CLI wrapper (`Piper` class) with proc_open, raw PCM → WAV conversion, length-scale quality control, and voice model scanner.
- Audio transcript generation pipeline (`Transcriber` class): excerpt-first text extraction, SHA-256 content caching, WAV duration parsing from RIFF headers.
- Hash-based audio cache (`Cache_Manager`) in `wp-content/uploads/piperless/` with orphan detection (cross-referenced post meta), flush, and stats.
- PSR-3-style file logger (`Logger`) with 8 severity levels, WordPress debug.log integration, and dedicated `piperless.log`.
- Admin settings page under Settings → Piperless with three sections:
  - **Piper Configuration** — binary path, models directory, default voice/language/quality, logging level, and Test Connection button.
  - **Voice Model Previews** — discovered models table with per-model audio preview (play/pause, cached per model + quality).
  - **Audio Player Settings** — 3 preset themes (Classic, Modern Dark, Minimal) + Custom CSS, player placement (before/after/manual), duration toggle.
  - **Player Preview** — live theme preview that swaps on dropdown change.
  - **Cache Management** — clear orphaned audio, flush entire cache, live stats.
  - **Debug Log** — log viewer with refresh and clear.
- Gutenberg PluginSidebar with Generate/Regenerate Audio button, per-post voice/language/quality overrides (SelectControls), audio preview player, and remove button.
- Custom HTML5 audio player (zero-dependency JS) with play/pause SVG toggle, click-to-seek progress bar, buffer indicator, duration display (mm:ss), touch support, MutationObserver for dynamic content, error state handling, and multi-instance support.
- REST API endpoints: POST generate, GET status, GET models, DELETE audio.
- Shortcode: `[piperless_player post_id="123"]`.
- `the_content` filter auto-placement (before/after/manual).
- `build.sh` — reads version from plugin header, creates `piperless-X.Y.Z.zip`.
- `uninstall.php` — removes all options, post meta, cached files, and scheduled hooks.
- Full i18n support with `piperless.pot` (100+ strings).
- Activation version checks (WP 6.0+, PHP 8.0+) with user-facing error messages.
- Security: ABSPATH guards, `index.php` in all directories, `.htaccess` deny in cache dir, nonce verification on all AJAX/REST endpoints, capability checks, input sanitization, output escaping.
