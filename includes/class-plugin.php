<?php
/**
 * Main plugin class — singleton orchestrator.
 *
 * @package Piperless
 */

declare(strict_types=1);

namespace Piperless;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class — singleton orchestrator.
 *
 * Instantiates all subsystems (Logger, Piper, Settings, Transcriber,
 * Cache_Manager, Player, Gutenberg) and wires their init() hooks.
 * Held as a singleton via Plugin::instance().
 *
 * ## Auto-generation
 *
 * maybe_auto_generate() fires on transition_post_status.  When a post
 * transitions to 'publish' from a non-publish state AND auto-generate
 * is enabled AND no audio URL exists yet, a one-shot cron event
 * (piperless_auto_generate) is scheduled 5 seconds in the future.
 * This defers the expensive Piper call off the HTTP response path.
 * do_auto_generate() re-checks for existing audio as a safety net.
 *
 * @since 0.1.0
 */
class Plugin {

	/** @var self|null Singleton instance. */
	private static ?self $instance = null;

	/** @var Logger */
	private Logger $logger;

	/** @var Settings */
	private Settings $settings;

	/** @var Piper */
	private Piper $piper;

	/** @var Cache_Manager */
	private Cache_Manager $cache_manager;

	/** @var Transcriber */
	private Transcriber $transcriber;

	/** @var Player */
	private Player $player;

	/** @var Gutenberg */
	private Gutenberg $gutenberg;

	/**
	 * Private constructor — use ::instance().
	 */
	private function __construct() {
		$this->logger        = new Logger();
		$this->cache_manager = new Cache_Manager( $this->logger );
		$this->piper         = new Piper( $this->logger );
		$this->settings      = new Settings( $this->logger, $this->piper, $this->cache_manager );
		$this->transcriber   = new Transcriber( $this->logger, $this->piper, $this->cache_manager );
		$this->player        = new Player( $this->logger );
		$this->gutenberg     = new Gutenberg( $this->logger, $this->transcriber, $this->cache_manager );
	}

	/**
	 * Return the singleton instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Fire all hooks.
	 */
	public function init(): void {
		$this->logger->info( 'Piperless initialising.' );

		$this->settings->init();
		$this->gutenberg->init();
		$this->player->init();

		// Auto-generate on publish.
		add_action( 'transition_post_status', [ $this, 'maybe_auto_generate' ], 10, 3 );
		add_action( 'piperless_auto_generate', [ $this, 'do_auto_generate' ] );

		$this->logger->info( 'Piperless initialised.' );
	}

	// ── Accessors ────────────────────────────────────────────────────────────

	public function logger(): Logger {
		return $this->logger;
	}

	public function settings(): Settings {
		return $this->settings;
	}

	public function piper(): Piper {
		return $this->piper;
	}

	public function cache_manager(): Cache_Manager {
		return $this->cache_manager;
	}

	public function transcriber(): Transcriber {
		return $this->transcriber;
	}

	public function player(): Player {
		return $this->player;
	}

	public function gutenberg(): Gutenberg {
		return $this->gutenberg;
	}

	/**
	 * Auto-generate audio when a post is first published.
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Old post status.
	 * @param \WP_Post $post       Post object.
	 */
	public function maybe_auto_generate( string $new_status, string $old_status, \WP_Post $post ): void {
		$settings = get_option( 'piperless_settings', [] );
		if ( ( $settings['auto_generate_on_publish'] ?? '0' ) !== '1' ) {
			return;
		}

		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}

		if ( ! in_array( $post->post_type, apply_filters( 'piperless_post_types', [ 'post', 'page' ] ), true ) ) {
			return;
		}

		// Skip if the post already has audio — prevents re-generation on
		// posts where audio was manually generated before publishing.
		$existing_url = get_post_meta( $post->ID, '_piperless_audio_url', true );
		if ( ! empty( $existing_url ) ) {
			$this->logger->info( 'Skipping auto-generation for post {id} — audio already exists.', [ 'id' => $post->ID ] );
			return;
		}

		$this->logger->info( 'Scheduling auto-generation for post {id}', [ 'id' => $post->ID ] );

		// Defer to cron so publishing doesn't block the HTTP response.
		// The scheduled event fires on the next cron run (or WP-cron loop).
		if ( ! wp_next_scheduled( 'piperless_auto_generate', [ $post->ID ] ) ) {
			wp_schedule_single_event( time() + 5, 'piperless_auto_generate', [ $post->ID ] );
		}
	}

	/**
	 * Cron callback: execute deferred auto-generation.
	 *
	 * @param int $post_id Post ID.
	 */
	public function do_auto_generate( int $post_id ): void {
		// Safety net: skip if audio was generated between scheduling and now.
		$existing_url = get_post_meta( $post_id, '_piperless_audio_url', true );
		if ( ! empty( $existing_url ) ) {
			$this->logger->info( 'Skipping auto-generation for post {id} — audio already exists.', [ 'id' => $post_id ] );
			return;
		}

		$this->logger->info( 'Auto-generating audio for post {id}', [ 'id' => $post_id ] );
		$this->transcriber->generate( $post_id );
	}
}
