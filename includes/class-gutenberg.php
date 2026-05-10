<?php
/**
 * Gutenberg (block editor) integration.
 *
 * @package Piperless
 */

declare(strict_types=1);

namespace Piperless;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gutenberg (block editor) integration.
 *
 * Registers a PluginSidebar panel in the post editor with three
 * collapsible sections (Generation, Voice Settings, Display Settings)
 * and an audio preview panel.  Per-post overrides for voice, language,
 * quality, player style, placement, title, sentence silence, and
 * length scale are stored as post meta (show_in_rest: true).
 *
 * ## REST API endpoints
 *
 * All endpoints are registered under /piperless/v1/:
 *
 *   - GET  /audio?key=…       — serve cached audio (public, rate-limited)
 *   - POST /generate           — trigger audio generation
 *   - GET  /status/<id>        — check audio status
 *   - GET  /models              — list available voice models
 *   - DELETE /audio/<id>       — remove audio and cached files
 *
 * Generate, status, and remove check per-post ownership
 * (current_user_can('edit_post', $post_id)).  The audio proxy endpoint
 * is intentionally public (required by frontend players) but
 * rate-limited per IP and validates cache keys with a regex.
 *
 * ## Audio proxy
 *
 * stream_file() handles HTTP Range requests (206 Partial Content)
 * so browsers can determine audio duration and seek.  Without this,
 * audio.duration stays NaN and the progress bar, time display, and
 * scrub bar all break.
 *
 * @since 0.1.0
 */
class Gutenberg {

	/** @var Logger */
	private Logger $logger;

	/** @var Transcriber */
	private Transcriber $transcriber;

	/** @var Cache_Manager */
	private Cache_Manager $cache;

	/**
	 * Constructor.
	 *
	 * @param Logger        $logger      Logger.
	 * @param Transcriber   $transcriber Transcriber.
	 * @param Cache_Manager $cache       Cache manager.
	 */
	public function __construct( Logger $logger, Transcriber $transcriber, Cache_Manager $cache ) {
		$this->logger      = $logger;
		$this->transcriber = $transcriber;
		$this->cache       = $cache;
	}

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

		// Register post meta for per-post overrides.
		$post_types = apply_filters( 'piperless_post_types', [ 'post', 'page' ] );

		foreach ( $post_types as $post_type ) {
			register_post_meta( $post_type, '_piperless_voice', [
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'string',
				'auth_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			] );

			register_post_meta( $post_type, '_piperless_language', [
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'string',
				'auth_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			] );

			register_post_meta( $post_type, '_piperless_quality', [
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'string',
				'auth_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			] );

			register_post_meta( $post_type, '_piperless_audio_url', [
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'string',
				'auth_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			] );

			register_post_meta( $post_type, '_piperless_duration', [
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'number',
				'auth_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			] );

			register_post_meta( $post_type, '_piperless_title', [
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'string',
				'auth_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			] );

			register_post_meta( $post_type, '_piperless_sentence_silence', [
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'string',
				'auth_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			] );

			register_post_meta( $post_type, '_piperless_length_scale', [
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'string',
				'auth_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			] );

			register_post_meta( $post_type, '_piperless_placement', [
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'string',
				'auth_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			] );

			register_post_meta( $post_type, '_piperless_style', [
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'string',
				'auth_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			] );
		}
	}

	/**
	 * Enqueue the Gutenberg sidebar script.
	 */
	public function enqueue_editor_assets(): void {
		$screen = get_current_screen();

		if ( ! $screen || ! $screen->is_block_editor() ) {
			return;
		}

		$post_types = apply_filters( 'piperless_post_types', [ 'post', 'page' ] );

		if ( ! in_array( $screen->post_type, $post_types, true ) ) {
			return;
		}

		wp_enqueue_script(
			'piperless-gutenberg',
			PIPERLESS_PLUGIN_URL . 'assets/js/gutenberg.js',
			[
				'wp-plugins',
				'wp-edit-post',
				'wp-components',
				'wp-data',
				'wp-element',
				'wp-i18n',
				'wp-api-fetch',
				'wp-notices',
			],
			PIPERLESS_VERSION,
			true
		);

		// Pass data to the JS.
		$post_id = get_the_ID();
		$status  = $post_id ? $this->transcriber->status( $post_id ) : [
			'has_audio' => false,
			'url'       => null,
			'duration'  => null,
		];

		wp_localize_script( 'piperless-gutenberg', 'piperlessEditor', [
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'postId'         => $post_id,
			'audioStatus'    => $status,
			'defaultVoice'   => '',
			'defaultLang'    => 'en_US',
			'defaultQuality' => 'medium',
			'defaultTitle'   => get_option( 'piperless_settings', [] )['player_title'] ?? __( 'Audio transcript', 'piperless' ),
			'i18n'           => [
				'title'          => __( 'Audio Transcript', 'piperless' ),
				'generate'       => __( 'Generate Audio', 'piperless' ),
				'regenerate'     => __( 'Regenerate Audio', 'piperless' ),
				'generating'     => __( 'Generating…', 'piperless' ),
				'preview'        => __( 'Preview', 'piperless' ),
				'remove'         => __( 'Remove Audio', 'piperless' ),
				'noAudio'        => __( 'No audio generated yet.', 'piperless' ),
				'duration'       => __( 'Duration:', 'piperless' ),
				'success'        => __( 'Audio generated successfully!', 'piperless' ),
				'error'          => __( 'Generation failed. Check the plugin log.', 'piperless' ),
				'voiceLabel'     => __( 'Voice', 'piperless' ),
				'languageLabel'  => __( 'Language', 'piperless' ),
				'qualityLabel'   => __( 'Quality', 'piperless' ),
				'confirmRegen'   => __( 'Regenerate audio? This will overwrite the existing transcript.', 'piperless' ),
				'titleLabel'     => __( 'Player Title', 'piperless' ),
				'titlePlaceholder' => __( 'Audio transcript', 'piperless' ),
			],
		] );

		wp_set_script_translations( 'piperless-gutenberg', 'piperless', PIPERLESS_PLUGIN_DIR . 'languages' );
	}

	/**
	 * Register REST API endpoints.
	 */
	public function register_rest_routes(): void {
		// GET /piperless/v1/audio — serve cached audio through PHP proxy.
		register_rest_route( 'piperless/v1', '/audio', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'rest_serve_audio' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'key' => [
					'required'    => true,
					'type'        => 'string',
					'description' => __( 'Cache key', 'piperless' ),
				],
			],
		] );

		// POST /piperless/v1/generate — trigger audio generation.
		register_rest_route( 'piperless/v1', '/generate', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'rest_generate' ],
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'args'                => [
				'post_id'  => [
					'required'    => true,
					'type'        => 'integer',
					'description' => __( 'Post ID', 'piperless' ),
				],
				'voice'    => [
					'type'        => 'string',
					'description' => __( 'Voice name override', 'piperless' ),
				],
				'language' => [
					'type'        => 'string',
					'description' => __( 'Language code override', 'piperless' ),
				],
				'quality'  => [
					'type'        => 'string',
					'description' => __( 'Quality tier override', 'piperless' ),
				],
			],
		] );

		// GET /piperless/v1/status/<post_id> — check audio status.
		register_rest_route( 'piperless/v1', '/status/(?P<post_id>\d+)', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'rest_status' ],
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'args'                => [
				'post_id' => [
					'required'    => true,
					'type'        => 'integer',
					'description' => __( 'Post ID', 'piperless' ),
				],
			],
		] );

		// GET /piperless/v1/models — list available models.
		register_rest_route( 'piperless/v1', '/models', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'rest_models' ],
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		] );

		// DELETE /piperless/v1/audio/<post_id> — remove audio for a post.
		register_rest_route( 'piperless/v1', '/audio/(?P<post_id>\d+)', [
			'methods'             => \WP_REST_Server::DELETABLE,
			'callback'            => [ $this, 'rest_remove_audio' ],
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'args'                => [
				'post_id' => [
					'required'    => true,
					'type'        => 'integer',
				],
			],
		] );
	}

	/**
	 * REST: trigger audio generation.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_generate( \WP_REST_Request $request ) {
		$post_id  = (int) $request->get_param( 'post_id' );
		$voice    = sanitize_text_field( $request->get_param( 'voice' ) ?? '' );
		$language = sanitize_text_field( $request->get_param( 'language' ) ?? '' );
		$quality  = sanitize_text_field( $request->get_param( 'quality' ) ?? '' );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'piperless_forbidden',
				__( 'You do not have permission to edit this post.', 'piperless' ),
				[ 'status' => 403 ]
			);
		}

		$result = $this->transcriber->generate( $post_id, $voice, $language, $quality );

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'piperless_generation_failed',
				$result['error'],
				[ 'status' => 500 ]
			);
		}

		return rest_ensure_response( [
			'url'      => $result['url'],
			'duration' => get_post_meta( $post_id, '_piperless_duration', true ),
		] );
	}

	/**
	 * REST: get audio status for a post.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_status( \WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'piperless_forbidden',
				__( 'You do not have permission to edit this post.', 'piperless' ),
				[ 'status' => 403 ]
			);
		}

		return rest_ensure_response( $this->transcriber->status( $post_id ) );
	}

	/**
	 * REST: list available models.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_models() {
		$piper  = new Piper( $this->logger );
		$models = $piper->scan_models();

		// Simplify for the frontend.
		$voices    = [];
		$languages = [];
		$qualities = [];

		foreach ( $models as $model ) {
			$voices[ $model['voice'] ]       = true;
			$languages[ $model['language'] ] = true;
			$qualities[ $model['quality'] ]  = true;
		}

		// Strip absolute filesystem paths — only expose the model basename.
		$safe_models = [];
		foreach ( $models as $m ) {
			$safe_models[] = [
				'name'     => $m['name'],
				'voice'    => $m['voice'],
				'language' => $m['language'],
				'quality'  => $m['quality'],
				'path'     => basename( $m['path'] ),
			];
		}

		$settings = get_option( 'piperless_settings', [] );
		$aliases  = $settings['voice_aliases'] ?? [];

		return rest_ensure_response( [
			'models'        => $safe_models,
			'voices'        => array_keys( $voices ),
			'languages'     => array_keys( $languages ),
			'qualities'     => array_keys( $qualities ),
			'voice_aliases' => $aliases,
		] );
	}

	/**
	 * REST: remove audio for a post.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_remove_audio( \WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'piperless_forbidden',
				__( 'You do not have permission to edit this post.', 'piperless' ),
				[ 'status' => 403 ]
			);
		}

		// Delete cached audio files from disk.
		$cache_keys = get_post_meta( $post_id, '_piperless_cache_key', true );
		if ( is_array( $cache_keys ) ) {
			foreach ( $cache_keys as $key => $model ) {
				$this->cache->delete( is_string( $key ) ? $key : $model );
			}
		} elseif ( is_string( $cache_keys ) && '' !== $cache_keys ) {
			$this->cache->delete( $cache_keys );
		}

		delete_post_meta( $post_id, '_piperless_audio_url' );
		delete_post_meta( $post_id, '_piperless_duration' );
		delete_post_meta( $post_id, '_piperless_generated_at' );
		delete_post_meta( $post_id, '_piperless_cache_key' );

		$this->logger->info( 'Audio removed for post {id}', [ 'id' => $post_id ] );

		return rest_ensure_response( [ 'success' => true ] );
	}

	/**
	 * REST: serve cached audio file through PHP proxy.
	 *
	 * Reads the file from the protected cache directory and streams it
	 * with proper Content-Type and Content-Length headers.
	 * Prefers MP3 if cached, falls back to WAV.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_serve_audio( \WP_REST_Request $request ) {
		$cache_key = sanitize_text_field( $request->get_param( 'key' ) );

		// Validate: only alphanumeric + underscores + hyphens in cache keys.
		if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $cache_key ) ) {
			return new \WP_Error( 'invalid_key', __( 'Invalid cache key.', 'piperless' ), [ 'status' => 400 ] );
		}

		// Rate limit: configurable requests per minute per IP.
		$ip          = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
		$rate_key    = 'piperless_rate_audio_' . md5( $ip );
		$rate_count  = (int) get_transient( $rate_key );
		$settings    = get_option( 'piperless_settings', [] );
		$rate_limit  = max( 1, min( 600, (int) ( $settings['audio_rate_limit'] ?? 60 ) ) );

		if ( $rate_count >= $rate_limit ) {
			return new \WP_Error(
				'rate_limited',
				__( 'Too many requests. Please try again later.', 'piperless' ),
				[ 'status' => 429 ]
			);
		}

		set_transient( $rate_key, $rate_count + 1, 60 );

		// Try MP3 first (canonical format).
		$mp3_path = $this->cache->file_path( $cache_key );

		if ( file_exists( $mp3_path ) ) {
			return $this->stream_file( $mp3_path, 'audio/mpeg' );
		}

		// Fall back to legacy WAV.
		$wav_path = $this->cache->dir() . '/' . $cache_key . '.wav';

		if ( ! file_exists( $wav_path ) ) {
			return new \WP_Error( 'not_found', __( 'Audio file not found.', 'piperless' ), [ 'status' => 404 ] );
		}

		return $this->stream_file( $wav_path, 'audio/wav' );
	}

	/**
	 * Stream a file with proper HTTP headers.
	 *
	 * @param string $path         Absolute file path.
	 * @param string $content_type MIME type.
	 * @return \WP_REST_Response
	 */
	private function stream_file( string $path, string $content_type ): \WP_REST_Response {
		$size   = filesize( $path );
		$fp     = fopen( $path, 'rb' );

		if ( false === $fp ) {
			return new \WP_Error( 'read_error', __( 'Could not read audio file.', 'piperless' ), [ 'status' => 500 ] );
		}

		// ── Range request support ───────────────────────────────────
		// Browsers use Range requests to read audio headers (duration
		// detection) and to seek within the file.  Without 206 Partial
		// Content responses, the progress bar, time display, and scrub
		// bar all fail because audio.duration stays NaN.
		$range_header = $_SERVER['HTTP_RANGE'] ?? '';
		$start        = 0;
		$end          = $size - 1;
		$is_range     = false;

		if ( preg_match( '/bytes=(\d*)-(\d*)/', $range_header, $m ) ) {
			$is_range = true;
			$start    = ( '' !== $m[1] ) ? (int) $m[1] : 0;
			$end      = ( '' !== $m[2] ) ? (int) $m[2] : ( $size - 1 );
			$start    = max( 0, min( $start, $size - 1 ) );
			$end      = max( $start, min( $end, $size - 1 ) );
		}

		$length = $end - $start + 1;

		if ( $is_range ) {
			header( 'HTTP/1.1 206 Partial Content' );
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
			header( 'Content-Length: ' . (string) $length );
			fseek( $fp, $start );
		} else {
			header( 'Content-Length: ' . (string) $size );
		}

		header( 'Content-Type: ' . $content_type );
		header( 'Accept-Ranges: bytes' );
		header( 'Cache-Control: public, max-age=86400' );

		// Stream the requested range in chunks.
		$sent = 0;
		while ( $sent < $length && ! feof( $fp ) ) {
			$chunk = fread( $fp, min( 8192, $length - $sent ) );
			if ( false === $chunk ) {
				break;
			}
			echo $chunk;
			$sent += strlen( $chunk );
			if ( ob_get_level() > 0 ) {
				ob_flush();
			}
			flush();
		}

		fclose( $fp );

		do_action( 'shutdown' );
		die();
	}
}
