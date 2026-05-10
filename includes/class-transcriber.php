<?php
/**
 * Audio generation orchestrator.
 *
 * @package Piperless
 */

declare(strict_types=1);

namespace Piperless;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audio generation orchestrator.
 *
 * Coordinates the full text-to-speech pipeline for a WordPress post:
 *
 *   1. Resolve voice / language / quality from per-post meta, REST
 *      parameters, or plugin settings.
 *   2. Extract text — manual excerpt first, then content with optional
 *      embedded-block filtering (skip_embedded_content setting).
 *   3. Generate a SHA-256 cache key from text + model + parameters.
 *   4. Return cached audio if it exists.
 *   5. Acquire a mutex transient to prevent parallel Piper processes
 *      for the same cache key (5-minute TTL safety net).
 *   6. Shell out to Piper, wrap raw PCM in WAV, convert to MP3 if
 *      ffmpeg is available.
 *   7. Store in the cache directory, update post meta, release mutex.
 *
 * ## Concurrency
 *
 * A transient-based mutex (piperless_synthesising_<cache_key>) prevents
 * two requests from running Piper simultaneously for identical content.
 * The lock is released on all exit paths (success, synthesis failure,
 * cache-write failure).  A 5-minute TTL prevents permanent deadlocks.
 *
 * ## Text extraction
 *
 * When skip_embedded_content is enabled and no manual excerpt exists,
 * the post body is parsed as Gutenberg blocks.  Embed-type blocks
 * (core/embed, core-embed/*, extensible via piperless_skip_blocks) are
 * stripped before text is collected from innerHTML recursively.
 *
 * @since 0.1.0
 */
class Transcriber {

	/** @var Logger */
	private Logger $logger;

	/** @var Piper */
	private Piper $piper;

	/** @var Cache_Manager */
	private Cache_Manager $cache;

	/**
	 * Constructor.
	 *
	 * @param Logger        $logger Logger instance.
	 * @param Piper         $piper  Piper TTS wrapper.
	 * @param Cache_Manager $cache  Cache manager.
	 */
	public function __construct( Logger $logger, Piper $piper, Cache_Manager $cache ) {
		$this->logger = $logger;
		$this->piper  = $piper;
		$this->cache  = $cache;
	}

	/**
	 * Generate (or retrieve) an audio transcript for a post.
	 *
	 * @param int    $post_id  WordPress post ID.
	 * @param string $voice    Voice name override, or '' for default.
	 * @param string $language Language code override, or '' for default.
	 * @param string $quality  Quality override, or '' for default.
	 * @return array{success:bool,url:string|null,error:string|null}
	 */
	public function generate( int $post_id, string $voice = '', string $language = '', string $quality = '' ): array {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return $this->error( __( 'Post not found.', 'piperless' ) );
		}

		if ( ! in_array( $post->post_type, $this->supported_post_types(), true ) ) {
			return $this->error( __( 'Unsupported post type.', 'piperless' ) );
		}

		// Resolve parameters — per-post meta > REST params > settings defaults.
		$settings   = get_option( 'piperless_settings', [] );

		$post_voice    = get_post_meta( $post_id, '_piperless_voice', true );
		$post_language = get_post_meta( $post_id, '_piperless_language', true );
		$post_quality  = get_post_meta( $post_id, '_piperless_quality', true );

		$voice    = ( '' !== $voice ) ? $voice
			: ( ( ! empty( $post_voice ) ) ? $post_voice
			: ( $settings['default_voice'] ?? '' ) );
		$language = ( '' !== $language ) ? $language
			: ( ( ! empty( $post_language ) ) ? $post_language
			: ( $settings['default_language'] ?? 'en_US' ) );
		$quality  = ( '' !== $quality ) ? $quality
			: ( ( ! empty( $post_quality ) ) ? $post_quality
			: ( $settings['default_quality'] ?? 'medium' ) );

		$this->logger->debug( 'Generate: voice={voice} lang={lang} quality={quality} for post {id}', [
			'voice'   => $voice,
			'lang'    => $language,
			'quality' => $quality,
			'id'      => $post_id,
		] );

		// Per-post Piper overrides.
		$post_silence     = get_post_meta( $post_id, '_piperless_sentence_silence', true );
		$post_length_scale = get_post_meta( $post_id, '_piperless_length_scale', true );

		// Find the model.
		$model = $this->piper->find_model( $voice, $language, $quality );

		if ( null === $model ) {
			return $this->error( __( 'No suitable Piper voice model found. Check your models directory.', 'piperless' ) );
		}

		// Extract text — prefer excerpt, fallback to content.
		$text = $this->extract_text( $post, $settings );

		if ( '' === trim( $text ) ) {
			return $this->error( __( 'No text content available for this post.', 'piperless' ) );
		}

		// Cache key (includes bitrate so changing it regenerates files).
		$bitrate   = get_option( 'piperless_settings', [] )['piper_mp3_bitrate'] ?? '32k';
		$cache_key = $this->cache->cache_key( $text, $model, $language, $quality, $bitrate );

		// Store cache key in post meta before generation so it is tracked.
		$model_basename = basename( $model, '.onnx' );
		$this->store_cache_key( $post_id, $cache_key, $model_basename );

		// Return cached audio if available.
		if ( $this->cache->exists( $cache_key ) ) {
			$this->update_post_meta( $post_id, $cache_key, basename( $model, '.onnx' ) );

			$this->logger->info( 'Using cached audio for post {id}', [ 'id' => $post_id ] );

			return [
				'success' => true,
				'url'     => $this->cache->proxy_url( $cache_key ),
				'error'   => null,
			];
		}

		// Ensure cache directory.
		if ( ! $this->cache->ensure_dir() ) {
			return $this->error( __( 'Failed to create cache directory.', 'piperless' ) );
		}

		// Concurrency mutex: only one synthesis per cache key at a time.
		// Prevents parallel Piper processes from exhausting server resources.
		$mutex_key = 'piperless_synthesising_' . $cache_key;
		if ( false !== get_transient( $mutex_key ) ) {
			return $this->error( __( 'Audio generation already in progress for this content. Please wait.', 'piperless' ) );
		}
		set_transient( $mutex_key, 1, 300 ); // 5-minute TTL as safety net.

		// Generate audio.
		$this->logger->info( 'Generating audio for post {id} with model {model}', [
			'id'    => $post_id,
			'model' => basename( $model ),
		] );

		$wav = $this->piper->synthesise( $text, $model, $quality, $post_silence, $post_length_scale );

		if ( null === $wav ) {
			delete_transient( $mutex_key );
			return $this->error( __( 'Piper failed to generate audio. Check the logs for details.', 'piperless' ) );
		}

		// Store in cache — release mutex on write failure.
		if ( ! $this->cache->put( $cache_key, $wav ) ) {
			delete_transient( $mutex_key );
			return $this->error( __( 'Failed to write audio file to cache.', 'piperless' ) );
		}

		// Update post meta (MP3 conversion happens inside cache->put()).
		$this->update_post_meta( $post_id, $cache_key, basename( $model, '.onnx' ) );

		// Release mutex on success.
		delete_transient( $mutex_key );

		$this->logger->info( 'Audio generated for post {id}', [ 'id' => $post_id ] );

		return [
			'success' => true,
			'url'     => $this->cache->proxy_url( $cache_key ),
			'error'   => null,
		];
	}

	/**
	 * Get the audio status for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array{has_audio:bool,url:string|null,duration:float|null,cache_key:string|null}
	 */
	public function status( int $post_id ): array {
		$url       = get_post_meta( $post_id, '_piperless_audio_url', true );
		$duration  = get_post_meta( $post_id, '_piperless_duration', true );
		$cache_key = get_post_meta( $post_id, '_piperless_cache_key', true );

		return [
			'has_audio'  => ! empty( $url ),
			'url'        => $url ?: null,
			'duration'   => $duration ? (float) $duration : null,
			'cache_key'  => $cache_key ?: null,
		];
	}

	/**
	 * Extract text from a post.
	 *
	 * Priority: custom excerpt → filtered content → full content.
	 *
	 * When "Skip Embedded Content" is enabled and no manual excerpt exists,
	 * the post body is parsed as Gutenberg blocks and any embed-type blocks
	 * (core/embed, core-embed/*, plus blocks added via the piperless_skip_blocks
	 * filter) are excluded before text extraction.
	 *
	 * @param \WP_Post                $post     Post object.
	 * @param array<string,mixed>     $settings Plugin settings.
	 * @return string
	 */
	private function extract_text( \WP_Post $post, array $settings ): string {
		$title = trim( $post->post_title );

		// Check for manual excerpt first.
		$excerpt = $post->post_excerpt;

		if ( '' !== trim( $excerpt ) ) {
			$body = wp_strip_all_tags( $excerpt, true );
			return ( '' !== $title ? $title . '. ' : '' ) . $body;
		}

		// When skip_embedded_content is enabled and the post has blocks,
		// strip embed-type blocks before rendering.
		$skip_embeds = ! empty( $settings['skip_embedded_content'] );

		if ( $skip_embeds && has_blocks( $post->post_content ) ) {
			$blocks  = parse_blocks( $post->post_content );
			$blocks  = $this->filter_embed_blocks( $blocks );

			// Extract text directly from the block tree — avoids
			// render_block() + the_content double-processing which
			// can strip paragraph/heading content.
			$content = $this->extract_block_text( $blocks );
			$content = wp_strip_all_tags( $content, true );

			return ( '' !== $title ? $title . '. ' : '' ) . trim( $content );
		}

		// Standard fallback: render everything.
		$content = get_the_content( null, false, $post );
		$content = apply_filters( 'the_content', $content );
		$content = wp_strip_all_tags( $content, true );

		return ( '' !== $title ? $title . '. ' : '' ) . trim( $content );
	}

	/**
	 * Recursively extract text from a parsed block tree.
	 *
	 * Walks the block tree and concatenates innerHTML from every block,
	 * recursing into innerBlocks.  This avoids the render_block() +
	 * the_content double-processing path which can lose content.
	 *
	 * @param array<int, array> $blocks Parsed blocks.
	 * @return string
	 */
	private function extract_block_text( array $blocks ): string {
		$text = '';

		foreach ( $blocks as $block ) {
			// Collect the block's own inner HTML (e.g. the <p> content).
			$text .= $block['innerHTML'] ?? '';

			// Recurse into inner blocks (columns, groups, etc.).
			if ( ! empty( $block['innerBlocks'] ) ) {
				$text .= $this->extract_block_text( $block['innerBlocks'] );
			}
		}

		return $text;
	}

	/**
	 * Recursively remove embedded content blocks from a parsed block list.
	 *
	 * Skipped patterns: core/embed, core-embed/* by default.
	 * Extensible via the `piperless_skip_blocks` filter.
	 *
	 * @param array<int, array> $blocks Parsed blocks from parse_blocks().
	 * @return array<int, array>
	 */
	private function filter_embed_blocks( array $blocks ): array {
		$skip_patterns = apply_filters( 'piperless_skip_blocks', [
			'core/embed',
			'core-embed/',
		] );

		$filtered = [];

		foreach ( $blocks as $block ) {
			$block_name = $block['blockName'] ?? '';

			// Check skip patterns.
			$skip = false;
			foreach ( $skip_patterns as $pattern ) {
				if ( str_starts_with( $block_name, $pattern ) ) {
					$skip = true;
					break;
				}
			}

			if ( $skip ) {
				continue;
			}

			// Recurse into inner blocks (e.g. columns, groups).
			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->filter_embed_blocks( $block['innerBlocks'] );
			}

			$filtered[] = $block;
		}

		return $filtered;
	}

	/**
	 * Store per-post cache key (appends if multiple keys exist).
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $cache_key Cache key.
	 */
	private function store_cache_key( int $post_id, string $cache_key, string $model_basename = '' ): void {
		$existing = get_post_meta( $post_id, '_piperless_cache_key', true );

		if ( empty( $existing ) ) {
			update_post_meta( $post_id, '_piperless_cache_key', [ $cache_key => $model_basename ] );
			return;
		}

		$keys = is_array( $existing ) ? $existing : [ $existing ];

		if ( ! isset( $keys[ $cache_key ] ) ) {
			$keys[ $cache_key ] = $model_basename;
			update_post_meta( $post_id, '_piperless_cache_key', $keys );
		}
	}

	/**
	 * Update post meta after successful generation.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $cache_key Cache key.
	 * @param string $file_path Absolute WAV path.
	 */
	private function update_post_meta( int $post_id, string $cache_key, string $model_basename = '' ): void {
		$url      = $this->cache->proxy_url( $cache_key );
		$duration = $this->wav_duration( $cache_key );

		update_post_meta( $post_id, '_piperless_audio_url', $url );
		update_post_meta( $post_id, '_piperless_duration', $duration );
		update_post_meta( $post_id, '_piperless_generated_at', current_time( 'mysql', true ) );

		if ( '' !== $model_basename ) {
			update_post_meta( $post_id, '_piperless_model_name', $model_basename );
		}
	}

	/**
	 * Parse WAV file header to determine duration in seconds.
	 *
	 * @param string $file_path Absolute path to WAV file.
	 * @return float Duration in seconds.
	 */
	public function wav_duration( string $cache_key ): float {
		$file_path = $this->cache->file_path( $cache_key );
		if ( ! file_exists( $file_path ) ) {
			// Try legacy WAV path.
			$file_path = $this->cache->dir() . '/' . $cache_key . '.wav';
		}
		if ( ! file_exists( $file_path ) ) {
			return 0.0;
		}

		$fp = @fopen( $file_path, 'rb' );
		if ( false === $fp ) {
			return 0.0;
		}

		// Read RIFF header.
		$header = fread( $fp, 44 );
		fclose( $fp );

		if ( strlen( $header ) < 44 ) {
			return 0.0;
		}

		$unpacked = unpack( 'Vsample_rate/Vbyte_rate/vblock_align', substr( $header, 24, 12 ) );

		if ( false === $unpacked ) {
			return 0.0;
		}

		$sample_rate = $unpacked['sample_rate'];
		$byte_rate   = $unpacked['byte_rate'];

		if ( 0 === $byte_rate ) {
			return 0.0;
		}

		$data_size = filesize( $file_path ) - 44;
		if ( $data_size <= 0 ) {
			return 0.0;
		}

		return $data_size / $byte_rate;
	}

	/**
	 * Post types that Piperless supports.
	 *
	 * @return string[]
	 */
	private function supported_post_types(): array {
		$types = apply_filters( 'piperless_post_types', [ 'post', 'page' ] );
		return is_array( $types ) ? $types : [ 'post' ];
	}

	/**
	 * Build an error response.
	 *
	 * @param string $message Error message.
	 * @return array{success:bool,url:null,error:string}
	 */
	private function error( string $message ): array {
		$this->logger->error( $message );
		return [
			'success' => false,
			'url'     => null,
			'error'   => $message,
		];
	}
}
