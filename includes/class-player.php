<?php
/**
 * Frontend audio player rendering.
 *
 * @package Piperless
 */

declare(strict_types=1);

namespace Piperless;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend audio player rendering.
 *
 * Renders a custom HTML5 audio player with play/pause, click-to-seek
 * progress bar, volume control, and time display.  The player markup
 * is pure HTML/CSS; behaviour is driven by assets/js/player.js.
 *
 * ## Placement
 *
 * maybe_prepend_player() hooks into the_content filter (priority 20)
 * and inserts the player based on the placement setting (per-post
 * _piperless_placement meta wins over the global player_placement
 * setting).  Supported values: before, after, both, manual.
 *
 * ## Player style
 *
 * Six themes are available (Classic, Minimal, Modern Dark, NewsViews,
 * NewsViews Classic, Custom CSS).  The theme class is applied as
 * piperless-player--<style> on the container div.  Per-post override
 * via _piperless_style meta.
 *
 * @since 0.1.0
 */
class Player {

	/** @var Logger */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_filter( 'the_content', [ $this, 'maybe_prepend_player' ], 20 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_shortcode( 'piperless_player', [ $this, 'shortcode' ] );
	}

	/**
	 * Insert the player before or after content based on settings.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function maybe_prepend_player( string $content ): string {
		// Only on singular views.
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$settings  = get_option( 'piperless_settings', [] );
		$post_meta = get_post_meta( get_the_ID(), '_piperless_placement', true );
		$placement = ( '' !== $post_meta ) ? $post_meta : ( $settings['player_placement'] ?? 'after' );

		if ( 'manual' === $placement ) {
			return $content;
		}

		$player_html = $this->render( get_the_ID() );

		if ( '' === $player_html ) {
			return $content;
		}

		if ( 'before' === $placement ) {
			return $player_html . $content;
		}

		if ( 'both' === $placement ) {
			return $player_html . $content . $player_html;
		}

		return $content . $player_html;
	}

	/**
	 * Shortcode: [piperless_player post_id="123"]
	 *
	 * @param array<string,mixed> $atts Attributes.
	 * @return string
	 */
	public function shortcode( array $atts ): string {
		$atts = shortcode_atts( [ 'post_id' => 0 ], $atts, 'piperless_player' );

		$post_id = (int) $atts['post_id'];
		if ( 0 === $post_id ) {
			$post_id = get_the_ID();
		}

		return $this->render( $post_id );
	}

	/**
	 * Render the audio player for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string HTML or empty string.
	 */
	public function render( int $post_id ): string {
		$audio_url = get_post_meta( $post_id, '_piperless_audio_url', true );

		if ( empty( $audio_url ) ) {
			return '';
		}

		$settings   = get_option( 'piperless_settings', [] );
		$post_style = get_post_meta( $post_id, '_piperless_style', true );
		$style      = ( '' !== $post_style ) ? $post_style : ( $settings['player_style'] ?? 'classic' );
		$show_meta  = ( $settings['player_show_meta'] ?? '1' ) === '1';
		$duration   = (float) get_post_meta( $post_id, '_piperless_duration', true );

		// Title: per-post override wins, then global default, then empty.
		$title      = get_post_meta( $post_id, '_piperless_title', true );
		if ( '' === $title ) {
			$title = $settings['player_title'] ?? '';
		}

		$style_class = 'piperless-player--' . esc_attr( $style );

		ob_start();
		?>
		<div
			class="piperless-player <?php echo esc_attr( $style_class ); ?>"
			data-style="<?php echo esc_attr( $style ); ?>"
			data-audio-url="<?php echo esc_url( $audio_url ); ?>"
			role="region"
			aria-label="<?php echo esc_attr( sprintf(
				/* translators: %s: post title or player title */
				__( 'Audio transcript: %s', 'piperless' ),
				( '' !== $title ) ? $title : get_the_title( $post_id )
			) ); ?>"
		>
			<?php if ( '' !== $title ) : ?>
			<div class="piperless-player__title"><?php echo esc_html( $title ); ?></div>
			<?php endif; ?>
			<audio class="piperless-player__audio" preload="auto">
				<source src="<?php echo esc_url( $audio_url ); ?>">
				<?php esc_html_e( 'Your browser does not support the audio element.', 'piperless' ); ?>
			</audio>

			<button
				class="piperless-player__play"
				type="button"
				aria-label="<?php esc_attr_e( 'Play', 'piperless' ); ?>"
			>
				<svg class="piperless-player__play-icon" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true">
					<polygon points="6,3 20,12 6,21"/>
				</svg>
				<svg class="piperless-player__pause-icon" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true">
					<rect x="5" y="3" width="5" height="18" rx="1"/>
					<rect x="14" y="3" width="5" height="18" rx="1"/>
				</svg>
			</button>

			<div class="piperless-player__progress-wrapper">
				<div class="piperless-player__progress">
					<div class="piperless-player__progress-buffered"></div>
					<div class="piperless-player__progress-bar"></div>
				</div>
			</div>

			<div class="piperless-player__time">
				<span class="piperless-player__current">00:00</span>
				<?php if ( $show_meta && $duration > 0 ) : ?>
					<span class="piperless-player__separator">/</span>
					<span class="piperless-player__duration"><?php echo esc_html( $this->format_duration( $duration ) ); ?></span>
				<?php endif; ?>
			</div>

			<div class="piperless-player__volume">
				<button class="piperless-player__volume-btn" type="button" aria-label="<?php esc_attr_e( 'Volume', 'piperless' ); ?>">
					<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
						<polygon points="11,5 6,9 2,9 2,15 6,15 11,19"/>
						<path d="M15.54 8.46a5 5 0 0 1 0 7.07" fill="none" stroke="currentColor" stroke-width="2"/>
						<path d="M19.07 4.93a10 10 0 0 1 0 14.14" fill="none" stroke="currentColor" stroke-width="2"/>
					</svg>
				</button>
				<div class="piperless-player__volume-slider">
					<input type="range" min="0" max="100" value="100" step="1" orient="vertical" aria-label="<?php esc_attr_e( 'Volume', 'piperless' ); ?>">
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Enqueue frontend CSS and JS.
	 */
	public function enqueue_assets(): void {
		$settings = get_option( 'piperless_settings', [] );
		$style    = $settings['player_style'] ?? 'classic';

		// Base player styles.
		wp_enqueue_style(
			'piperless-player-base',
			PIPERLESS_PLUGIN_URL . 'assets/css/player-base.css',
			[],
			PIPERLESS_VERSION
		);

		// Selected theme.
		if ( 'custom' !== $style ) {
			$theme_file = 'assets/css/player-' . $style . '.css';
			if ( file_exists( PIPERLESS_PLUGIN_DIR . $theme_file ) ) {
				wp_enqueue_style(
					'piperless-player-' . $style,
					PIPERLESS_PLUGIN_URL . $theme_file,
					[ 'piperless-player-base' ],
					PIPERLESS_VERSION
				);
			}
		}

		// Player max-width (inline).
		$max_width = (int) ( $settings['player_max_width'] ?? 680 );
		if ( $max_width > 0 ) {
			wp_add_inline_style( 'piperless-player-base', '.piperless-player{max-width:' . $max_width . 'px}' );
		} else {
			wp_add_inline_style( 'piperless-player-base', '.piperless-player{max-width:none}' );
		}

		// Custom CSS (inline).
		if ( 'custom' === $style && ! empty( $settings['player_custom_css'] ) ) {
			wp_add_inline_style( 'piperless-player-base', $settings['player_custom_css'] );
		}

		// Player JavaScript.
		wp_enqueue_script(
			'piperless-player',
			PIPERLESS_PLUGIN_URL . 'assets/js/player.js',
			[],
			PIPERLESS_VERSION,
			true
		);
	}

	/**
	 * Format seconds as mm:ss.
	 *
	 * @param float $seconds Duration in seconds.
	 * @return string
	 */
	public function format_duration( float $seconds ): string {
		$minutes = (int) floor( $seconds / 60 );
		$secs    = (int) round( $seconds % 60 );

		return sprintf( '%02d:%02d', $minutes, $secs );
	}
}
