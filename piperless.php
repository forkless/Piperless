<?php
/**
 * Plugin Name: Piperless — Audio Transcripts
 * Plugin URI:  https://forkless.com
 * Description: Generate audio transcripts of WordPress posts using Piper TTS. Customizable players, caching, and full Gutenberg integration.
 * Version:     1.1.3
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:      Forkless
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: piperless
 * Domain Path: /languages
 *
 * @package Piperless
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Constants ────────────────────────────────────────────────────────────────
define( 'PIPERLESS_VERSION', '1.1.3' );
define( 'PIPERLESS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PIPERLESS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PIPERLESS_PLUGIN_FILE', __FILE__ );
define( 'PIPERLESS_MIN_WP', '6.0' );
define( 'PIPERLESS_MIN_PHP', '8.0' );

// ── Autoloader ───────────────────────────────────────────────────────────────
spl_autoload_register( function ( string $class ): void {
	$prefix = 'Piperless\\';
	$len    = strlen( $prefix );

	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, $len );
	$parts          = explode( '\\', strtolower( $relative_class ) );
	$last           = array_pop( $parts );
	$last           = str_replace( '_', '-', $last );
	$file           = PIPERLESS_PLUGIN_DIR . 'includes/class-' . $last . '.php';

	if ( file_exists( $file ) ) {
		require $file;
	}
} );

// ── Lifecycle ────────────────────────────────────────────────────────────────
require_once PIPERLESS_PLUGIN_DIR . 'includes/class-plugin.php';

/**
 * Bootstrap the plugin.
 */
function piperless_init(): void {
	Piperless\Plugin::instance()->init();
}
add_action( 'plugins_loaded', 'piperless_init' );

/**
 * Load text domain.
 */
function piperless_load_textdomain(): void {
	load_plugin_textdomain(
		'piperless',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}
add_action( 'init', 'piperless_load_textdomain' );

// ── Activation ───────────────────────────────────────────────────────────────
register_activation_hook( __FILE__, function (): void {
	global $wp_version;

	if ( version_compare( PHP_VERSION, PIPERLESS_MIN_PHP, '<' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			sprintf(
				/* translators: %s: minimum PHP version */
				esc_html__( 'Piperless requires PHP %s or higher.', 'piperless' ),
				esc_html( PIPERLESS_MIN_PHP )
			),
			esc_html__( 'Plugin Activation Error', 'piperless' ),
			[ 'back_link' => true ]
		);
	}

	if ( version_compare( $wp_version, PIPERLESS_MIN_WP, '<' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			sprintf(
				/* translators: %s: minimum WordPress version */
				esc_html__( 'Piperless requires WordPress %s or higher.', 'piperless' ),
				esc_html( PIPERLESS_MIN_WP )
			),
			esc_html__( 'Plugin Activation Error', 'piperless' ),
			[ 'back_link' => true ]
		);
	}

	// Create the piperless uploads directory.
	$upload_dir    = wp_upload_dir();
	$piperless_dir = $upload_dir['basedir'] . '/piperless';

	if ( ! file_exists( $piperless_dir ) ) {
		wp_mkdir_p( $piperless_dir );
	}

	// Security: prevent all direct access to cached audio files.
	// Audio is served through the REST API endpoint /piperless/v1/audio.
	$htaccess = $piperless_dir . '/.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		@file_put_contents( $htaccess, "Deny from all\n" );
	}
	if ( ! file_exists( $piperless_dir . '/index.php' ) ) {
		@file_put_contents( $piperless_dir . '/index.php', "<?php // Silence is golden.\n" );
	}

	// Set default options.
	if ( false === get_option( 'piperless_settings' ) ) {
		update_option(
			'piperless_settings',
			[
				'piper_binary'       => '',
				'models_directory'   => '/opt/var/piper/voices',
				'piper_interface'    => 'auto',
				'default_voice'      => '',
				'default_language'   => 'en_US',
				'default_quality'       => 'medium',
				'piper_ffmpeg_binary'    => '',
				'piper_mp3_bitrate'      => '32k',
				'piper_sentence_silence' => '',
				'piper_length_scale'     => '',
				'player_style'       => 'classic',
				'player_custom_css'  => '',
				'player_placement'   => 'after',
				'player_title'       => __( 'Audio transcript', 'piperless' ),
				'auto_generate_on_publish' => '0',
				'skip_embedded_content'    => '0',
				'player_show_meta'   => '1',
				'logging_level'      => 'warning',
				'piper_process_timeout' => '300',
				'audio_rate_limit'      => '60',
			]
		);
	}
} );

// ── Plugins page action links ──────────────────────────────────────────────
add_action( 'admin_init', function (): void {
	$basename = plugin_basename( PIPERLESS_PLUGIN_FILE );
	add_filter( 'plugin_action_links_' . $basename, function ( array $links ): array {
		$settings_url = admin_url( 'options-general.php?page=piperless' );
		$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . __( 'Settings' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	} );
} );

// ── Deactivation ─────────────────────────────────────────────────────────────
register_deactivation_hook( __FILE__, function (): void {
	wp_clear_scheduled_hook( 'piperless_daily_cleanup' );
} );
