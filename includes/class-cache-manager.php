<?php
/**
 * Audio file cache manager.
 *
 * @package Piperless
 */

declare(strict_types=1);

namespace Piperless;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audio file cache manager.
 *
 * Stores generated WAV/MP3 files in wp-content/uploads/piperless/.
 * Files are content-addressed: the cache key is a SHA-256 hash of
 * text + model + language + quality + bitrate, so identical content
 * always produces the same key and reuses the file.
 *
 * ## MP3 conversion
 *
 * When ffmpeg is available (auto-detected from common paths or
 * configured via piper_ffmpeg_binary), WAV data is written to a temp
 * file, converted to MP3 via exec(), and stored as .mp3.  If ffmpeg
 * is unavailable or conversion fails, the raw WAV is stored as .wav.
 * Both formats coexist in the same directory; the REST proxy prefers
 * MP3 and falls back to WAV.
 *
 * ## Orphan detection
 *
 * clear_orphans() cross-references every file in the cache directory
 * against the _piperless_cache_key post meta across all posts.  Files
 * with no matching post are deleted.  Model preview and test preview
 * files (model_preview_*, piperless_test_preview*) are silently
 * cleaned up but not counted in the returned total.
 *
 * ## Security
 *
 * The cache directory is protected by .htaccess (Deny from all) on
 * Apache.  Audio is served exclusively through the REST API proxy
 * endpoint /piperless/v1/audio, which validates cache keys and
 * supports HTTP Range requests for seeking.
 *
 * @since 0.1.0
 */
class Cache_Manager {

	/** @var Logger */
	private Logger $logger;

	/** @var string Absolute path to the cache directory. */
	private string $cache_dir;

	/** @var string URL to the cache directory. */
	private string $cache_url;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger  = $logger;
		$upload_dir    = wp_upload_dir();
		$this->cache_dir = trailingslashit( $upload_dir['basedir'] ) . 'piperless';
		$this->cache_url = trailingslashit( $upload_dir['baseurl'] ) . 'piperless';
	}

	/**
	 * Generate a cache key from text and voice parameters.
	 *
	 * @param string $text     The text content.
	 * @param string $model    Model file path.
	 * @param string $language Language code.
	 * @param string $quality  Quality tier.
	 * @return string SHA-256 hash.
	 */
	public function cache_key( string $text, string $model, string $language, string $quality, string $bitrate = '', string $format = 'mp3' ): string {
		$seed = $text . '|' . $model . '|' . $language . '|' . $quality;
		if ( '' !== $bitrate ) {
			$seed .= '|br:' . $bitrate;
		}
		if ( '' !== $format ) {
			$seed .= '|fmt:' . $format;
		}
		return hash( 'sha256', $seed );
	}

	/**
	 * Check if a cached audio file exists.
	 *
	 * @param string $cache_key The cache key.
	 * @return bool
	 */
	public function exists( string $cache_key ): bool {
		$settings = get_option( 'piperless_settings', [] );
		$format   = $settings['piper_audio_format'] ?? 'mp3';
		return file_exists( $this->file_path( $cache_key, $format ) )
			|| file_exists( $this->file_path( $cache_key, 'mp3' ) )
			|| file_exists( $this->file_path( $cache_key, 'opus' ) )
			|| file_exists( $this->cache_dir . '/' . $cache_key . '.wav' );
	}

	/**
	 * Get the absolute file path for a cache key (MP3).
	 *
	 * @param string $cache_key Cache key.
	 * @return string
	 */
	public function file_path( string $cache_key, string $format = 'mp3' ): string {
		return $this->cache_dir . '/' . $cache_key . '.' . $format;
	}

	/**
	 * Get the URL for a cache key.
	 *
	 * @param string $cache_key Cache key.
	 * @return string
	 */
	public function file_url( string $cache_key ): string {
		return $this->proxy_url( $cache_key );
	}

	/**
	 * Store audio data in the cache (WAV in → MP3 stored).
	 *
	 * @param string $cache_key Cache key.
	 * @param string $data      Raw WAV data.
	 * @return bool
	 */
	public function put( string $cache_key, string $data ): bool {
		$settings = get_option( 'piperless_settings', [] );
		$ffmpeg   = $this->find_ffmpeg();
		$format   = $settings['piper_audio_format'] ?? 'mp3';
		$bitrate  = ( 'opus' === $format )
			? ( $settings['piper_opus_bitrate'] ?? '24k' )
			: ( $settings['piper_mp3_bitrate'] ?? '32k' );

		if ( null !== $ffmpeg ) {
			// Write WAV to temp, convert to selected format.
			$wav_tmp = tempnam( sys_get_temp_dir(), 'piperless_' ) . '.wav';

			error_clear_last();
			if ( false === @file_put_contents( $wav_tmp, $data, LOCK_EX ) ) {
				$this->logger->log_last_error( 'Temp WAV write' );
				$this->logger->error( 'Failed to write temp WAV for conversion.' );
				return false;
			}

			$out_path = $this->file_path( $cache_key, $format );

			if ( 'opus' === $format ) {
				$cmd = sprintf(
					'%s -i %s -c:a libopus -b:a %s -ac 1 -application voip -y %s 2>&1',
					escapeshellarg( $ffmpeg ),
					escapeshellarg( $wav_tmp ),
					escapeshellarg( $bitrate ),
					escapeshellarg( $out_path )
				);
			} else {
				$cmd = sprintf(
					'%s -i %s -codec:a libmp3lame -b:a %s -ac 1 -y %s 2>&1',
					escapeshellarg( $ffmpeg ),
					escapeshellarg( $wav_tmp ),
					escapeshellarg( $bitrate ),
					escapeshellarg( $out_path )
				);
			}

			$output = [];
			$ret    = 0;
			exec( $cmd, $output, $ret );
			@unlink( $wav_tmp );

			if ( 0 === $ret && file_exists( $out_path ) && filesize( $out_path ) > 0 ) {
				$format_label = strtoupper( $format );
				$this->logger->info( "Cached {$format_label}: {key} ({size} bytes)", [
					'key'  => $cache_key,
					'size' => filesize( $out_path ),
				] );
				return true;
			}

			$this->logger->error( "ffmpeg {$format} conversion failed for {key}, code {code}", [
				'key'  => $cache_key,
				'code' => $ret,
			] );
			// Fall through to store WAV if ffmpeg failed.
		}

		// No ffmpeg — store WAV as-is.
		$wav_path = $this->cache_dir . '/' . $cache_key . '.wav';
		error_clear_last();
		$written  = @file_put_contents( $wav_path, $data, LOCK_EX );

		if ( false === $written ) {
			$this->logger->log_last_error( 'Cache file write' );
			$this->logger->error( 'Failed to write cache file: {path}', [ 'path' => $wav_path ] );
			return false;
		}

		$this->logger->info( 'Cached WAV: {key} ({size} bytes) — ffmpeg unavailable', [
			'key'  => $cache_key,
			'size' => $written,
		] );

		return true;
	}

	/**
	 * Retrieve cached audio data.
	 *
	 * @param string $cache_key Cache key.
	 * @return string|null Raw WAV data or null.
	 */
	public function get( string $cache_key ): ?string {
		$path = $this->file_path( $cache_key );

		if ( ! file_exists( $path ) ) {
			return null;
		}

		$data = @file_get_contents( $path );
		return ( false === $data ) ? null : $data;
	}

	/**
	 * Delete a specific cache entry.
	 *
	 * @param string $cache_key Cache key.
	 * @return bool
	 */
	public function delete( string $cache_key ): bool {
		$deleted = false;

		// Delete MP3.
		$mp3_path = $this->file_path( $cache_key, 'mp3' );
		if ( file_exists( $mp3_path ) ) {
			if ( @unlink( $mp3_path ) ) {
				$deleted = true;
			} else {
				$this->logger->warning( 'Failed to unlink: {path}', [ 'path' => $mp3_path ] );
			}
		}

		// Delete Opus.
		$opus_path = $this->file_path( $cache_key, 'opus' );
		if ( file_exists( $opus_path ) ) {
			if ( @unlink( $opus_path ) ) {
				$deleted = true;
			}
		}

		// Also delete legacy WAV.
		$wav_path = $this->cache_dir . '/' . $cache_key . '.wav';
		if ( file_exists( $wav_path ) ) {
			if ( @unlink( $wav_path ) ) {
				$deleted = true;
			} else {
				$this->logger->warning( 'Failed to unlink: {path}', [ 'path' => $wav_path ] );
			}
		}

		if ( $deleted ) {
			$this->logger->info( 'Deleted cached audio: {key}', [ 'key' => $cache_key ] );
		} else {
			$this->logger->debug( 'Nothing to delete for key: {key}', [ 'key' => $cache_key ] );
		}

		return $deleted;
	}

	/**
	 * Flush the entire cache.
	 *
	 * @return int Number of files deleted.
	 */
	public function flush(): int {
		if ( ! is_dir( $this->cache_dir ) ) {
			return 0;
		}

		$count = 0;

		// Delete MP3 files.
		$mp3_files = glob( $this->cache_dir . '/*.mp3' );
		if ( false !== $mp3_files ) {
			foreach ( $mp3_files as $file ) {
				if ( @unlink( $file ) ) { $count++; }
			}
		}

		// Delete Opus files.
		$opus_files = glob( $this->cache_dir . '/*.opus' );
		if ( false !== $opus_files ) {
			foreach ( $opus_files as $file ) {
				if ( @unlink( $file ) ) { $count++; }
			}
		}

		// Also clean up any legacy WAV files.
		$wav_files = glob( $this->cache_dir . '/*.wav' );
		if ( false !== $wav_files ) {
			foreach ( $wav_files as $file ) {
				if ( @unlink( $file ) ) { $count++; }
			}
		}

		$this->logger->info( 'Flushed {count} cached audio files.', [ 'count' => $count ] );
		return $count;
	}

	/**
	 * Clear disabled entries — entries not currently active for their post.
	 *
	 * @return int Number of files deleted.
	 */
	/**
	 * Clear orphaned files — cache entries not referenced by any post meta.
	 *
	 * @return int Number of orphaned files deleted.
	 */
	public function clear_orphans(): int {
		if ( ! is_dir( $this->cache_dir ) ) {
			return 0;
		}

		$entries = $this->get_entries( 1, 9999 );
		$count   = 0;

		$this->logger->debug( 'Clear orphans: scanning {total} entries', [ 'total' => $entries['total'] ] );

		foreach ( $entries['entries'] as $entry ) {
			if ( ! $entry['enabled'] ) {
				$this->logger->debug( 'Deleting orphaned entry: {key} ({file})', [
					'key'  => $entry['key'],
					'file' => $entry['filename'] ?? '?',
				] );
				if ( $this->delete( $entry['key'] ) ) {
					$count++;
				} else {
					$this->logger->warning( 'Failed to delete orphaned entry: {key}', [ 'key' => $entry['key'] ] );
				}
			}
		}

		// Also delete model preview and test preview files that accumulate
		// when models are changed or removed.  Not counted — they're not
		// "orphaned audio" in the user-facing sense.
		$preview_patterns = [ 'model_preview_*', 'piperless_test_preview*' ];
		foreach ( $preview_patterns as $pattern ) {
			foreach ( [ '.mp3', '.opus', '.wav' ] as $ext ) {
				$matches = glob( $this->cache_dir . '/' . $pattern . $ext );
				if ( false !== $matches ) {
					foreach ( $matches as $file ) {
						@unlink( $file );
					}
				}
			}
		}

		$this->logger->info( 'Cleared {count} orphaned/disabled audio files.', [ 'count' => $count ] );
		return $count;
	}

	/**
	 * Get cache statistics.
	 *
	 * @return array{cached_files:int,total_bytes:int}
	 */
	public function stats(): array {
		if ( ! is_dir( $this->cache_dir ) ) {
			return [ 'cached_files' => 0, 'total_bytes' => 0 ];
		}

		$total   = 0;
		$counted = 0;

		$all_files = array_merge(
			(array) glob( $this->cache_dir . '/*.mp3' ),
			(array) glob( $this->cache_dir . '/*.opus' ),
			(array) glob( $this->cache_dir . '/*.wav' )
		);

		foreach ( $all_files as $file ) {
			$key = basename( $file );
			$key = str_replace( [ '.mp3', '.opus', '.wav' ], '', $key );
			if ( str_starts_with( $key, 'model_preview_' ) || str_starts_with( $key, 'piperless_test_preview' ) ) {
				continue;
			}
			$size = @filesize( $file );
			if ( false !== $size ) {
				$total += $size;
				$counted++;
			}
		}

		return [
			'cached_files' => $counted,
			'total_bytes'  => $total,
		];
	}

	/**
	 * Get the proxy URL for serving a cached audio file through the REST API.
	 *
	 * @param string $cache_key Cache key.
	 * @return string
	 */
	public function proxy_url( string $cache_key ): string {
		return rest_url( 'piperless/v1/audio' ) . '?key=' . urlencode( $cache_key );
	}

	/**
	 * Find ffmpeg binary on the system.
	 *
	 * @return string|null
	 */
	public function find_ffmpeg(): ?string {
		static $cached = null;
		static $resolved_path = null;

		// Return cached result.
		if ( null !== $cached ) {
			return $resolved_path;
		}

		$cached        = false;
		$resolved_path = null;

		$settings = get_option( 'piperless_settings', [] );
		$custom   = $settings['piper_ffmpeg_binary'] ?? '';

		if ( '' !== $custom ) {
			// Support both full binary path and directory-only path.
			if ( @is_dir( $custom ) ) {
				$custom = rtrim( $custom, '/' ) . '/ffmpeg';
			}
			if ( @file_exists( $custom ) && @is_executable( $custom ) ) {
				$cached        = true;
				$resolved_path = $custom;
				return $resolved_path;
			}
			$this->logger->warning(
				'Configured ffmpeg binary not found or not executable: {path}. Trying auto-detection.',
				[ 'path' => $custom ]
			);
		}

		$candidates = apply_filters(
			'piperless_ffmpeg_paths',
			[ '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/bin/ffmpeg' ]
		);

		foreach ( $candidates as $path ) {
			if ( @file_exists( $path ) && @is_executable( $path ) ) {
				$cached        = true;
				$resolved_path = $path;
				$this->logger->info( 'Found ffmpeg at {path}', [ 'path' => $path ] );
				return $resolved_path;
			}
		}

		$this->logger->warning(
			'ffmpeg not found on this system — audio will be stored as WAV. Install ffmpeg or set the FFmpeg Binary Path in settings.'
		);

		$cached = true; // Negative cache — don't re-scan.
		return null;
	}

	/**
	 * Get paginated cache entries with associated post info.
	 *
	 * @param int $page     Page number (1-based).
	 * @param int $per_page Entries per page.
	 * @return array{entries:array,total:int,pages:int}
	 */
	public function get_entries( int $page = 1, int $per_page = 20, string $sort_by = 'created', bool $sort_asc = false ): array {
		if ( ! is_dir( $this->cache_dir ) ) {
			return [ 'entries' => [], 'total' => 0, 'pages' => 0 ];
		}

		// Collect all referenced cache keys from post meta.
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_piperless_cache_key'
			)
		);

		$key_to_post  = [];
		$key_to_model = [];
		foreach ( $rows as $row ) {
			$keys = maybe_unserialize( $row->meta_value );
			if ( is_array( $keys ) ) {
				foreach ( $keys as $k => $v ) {
					if ( is_string( $k ) && '' !== $k ) {
						$key_to_post[ $k ] = (int) $row->post_id;
						if ( is_string( $v ) && '' !== $v ) {
							$key_to_model[ $k ] = $v;
						}
					}
				}
			} elseif ( is_string( $keys ) ) {
				$key_to_post[ $keys ] = (int) $row->post_id;
			}
		}

		// Also collect mp3 keys.
		$rows_mp3 = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_piperless_cache_key'
			)
		);

		// Scan MP3, Opus, and legacy WAV files.
		$all_files = array_merge(
			(array) glob( $this->cache_dir . '/*.mp3' ),
			(array) glob( $this->cache_dir . '/*.opus' ),
			(array) glob( $this->cache_dir . '/*.wav' )
		);

		$entries  = [];
		foreach ( $all_files as $path ) {
			$ext  = pathinfo( $path, PATHINFO_EXTENSION );
			$key  = basename( $path, '.' . $ext );

			// Skip preview-only cache entries.
			if ( str_starts_with( $key, 'model_preview_' ) || str_starts_with( $key, 'piperless_test_preview' ) ) {
				continue;
			}

			$post_id  = $key_to_post[ $key ] ?? null;
			$title    = '';
			$edit_url = '';
			$model    = '';

			if ( null !== $post_id ) {
				$post = get_post( $post_id );
				if ( $post ) {
					$title    = $post->post_title;
					$edit_url = get_edit_post_link( $post_id, 'raw' );
				}
				$model = $key_to_model[ $key ] ?? '';
			}

			$size_bytes = filesize( $path );
			$is_mp3     = ( 'mp3' === $ext );

			// Check for companion formats.
			$has_mp3  = $is_mp3 || file_exists( $this->cache_dir . '/' . $key . '.mp3' );
			$has_opus = ( 'opus' === $ext ) || file_exists( $this->cache_dir . '/' . $key . '.opus' );
			$has_wav  = file_exists( $this->cache_dir . '/' . $key . '.wav' );

			// Check if this entry is the active audio for its post.
			$enabled = false;
			if ( null !== $post_id ) {
				$post_audio_url = get_post_meta( $post_id, '_piperless_audio_url', true );
				$enabled = ( $this->proxy_url( $key ) === $post_audio_url );
			}

			$settings = get_option( 'piperless_settings', [] );

			$mtime = filemtime( $path );

			$entries[] = [
				'key'        => $key,
				'filename'   => basename( $path ),
				'size_bytes' => $size_bytes,
				'has_mp3'    => $has_mp3,
				'has_opus'   => $has_opus,
				'bitrate'    => $has_opus ? ( $settings['piper_opus_bitrate'] ?? '24k' )
					: ( $has_mp3 ? ( $settings['piper_mp3_bitrate'] ?? '32k' ) : '' ),
				'created'    => $mtime ? gmdate( 'Y-m-d H:i', $mtime ) : '',
				'enabled'    => $enabled,
				'model'      => $model,
				'post_id'    => $post_id,
				'post_title' => $title,
				'edit_url'   => $edit_url,
				'orphaned'   => ( null === $post_id ),
				'proxy_url'  => $this->proxy_url( $key ),
			];
		}

		// ── Sort before pagination ──────────────────────────────
		if ( 'size' === $sort_by ) {
			usort( $entries, function ( $a, $b ) use ( $sort_asc ) {
				return $sort_asc
					? ( ( $a['size_bytes'] ?? 0 ) <=> ( $b['size_bytes'] ?? 0 ) )
					: ( ( $b['size_bytes'] ?? 0 ) <=> ( $a['size_bytes'] ?? 0 ) );
			} );
		} else {
			usort( $entries, function ( $a, $b ) use ( $sort_asc ) {
				return $sort_asc
					? ( $a['created'] ?? '' ) <=> ( $b['created'] ?? '' )
					: ( $b['created'] ?? '' ) <=> ( $a['created'] ?? '' );
			} );
		}

		$total = count( $entries );
		$pages = (int) ceil( $total / max( 1, $per_page ) );
		$page  = max( 1, min( $page, max( 1, $pages ) ) );
		$offset = ( $page - 1 ) * $per_page;

		$entries = array_slice( $entries, $offset, $per_page );

		return [
			'entries' => array_values( $entries ),
			'total'   => $total,
			'pages'   => $pages,
		];
	}

	/**
	 * Delete a cache entry by key (both WAV and MP3).
	 *
	 * @param string $cache_key Cache key.
	 * @return bool
	 */
	public function delete_entry( string $cache_key ): bool {
		return $this->delete( $cache_key );
	}

	/**
	 * Return the cache directory path.
	 *
	 * @return string
	 */
	public function dir(): string {
		return $this->cache_dir;
	}

	/**
	 * Ensure the cache directory exists.
	 *
	 * @return bool
	 */
	public function ensure_dir(): bool {
		if ( is_dir( $this->cache_dir ) ) {
			return true;
		}

		$created = wp_mkdir_p( $this->cache_dir );

		if ( ! $created ) {
			$this->logger->error( 'Failed to create cache directory: {dir}', [ 'dir' => $this->cache_dir ] );
			return false;
		}

		// Security: prevent all direct access. Audio served via REST API.
		$htaccess = $this->cache_dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "Deny from all\n" );
		}
		if ( ! file_exists( $this->cache_dir . '/index.php' ) ) {
			@file_put_contents( $this->cache_dir . '/index.php', "<?php // Silence is golden.\n" );
		}

		return true;
	}
}
