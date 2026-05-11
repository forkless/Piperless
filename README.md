# Piperless — Audio Transcripts for WordPress

**Turn your WordPress posts into high-quality audio transcripts using local, open-source neural TTS. No API keys. No subscriptions. No data leaving your server.**

> **Transparency note:** Piperless was developed using AI-assisted CLI tools (DeepSeek V4 Pro). Every line of code, security review, and translation was generated through prompt-driven development — then reviewed, tested, and hardened by a human. This weekend project started out of curiosity for what AI technology can do. Total development cost: one working day and less than the price of a cup of coffee in AI tokens.


## What It Does

Piperless converts every published post into a natural-sounding audio transcript using the [Piper](https://github.com/rhasspy/piper) text-to-speech engine. It generates audio automatically on publish, caches the results, and embeds a customizable HTML5 audio player in your content.

- **Visually impaired** readers can listen instead of read
- **Dyslexic** readers get an alternative format
- **Low-literacy** audiences can access your content
- **Commuting** readers can consume posts as audio
- **Non-native speakers** benefit from hearing correct pronunciation


## Features

- **Automatic generation** — optionally generate audio when a post is published. No per-post action required.
- **6 player themes** — Classic, Minimal, Modern Dark, Ron Burgundy, Dan Rather Blue, and Custom CSS. Match your brand.
- **Per-post overrides** — customize voice, language, quality, player style, and placement for individual posts via the Gutenberg sidebar.
- **Content-addressed caching** — identical text + voice + language always produces the same audio file. Never regenerate the same content twice.
- **Multi-language** — Piper supports 20+ languages. Install the voice models you need and Piperless auto-discovers them.
- **Voice aliases** — rename technical model names like `en_US-lessac-low` to "American Female" in your admin panel.
- **MP3 conversion** — auto-converts Piper's WAV output to compact MP3 using ffmpeg (optional, falls back to WAV).
- **Smart text extraction** — uses the post excerpt when available to avoid reading embedded content (YouTube, Twitter, third-party embeds), decimal numbers, and other artifacts that produce garbled speech. Falls back to the full post body when no excerpt is set.
- **No external services** — Piper runs on your server. Text never leaves your infrastructure. GDPR-compliant by architecture.
- **Production-hardened** — 29/29 security audit clearance. Rate limiting, authorization layering, process timeout guards, open_basedir aware.
- **8 admin languages** — Dutch, German, French, Spanish, Chinese (Simplified), Japanese, Brazilian Portuguese, and Italian translations included.


## Why the Excerpt Matters

Piper reads text exactly as it appears. Without careful text selection, you get:

- **Embedded content read verbatim** — YouTube video descriptions, Twitter embed text, and third-party widget content get read as part of your article, confusing listeners.
- **Decimal numbers breaking sentence flow** — "The price increased by 3.5 percent" becomes "The price increased by 3" [pause] "5 percent" because Piper interprets the period as a sentence boundary.
- **Shortcodes and markup noise** — unrendered shortcodes and HTML artifacts in the post body produce gibberish audio.

Piperless solves this by **using the WordPress excerpt field first**. Write a clean, spoken-word version of your post in the excerpt — it's read as-is. If no excerpt exists, Piperless falls back to the post body with an optional filter to skip embedded blocks.

**Pro tip:** Think of the excerpt as your "audio script." It doesn't replace the post — it's the version that sounds natural when read aloud.


## Requirements

| Component | Minimum | Notes |
|-----------|---------|-------|
| WordPress | 6.0+ | Self-hosted |
| PHP | 8.0+ | |
| Piper TTS | Installed on server | [GitHub](https://github.com/rhasspy/piper) |
| Voice models | `.onnx` + `.json` files | 20+ languages available |
| ffmpeg | Optional | Auto-detected. Converts WAV → MP3 |
| Server | VPS or dedicated | Shared hosting cannot install Piper |

**Piperless will not work on shared hosting.** It requires shell access to install Piper and the ability to run system binaries via `proc_open`.


## Quick Start

### 1. Install Piper

```bash
# Download Piper binary for your platform from:
# https://github.com/rhasspy/piper/releases

# Or install via package manager (where available)
```

### 2. Download Voice Models

Download `.onnx` and `.onnx.json` files for your languages from the [Piper voice repository](https://huggingface.co/rhasspy/piper-voices). Place them in a directory accessible to PHP.

```bash
/opt/var/piper/voices/
├── en_US-lessac-low.onnx
├── en_US-lessac-low.onnx.json
├── en_US-amy-medium.onnx
├── en_US-amy-medium.onnx.json
├── nl_NL-ml-medium.onnx
└── nl_NL-ml-medium.onnx.json
```

### 3. Install Piperless

1. Download the latest release ZIP
2. Go to WordPress Admin → Plugins → Add New → Upload Plugin
3. Choose the ZIP file and click "Install Now"
4. Activate the plugin

### 4. Configure

Go to **Settings → Piperless** and set:

- **Piper Binary Path** — absolute path to the Piper executable (e.g., `/opt/bin/piper`)
- **Models Directory** — path to your `.onnx` voice models
- **Default Voice** — choose a default voice from the auto-discovered list
- Click **Test Connection** to verify Piper is working

### 5. Generate Your First Audio

Open any post in the block editor. In the Piperless sidebar panel, click **Generate Audio**. The audio player will appear in your post automatically based on your placement settings.


## Admin Panel

Piperless adds a settings page under **Settings → Piperless** with 8 tabs:

| Tab | Purpose |
|-----|---------|
| **Piper** | Configure the TTS engine, browse and preview voice models, set voice aliases |
| **Content** | Auto-generate on publish, skip embedded content during text extraction |
| **Styling** | Player theme, placement, custom CSS, player title, duration display |
| **Performance** | Piper process timeout (30–3600s), audio endpoint rate limiting |
| **Cache Management** | Stats, cache browser, clear orphaned files, flush entire cache |
| **Logs** | Logging level configuration, live log viewer, clear log |
| **Help** | Usage instructions and ffmpeg notes |
| **About** | Version and contact information |


## Player Themes

Six built-in themes plus unlimited custom CSS:

| Theme | Description |
|-------|-------------|
| **Classic** | Blue accent, clean borders |
| **Minimal** | Clean, understated |
| **Modern Dark** | Dark background |
| **Ron Burgundy** | Bold burgundy |
| **Dan Rather Blue** | Classic navy |
| **Custom CSS** | Full control — base theme with your own styles |

Each theme is a standalone CSS file. Switch themes instantly from the Styling tab.


## Accessibility

Piperless was built with accessibility in mind:

- **Screen reader landmark** — player container exposes `role="region"` and `aria-label="Audio transcript: Post Title"`
- **ARIA labels** — play/pause button and volume control are properly labeled
- **Keyboard navigation** — player is a navigable region
- **Content-as-transcript** — since the audio is generated from the post text, the text itself serves as the transcript for WCAG compliance


## Security

Piperless shells out to system binaries. It was built with defense-in-depth from the start:

- **Command injection prevention** — `escapeshellarg()` on every shell argument. Zero `escapeshellcmd()` calls.
- **Authorization** — REST endpoints verify per-post ownership, not just capability checks
- **Rate limiting** — configurable requests/minute per IP on the public audio proxy
- **Path traversal prevention** — cache keys regex-validated, model paths stripped from API responses
- **Error capture** — `error_clear_last()` before every suppressed filesystem call for deterministic diagnostics
- **Log file security** — `chmod 0600` after every write
- **Process isolation** — `set_time_limit()` guards with configurable timeouts
- **open_basedir aware** — string-prefix matching before any filesystem call to prevent hangs

Comprehensive review: **29/29 categories cleared. 1 logic bug found and fixed. Zero open findings.** [See full audit →](AUDIT.md)


## Translation

Piperless ships with complete translations for:

| Language | Locale | Coverage |
|----------|--------|----------|
| Dutch | nl_NL | 102/102 |
| German | de_DE | 102/102 |
| French | fr_FR | 102/102 |
| Spanish | es_ES | 102/102 |
| Chinese (Simplified) | zh_CN | 102/102 |
| Japanese | ja | 102/102 |
| Portuguese (Brazil) | pt_BR | 102/102 |
| Italian | it_IT | 102/102 |

The admin panel, Gutenberg sidebar, and frontend player are fully translated. See `languages/` for `.po` and `.mo` files.


## Filters & Hooks

### Filters

- `piperless_post_types` — post types supported by the plugin (default: `['post', 'page']`)
- `piperless_quality_tiers` — recognized quality labels in model filenames
- `piperless_skip_blocks` — Gutenberg block types to skip during text extraction
- `piperless_ffmpeg_paths` — paths to probe for ffmpeg auto-detection

### Shortcodes

- `[piperless_player]` — render the audio player for the current post
- `[piperless_player post_id="123"]` — render the player for a specific post


## Development

Piperless is built as 8 PHP classes with separation of concerns:

```
includes/
├── class-plugin.php         # Entry point, singleton, hook wiring
├── class-piper.php          # Piper CLI wrapper (3 interface modes)
├── class-transcriber.php    # Text extraction, generation pipeline, mutex
├── class-cache-manager.php  # Content-addressed file cache, MP3 conversion
├── class-gutenberg.php      # Block editor sidebar, REST API endpoints
├── class-settings.php       # Admin panel (8 tabs), AJAX handlers
├── class-player.php         # Frontend HTML5 player (6 themes)
└── class-logger.php         # PSR-3 logger with dual-channel fallback
```

### Build & Tooling

The `Makefile` wraps the build and translation toolchain:

```
make build               → Create piperless-X.Y.Z.zip
make translations        → Extract .pot → JSON, sync to all locales
make json2po             → Convert JSON translations back to .po/.mo
make check-translations  → Validate translation integrity
make lock-translations   → Lock all locales for translation work
make unlock-translations → Release all translation locks
make translation-status  → Show lock/completion for each locale
make clean               → Remove build artifacts
```

All tools run as standalone shell scripts in `tools/` — the Makefile is a convenience wrapper.

See `doc/index.html` for full developer documentation with architecture diagram, method tables, and security model.


## License

MIT-licensed. Piperless is free and open-source. Piper TTS is also MIT-licensed. Voice models vary — check individual model licenses.


## Links

- **Piper TTS:** [github.com/rhasspy/piper](https://github.com/rhasspy/piper)
- **Piper Voices:** [huggingface.co/rhasspy/piper-voices](https://huggingface.co/rhasspy/piper-voices)
- **Support:** [forkless.com](https://forkless.com)
- **Contact:** devs@forkless.com
