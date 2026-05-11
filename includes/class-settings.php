<?php
/**
 * Admin settings panel.
 *
 * @package Piperless
 */

declare(strict_types=1);

namespace Piperless;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin settings panel.
 *
 * Registers the Settings → Piperless page with seven tabs:
 * Piper, Content, Styling, Performance, Cache Management, Logs,
 * Help, About.  The first four tabs share a single WordPress settings
 * form (same option group); Cache, Logs, Help, and About are
 * standalone.
 *
 * ## Tab architecture
 *
 * Each tab group uses a separate "virtual" page slug passed to
 * add_settings_section() and do_settings_sections().  Fields are
 * registered with add_field() which accepts an optional page slug
 * parameter.  This allows the render_page() method to switch which
 * sections are rendered based on the active tab while keeping a
 * single <form> for the settings tabs.
 *
 * ## Field rendering
 *
 * render_field() dispatches by type: text, select, checkbox,
 * textarea, plus custom types (model_preview_table,
 * player_preview_block, voice_select).  Select fields support a
 * '---' key which renders as a disabled separator option.
 *
 * ## Pre-flight checks
 *
 * check_binary_status() validates the configured Piper binary using
 * only filesystem checks — never invokes the binary.  open_basedir
 * is checked with string-prefix matching before any filesystem call
 * to avoid hangs on blocked paths.
 *
 * @since 0.1.0
 */
class Settings {

	/** @var Logger */
	private Logger $logger;

	/** @var Piper */
	private Piper $piper;

	/** @var Cache_Manager */
	private Cache_Manager $cache;

	/** @var array<int,string> Pre-computed voice list for the dropdown. */
	private array $voice_list_cache = [];

	/** @var string Pre-rendered voice dropdown HTML. */
	private string $voice_dropdown_html = '';

	/** @var string Settings page slug. */
	private string $page_slug = 'piperless';

	/** @var string Option group. */
	private string $option_group = 'piperless_settings_group';

	/** @var string Page slug for Piper tab section group. */
	private string $page_slug_piper   = 'piperless_piper';

	/** @var string Page slug for Content tab section group. */
	private string $page_slug_content = 'piperless_content';

	/** @var string Page slug for Styling tab section group. */
	private string $page_slug_styling = 'piperless_styling';

	/** @var string Page slug for Performance tab section group. */
	private string $page_slug_performance = 'piperless_performance';

	/**
	 * Constructor.
	 *
	 * @param Logger        $logger Logger.
	 * @param Piper         $piper  Piper wrapper.
	 * @param Cache_Manager $cache  Cache manager.
	 */
	public function __construct( Logger $logger, Piper $piper, Cache_Manager $cache ) {
		$this->logger = $logger;
		$this->piper  = $piper;
		$this->cache  = $cache;
	}

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_piperless_test_piper', [ $this, 'ajax_test_piper' ] );
		add_action( 'wp_ajax_piperless_cache_stats', [ $this, 'ajax_cache_stats' ] );
		add_action( 'wp_ajax_piperless_flush_cache', [ $this, 'ajax_flush_cache' ] );
		add_action( 'wp_ajax_piperless_clear_orphans', [ $this, 'ajax_clear_orphans' ] );
		add_action( 'wp_ajax_piperless_clear_log', [ $this, 'ajax_clear_log' ] );
		add_action( 'wp_ajax_piperless_generate_preview', [ $this, 'ajax_generate_preview' ] );
		add_action( 'wp_ajax_piperless_model_preview', [ $this, 'ajax_model_preview' ] );
		add_action( 'wp_ajax_piperless_log_tail', [ $this, 'ajax_log_tail' ] );
		add_action( 'wp_ajax_piperless_cache_browse', [ $this, 'ajax_cache_browse' ] );
		add_action( 'wp_ajax_piperless_cache_delete_entry', [ $this, 'ajax_cache_delete_entry' ] );
	}

	/**
	 * Add the admin menu item.
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'Piperless — Audio Transcripts', 'piperless' ),
			__( 'Piperless', 'piperless' ),
			'manage_options',
			$this->page_slug,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Register all settings, sections, and fields.
	 */
	public function register_settings(): void {
		register_setting(
			$this->option_group,
			'piperless_settings',
			[ 'sanitize_callback' => [ $this, 'sanitize_settings' ] ]
		);

		// ══════════════════════════════════════════════════════════════════
		// Piper Tab — TTS engine configuration
		// ══════════════════════════════════════════════════════════════════
		add_settings_section(
			'piperless_piper_section',
			__( 'Piper TTS Configuration', 'piperless' ),
			[ $this, 'render_piper_section_intro' ],
			$this->page_slug_piper
		);

		$this->add_field( 'piper_binary', __( 'Piper Binary Path', 'piperless' ), 'text', 'piperless_piper_section', [
			'description' => __( 'Absolute path to the Piper executable. Works with the native binary, Python wheel wrappers (e.g. /opt/bin/run-piper), or any script that accepts --model, --length-scale, and either --output-raw or --output_file.', 'piperless' ),
		], $this->page_slug_piper );

		$this->add_field( 'models_directory', __( 'Models Directory', 'piperless' ), 'text', 'piperless_piper_section', [
			'description' => __( 'Absolute path to the directory containing Piper .onnx model files. Scanned recursively. Default: /opt/var/piper/voices.', 'piperless' ),
		], $this->page_slug_piper );

		$this->add_field( 'piper_interface', __( 'Piper Interface Mode', 'piperless' ), 'select', 'piperless_piper_section', [
			'description' => __( 'How the plugin communicates with Piper. Use "Auto-detect" unless you have a custom wrapper script.', 'piperless' ),
			'options'     => [
				'auto'       => __( 'Auto-detect (recommended)', 'piperless' ),
				'standard'   => __( 'Standard — native Piper CLI', 'piperless' ),
				'positional' => __( 'Positional — wrapper with model + text args', 'piperless' ),
			],
		], $this->page_slug_piper );

		// Model previews between interface mode and default voice.
		$this->add_field( 'model_preview_section', __( 'Available models', 'piperless' ), 'model_preview_table', 'piperless_piper_section', [], $this->page_slug_piper );

		$this->add_field( 'default_voice', __( 'Default Voice', 'piperless' ), 'voice_select', 'piperless_piper_section', [
			'description' => __( 'Default voice to use when generating transcripts. Leave at "Use first available" to auto-select.', 'piperless' ),
		], $this->page_slug_piper );

		$this->add_field( 'default_language', __( 'Default Language', 'piperless' ), 'text', 'piperless_piper_section', [
			'description' => __( 'Default language code (e.g. en_US, de_DE).', 'piperless' ),
		], $this->page_slug_piper );

		$this->add_field( 'default_quality', __( 'Default Quality', 'piperless' ), 'select', 'piperless_piper_section', [
			'description' => __( 'Balance between speed and audio quality.', 'piperless' ),
			'options'     => [
				'low'     => __( 'Low (fastest)', 'piperless' ),
				'medium'  => __( 'Medium', 'piperless' ),
				'high'    => __( 'High (best quality)', 'piperless' ),
			],
		], $this->page_slug_piper );

		$this->add_field( 'piper_ffmpeg_binary', __( 'FFmpeg Binary Path', 'piperless' ), 'text', 'piperless_piper_section', [
			'description' => __( 'Absolute path to the ffmpeg binary for MP3/Opus conversion. Auto-detected from common paths if left empty.', 'piperless' ),
		], $this->page_slug_piper );

		$this->add_field( 'piper_ffprobe_binary', __( 'FFprobe Binary Path', 'piperless' ), 'text', 'piperless_piper_section', [
			'description' => __( 'Absolute path to the ffprobe binary for audio duration detection. Auto-detected from the ffmpeg directory if left empty.', 'piperless' ),
		], $this->page_slug_piper );

		$this->add_field( 'piper_audio_format', __( 'Audio Format', 'piperless' ), 'select', 'piperless_piper_section', [
			'description' => __( 'Output audio format. MP3 is universally supported. Opus offers better quality at the same bitrate but has narrower browser support.', 'piperless' ),
			'options'     => [
				'mp3'  => 'MP3',
				'opus' => 'Opus',
			],
		], $this->page_slug_piper );

		$this->add_field( 'piper_mp3_bitrate', __( 'MP3 Bitrate', 'piperless' ), 'select', 'piperless_piper_section', [
			'description' => __( 'Audio quality for MP3 conversion. Lower = smaller files.', 'piperless' ),
			'options'     => [
				'24k' => __( '24 kbps (compact)', 'piperless' ),
				'32k' => __( '32 kbps (standard)', 'piperless' ),
			],
		], $this->page_slug_piper );

		$this->add_field( 'piper_opus_bitrate', __( 'Opus Bitrate', 'piperless' ), 'select', 'piperless_piper_section', [
			'description' => __( 'Bitrate for Opus encoding. Opus achieves good quality at much lower bitrates than MP3. Mono output.', 'piperless' ),
			'options'     => [
				'24k' => __( '24 kbps (standard)', 'piperless' ),
				'16k' => __( '16 kbps (compact)', 'piperless' ),
				'12k' => __( '12 kbps (minimal)', 'piperless' ),
			],
		], $this->page_slug_piper );

		$this->add_field( 'piper_sentence_silence', __( 'Sentence Silence', 'piperless' ), 'text', 'piperless_piper_section', [
			'description' => __( 'Adds silence after each sentence, in seconds (e.g. 0.2, 0.5). Leave empty for Piper default. Applies to Standard mode only.', 'piperless' ),
		], $this->page_slug_piper );

		$this->add_field( 'piper_length_scale', __( 'Length Scale Override', 'piperless' ), 'text', 'piperless_piper_section', [
			'description' => __( 'Overrides the quality-based word/syllable spacing. 1.0 = normal, higher = slower. Leave empty to use quality preset. Applies to Standard mode only.', 'piperless' ),
		], $this->page_slug_piper );

		// ══════════════════════════════════════════════════════════════════
		// Content Tab — text extraction and generation behaviour
		// ══════════════════════════════════════════════════════════════════
		add_settings_section(
			'piperless_content_section',
			__( 'Content Parsing', 'piperless' ),
			null,
			$this->page_slug_content
		);

		$this->add_field( 'auto_generate_on_publish', __( 'Auto-generate on Publish', 'piperless' ), 'checkbox', 'piperless_content_section', [
			'description' => __( 'Automatically generate an audio transcript when a post is published.', 'piperless' ),
		], $this->page_slug_content );

		$this->add_field( 'skip_embedded_content', __( 'Skip Embedded Content', 'piperless' ), 'checkbox', 'piperless_content_section', [
			'description' => __( 'When falling back to post body (no excerpt), skip text from embedded blocks like YouTube, Twitter, and third-party embeds.', 'piperless' ),
		], $this->page_slug_content );

		// ══════════════════════════════════════════════════════════════════
		// Logs Tab — logging configuration
		// ══════════════════════════════════════════════════════════════════
		add_settings_section(
			'piperless_logs_section',
			'',
			null,
			$this->page_slug
		);

		$this->add_field( 'logging_level', __( 'Logging Level', 'piperless' ), 'select', 'piperless_logs_section', [
			'description' => __( 'Minimum severity to record in the log.', 'piperless' ),
			'options'     => [
				'debug'     => __( 'Debug (verbose)', 'piperless' ),
				'info'      => __( 'Info', 'piperless' ),
				'warning'   => __( 'Warning', 'piperless' ),
				'error'     => __( 'Error only', 'piperless' ),
			],
		] );

		// ══════════════════════════════════════════════════════════════════
		// Styling Tab — audio player appearance and placement
		// ══════════════════════════════════════════════════════════════════
		add_settings_section(
			'piperless_player_section',
			__( 'Audio Player Settings', 'piperless' ),
			null,
			$this->page_slug_styling
		);

		// Player preview rendered inline after the style dropdown.
		$this->add_field( 'player_preview_section', __( 'Player Preview', 'piperless' ), 'player_preview_block', 'piperless_player_section', [], $this->page_slug_styling );

		$this->add_field( 'player_style', __( 'Player Style', 'piperless' ), 'select', 'piperless_player_section', [
			'description' => __( 'Choose a visual theme for the audio player.', 'piperless' ),
			'options'     => [
				'classic'            => __( 'Classic', 'piperless' ),
				'minimal'            => __( 'Minimal', 'piperless' ),
				'dark'               => __( 'Modern Dark', 'piperless' ),
				'newsviews'          => __( 'Ron Burgundy', 'piperless' ),
				'newsviews-classic'  => __( 'Dan Rather Blue', 'piperless' ),
				'---'                => '──────────',
				'custom'             => __( 'Custom CSS', 'piperless' ),
			],
		], $this->page_slug_styling );

		$this->add_field( 'player_custom_css', __( 'Custom CSS', 'piperless' ), 'textarea', 'piperless_player_section', [
			'description' => __( 'Custom CSS rules applied when "Custom CSS" style is selected.', 'piperless' ),
		], $this->page_slug_styling );

		$this->add_field( 'player_placement', __( 'Player Placement', 'piperless' ), 'select', 'piperless_player_section', [
			'description' => __( 'Where to insert the audio player relative to the post content.', 'piperless' ),
			'options'     => [
				'before' => __( 'Before content', 'piperless' ),
				'after'  => __( 'After content', 'piperless' ),
				'both'   => __( 'Above & below content', 'piperless' ),
				'---'    => '──────────',
				'manual' => __( 'Manual (shortcode only)', 'piperless' ),
			],
		], $this->page_slug_styling );

		$this->add_field( 'player_max_width', __( 'Player Max Width', 'piperless' ), 'text', 'piperless_player_section', [
			'description' => __( 'Maximum width of the player in pixels. Leave empty or set to 0 for 100% width. Default: 680.', 'piperless' ),
		], $this->page_slug_styling );

		$this->add_field( 'player_title', __( 'Player Title', 'piperless' ), 'text', 'piperless_player_section', [
			'description' => __( 'Title displayed above the audio player. Leave empty to hide. Default: Audio transcript.', 'piperless' ),
		], $this->page_slug_styling );

		$this->add_field( 'player_show_meta', __( 'Show Duration', 'piperless' ), 'checkbox', 'piperless_player_section', [
			'description' => __( 'Display the total listening time on the player.', 'piperless' ),
		], $this->page_slug_styling );

		// ══════════════════════════════════════════════════════════════════
		// Performance Tab — timeouts and rate limits
		// ══════════════════════════════════════════════════════════════════
		add_settings_section(
			'piperless_performance_section',
			'',
			null,
			$this->page_slug_performance
		);

		$this->add_field( 'piper_process_timeout', __( 'Piper Process Timeout', 'piperless' ), 'text', 'piperless_performance_section', [
			'description' => __( 'Maximum seconds a single Piper synthesis may run before the PHP worker is terminated. Increase for very long posts or slow hardware. Default: 300.', 'piperless' ),
		], $this->page_slug_performance );

		$this->add_field( 'audio_rate_limit', __( 'Audio Endpoint Rate Limit', 'piperless' ), 'text', 'piperless_performance_section', [
			'description' => __( 'Maximum requests per minute per visitor IP to the audio streaming endpoint. Prevents abuse of the public proxy. Default: 60.', 'piperless' ),
		], $this->page_slug_performance );
	}

	/**
	 * Add a settings field shorthand.
	 *
	 * @param string                $id       Field ID.
	 * @param string                $label    Label.
	 * @param string                $type     text|select|checkbox|textarea.
	 * @param string                $section  Section ID.
	 * @param array<string,mixed>   $args     Extra args.
	 */
	private function add_field( string $id, string $label, string $type, string $section, array $args = [], ?string $page = null ): void {
		$args['field_id'] = $id;
		$args['type']     = $type;
		add_settings_field(
			$id,
			$label,
			[ $this, 'render_field' ],
			$page ?? $this->page_slug,
			$section,
			$args
		);
	}

	/**
	 * Render the Piper section intro with test button.
	 */
	public function render_piper_section_intro(): void {
		echo '<p>' . esc_html__( 'Configure the Piper TTS engine.', 'piperless' ) . '</p>';
		echo '<button type="button" id="piperless-test-piper" class="button button-secondary">'
			. esc_html__( 'Test Connection', 'piperless' ) . '</button>';
		echo '<span id="piperless-test-result" style="margin-left:12px;"></span>';
	}

	/**
	 * Render a settings field.
	 *
	 * @param array<string,mixed> $args Field configuration.
	 */
	public function render_field( array $args ): void {
		$settings = get_option( 'piperless_settings', [] );
		$id       = $args['field_id'] ?? '';
		$type     = $args['type'] ?? 'text';
		$value    = $settings[ $id ] ?? '';
		$name     = 'piperless_settings[' . esc_attr( $id ) . ']';

		switch ( $type ) {
			case 'select':
				$options = $args['options'] ?? [];
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="regular-text">';
				foreach ( $options as $opt_val => $opt_label ) {
					if ( '---' === $opt_val ) {
						echo '<option disabled>', esc_html( $opt_label ), '</option>';
					} else {
						printf(
							'<option value="%s" %s>%s</option>',
							esc_attr( $opt_val ),
							selected( $value, $opt_val, false ),
							esc_html( $opt_label )
						);
					}
				}
				echo '</select>';
				break;

			case 'model_preview_table':
				$this->render_model_preview_table();
				break;

			case 'player_preview_block':
				$this->render_player_preview_block();
				break;

			case 'voice_select':
				// Emit pre-rendered HTML (computed in render_page context).
				echo $this->voice_dropdown_html;
				break;

			case 'checkbox':
				printf(
					'<label><input type="checkbox" id="%s" name="%s" value="1" %s> %s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( $value, '1', false ),
					esc_html( $args['description'] ?? '' )
				);
				$args['description'] = ''; // Already in label.
				break;

			case 'textarea':
				$display_value = (string) $value;
				// Pre-populate custom CSS field with classic theme as template.
				if ( 'player_custom_css' === $id && '' === $display_value ) {
					$classic_css = @file_get_contents( PIPERLESS_PLUGIN_DIR . 'assets/css/player-classic.css' );
					if ( false !== $classic_css ) {
						$display_value = $classic_css;
					}
				}
				printf(
					'<textarea id="%s" name="%s" rows="10" class="large-text code">%s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_textarea( $display_value )
				);
				break;

			default: // text
				printf(
					'<input type="text" id="%s" name="%s" value="%s" class="regular-text">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				break;
		}

		if ( ! empty( $args['description'] ) && 'checkbox' !== $type ) {
			printf(
				'<p class="description">%s</p>',
				esc_html( $args['description'] )
			);
		}
	}

	/**
	 * Sanitize settings before save.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @return array<string,mixed>
	 */
	public function sanitize_settings( array $input ): array {
		// Merge with existing settings so standalone forms (e.g., Logs tab)
		// don't wipe unrelated fields.
		$existing = get_option( 'piperless_settings', [] );
		$input    = array_merge( $existing, $input );
		$clean    = [];

		$clean['piper_binary']       = sanitize_text_field( $input['piper_binary'] ?? '' );
		$clean['models_directory']   = sanitize_text_field( $input['models_directory'] ?? '' );
		$clean['piper_interface']    = sanitize_text_field( $input['piper_interface'] ?? 'auto' );
		$clean['default_voice']      = sanitize_text_field( $input['default_voice'] ?? '' );
		$clean['default_language']   = sanitize_text_field( $input['default_language'] ?? 'en_US' );
		$clean['default_quality']       = sanitize_text_field( $input['default_quality'] ?? 'medium' );
		$clean['piper_ffmpeg_binary']    = sanitize_text_field( $input['piper_ffmpeg_binary'] ?? '' );
		$clean['piper_ffprobe_binary']   = sanitize_text_field( $input['piper_ffprobe_binary'] ?? '' );
		$clean['piper_mp3_bitrate']      = sanitize_text_field( $input['piper_mp3_bitrate'] ?? '32k' );
		$clean['piper_audio_format']     = in_array( $input['piper_audio_format'] ?? 'mp3', [ 'mp3', 'opus' ], true )
			? $input['piper_audio_format'] : 'mp3';
		$clean['piper_opus_bitrate']     = in_array( $input['piper_opus_bitrate'] ?? '24k', [ '24k', '16k', '12k' ], true )
			? $input['piper_opus_bitrate'] : '24k';
		$clean['piper_sentence_silence'] = sanitize_text_field( $input['piper_sentence_silence'] ?? '' );
		$clean['piper_length_scale']     = sanitize_text_field( $input['piper_length_scale'] ?? '' );
		$clean['player_style']           = sanitize_text_field( $input['player_style'] ?? 'classic' );
		$clean['player_custom_css']  = $this->sanitize_css( $input['player_custom_css'] ?? '' );
		$clean['player_placement']   = sanitize_text_field( $input['player_placement'] ?? 'after' );
		$clean['player_max_width'] = max( 0, (int) ( $input['player_max_width'] ?? 680 ) );
		$clean['player_title']       = sanitize_text_field( $input['player_title'] ?? '' );
		$clean['auto_generate_on_publish'] = ! empty( $input['auto_generate_on_publish'] ) ? '1' : '0';
		$clean['skip_embedded_content']   = ! empty( $input['skip_embedded_content'] ) ? '1' : '0';
		$clean['player_show_meta']        = ! empty( $input['player_show_meta'] ) ? '1' : '0';
		$clean['logging_level']           = sanitize_text_field( $input['logging_level'] ?? 'warning' );
		$clean['piper_process_timeout']   = max( 30, min( 3600, (int) ( $input['piper_process_timeout'] ?? 300 ) ) );
		$clean['audio_rate_limit']        = max( 1, min( 600, (int) ( $input['audio_rate_limit'] ?? 60 ) ) );

		// Voice aliases: sanitize each alias string.
		$clean['voice_aliases'] = [];
		if ( ! empty( $input['voice_aliases'] ) && is_array( $input['voice_aliases'] ) ) {
			foreach ( $input['voice_aliases'] as $voice_name => $alias ) {
				$clean['voice_aliases'][ sanitize_text_field( (string) $voice_name ) ] = sanitize_text_field( (string) $alias );
			}
		}

		return $clean;
	}

	/**
	 * Render the full settings page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->logger->info( 'Piperless admin page loaded.' );

		$this->voice_list_cache    = $this->get_voice_list();
		$this->voice_dropdown_html = $this->render_voice_dropdown_html();

		// Pre-flight: check Piper binary without invoking it.
		$binary_status = $this->check_binary_status();

		$active_tab = sanitize_text_field( $_GET['tab'] ?? 'piper' );
		$is_settings_tab = in_array( $active_tab, [ 'piper', 'content', 'styling', 'performance' ], true );
		?>
		<div class="wrap piperless-admin">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php if ( 'ok' !== $binary_status ) : ?>
				<div class="notice notice-warning is-dismissible">
					<p>
						<?php if ( 'not_configured' === $binary_status ) : ?>
							<?php esc_html_e( 'Piperless: Piper binary path is not configured. Text-to-speech will not work until you set the path in the Piper tab.', 'piperless' ); ?>
						<?php elseif ( 'not_found' === $binary_status ) : ?>
							<?php esc_html_e( 'Piperless: Piper binary not found at the configured path. Text-to-speech will not work until the path is corrected.', 'piperless' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Piperless: Piper binary is not executable. Check file permissions.', 'piperless' ); ?>
						<?php endif; ?>
					</p>
				</div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper">
				<a href="?page=<?php echo esc_attr( $this->page_slug ); ?>&tab=piper"
				   class="nav-tab <?php echo 'piper' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Piper', 'piperless' ); ?>
				</a>
				<a href="?page=<?php echo esc_attr( $this->page_slug ); ?>&tab=content"
				   class="nav-tab <?php echo 'content' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Content', 'piperless' ); ?>
				</a>
				<a href="?page=<?php echo esc_attr( $this->page_slug ); ?>&tab=styling"
				   class="nav-tab <?php echo 'styling' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Styling', 'piperless' ); ?>
				</a>
				<a href="?page=<?php echo esc_attr( $this->page_slug ); ?>&tab=performance"
				   class="nav-tab <?php echo 'performance' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Performance', 'piperless' ); ?>
				</a>
				<a href="?page=<?php echo esc_attr( $this->page_slug ); ?>&tab=cache"
				   class="nav-tab <?php echo 'cache' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Cache Management', 'piperless' ); ?>
				</a>
				<a href="?page=<?php echo esc_attr( $this->page_slug ); ?>&tab=logs"
				   class="nav-tab <?php echo 'logs' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Logs', 'piperless' ); ?>
				</a>
				<a href="?page=<?php echo esc_attr( $this->page_slug ); ?>&tab=help"
				   class="nav-tab <?php echo 'help' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Help', 'piperless' ); ?>
				</a>
				<a href="?page=<?php echo esc_attr( $this->page_slug ); ?>&tab=about"
				   class="nav-tab <?php echo 'about' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'About', 'piperless' ); ?>
				</a>
			</nav>

			<?php if ( $is_settings_tab ) : ?>
				<form method="post" action="options.php">
					<?php settings_fields( $this->option_group ); ?>

					<div class="piperless-tab-content" id="piperless-tab-piper" style="<?php echo 'piper' !== $active_tab ? 'display:none;' : ''; ?>">
						<?php do_settings_sections( $this->page_slug_piper ); ?>
					</div>

					<div class="piperless-tab-content" id="piperless-tab-content" style="<?php echo 'content' !== $active_tab ? 'display:none;' : ''; ?>">
						<?php do_settings_sections( $this->page_slug_content ); ?>
					</div>

					<div class="piperless-tab-content" id="piperless-tab-styling" style="<?php echo 'styling' !== $active_tab ? 'display:none;' : ''; ?>">
						<?php do_settings_sections( $this->page_slug_styling ); ?>
					</div>

					<div class="piperless-tab-content" id="piperless-tab-performance" style="<?php echo 'performance' !== $active_tab ? 'display:none;' : ''; ?>">
						<?php do_settings_sections( $this->page_slug_performance ); ?>
					</div>

					<?php submit_button(); ?>
				</form>
			<?php endif; ?>

			<div class="piperless-tab-content" id="piperless-tab-cache" style="<?php echo 'cache' !== $active_tab ? 'display:none;' : ''; ?>">
				<h2><?php esc_html_e( 'Cache Management', 'piperless' ); ?></h2>
				<div id="piperless-cache-stats"><p><?php esc_html_e( 'Loading…', 'piperless' ); ?></p></div>
				<p>
					<button type="button" id="piperless-clear-orphans" class="button"><?php esc_html_e( 'Clear Orphaned Audio', 'piperless' ); ?></button>
					<button type="button" id="piperless-flush-cache" class="button button-caution" style="margin-left:8px;"><?php esc_html_e( 'Flush Entire Cache', 'piperless' ); ?></button>
					<span id="piperless-cache-result" style="margin-left:12px;"></span>
				</p>

				<h3><?php esc_html_e( 'Cache Browser', 'piperless' ); ?></h3>
				<p>
					<button type="button" id="piperless-delete-selected" class="button" style="color:#b32d2e;"><?php esc_html_e( 'Delete Selected', 'piperless' ); ?></button>
				</p>
				<div id="piperless-cache-browser">
					<p><?php esc_html_e( 'Loading…', 'piperless' ); ?></p>
				</div>
			</div>

			<div class="piperless-tab-content" id="piperless-tab-help" style="<?php echo 'help' !== $active_tab ? 'display:none;' : ''; ?>">
				<h2><?php esc_html_e( 'Help', 'piperless' ); ?></h2>
				<div class="piperless-help-card">
					<p><?php esc_html_e( 'Piperless will try the post excerpt field first for transcription. If no excerpt is available, it will fall back on the content in the post.', 'piperless' ); ?></p>
					<p><?php esc_html_e( 'The plugin will try to convert the WAV file output from Piper to MP3 format if it can find ffmpeg on the system. The path to ffmpeg can be configured in the Piper tab and must be accessible by PHP — an open_basedir exception may be needed, or the ffmpeg binary should be placed within your web environment.', 'piperless' ); ?></p>
				</div>
			</div>

			<div class="piperless-tab-content" id="piperless-tab-about" style="<?php echo 'about' !== $active_tab ? 'display:none;' : ''; ?>">
				<div class="piperless-about-card">
					<div class="piperless-about-icon">&#9835;</div>
					<h2><?php esc_html_e( 'Piperless', 'piperless' ); ?></h2>
					<p class="piperless-about-version"><?php echo esc_html( 'v' . PIPERLESS_VERSION ); ?></p>
					<p class="piperless-about-blurb">
						<?php esc_html_e( 'Piperless turns your WordPress posts into audio transcripts. Developed by Forkless, it makes your content accessible, engaging, and easy to listen to. Quick setup, high-quality speech, and seamless integration.', 'piperless' ); ?>
					</p>
					<p class="piperless-about-contact">
						<?php esc_html_e( 'Questions? Contact the developers at', 'piperless' ); ?>
						<a href="mailto:devs@forkless.com">devs@forkless.com</a>
					</p>
				</div>
			</div>

			<div class="piperless-tab-content" id="piperless-tab-logs" style="<?php echo 'logs' !== $active_tab ? 'display:none;' : ''; ?>">
				<form method="post" action="options.php">
					<?php
					settings_fields( $this->option_group );
					do_settings_sections( $this->page_slug );
					submit_button();
					?>
				</form>

				<h2><?php esc_html_e( 'Debug Log', 'piperless' ); ?></h2>
				<pre id="piperless-log-viewer"><?php
					$log_lines = $this->logger->tail( 50 );
					echo [] === $log_lines
						? esc_html__( '(Log empty.)', 'piperless' )
						: esc_html( implode( "\n", $log_lines ) );
				?></pre>
				<p>
					<button type="button" id="piperless-refresh-log" class="button"><?php esc_html_e( 'Refresh Log', 'piperless' ); ?></button>
					<button type="button" id="piperless-clear-log" class="button" style="margin-left:8px;"><?php esc_html_e( 'Clear Log', 'piperless' ); ?></button>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue admin CSS and JS.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'settings_page_' . $this->page_slug !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'piperless-admin',
			PIPERLESS_PLUGIN_URL . 'assets/css/admin.css',
			[],
			PIPERLESS_VERSION
		);

		wp_enqueue_style(
			'piperless-player-base',
			PIPERLESS_PLUGIN_URL . 'assets/css/player-base.css',
			[],
			PIPERLESS_VERSION
		);

		wp_enqueue_style(
			'piperless-player-classic',
			PIPERLESS_PLUGIN_URL . 'assets/css/player-classic.css',
			[ 'piperless-player-base' ],
			PIPERLESS_VERSION
		);

		wp_enqueue_style(
			'piperless-player-dark',
			PIPERLESS_PLUGIN_URL . 'assets/css/player-dark.css',
			[ 'piperless-player-base' ],
			PIPERLESS_VERSION
		);

		wp_enqueue_style(
			'piperless-player-newsviews',
			PIPERLESS_PLUGIN_URL . 'assets/css/player-newsviews.css',
			[ 'piperless-player-base' ],
			PIPERLESS_VERSION
		);

		wp_enqueue_style(
			'piperless-player-newsviews-classic',
			PIPERLESS_PLUGIN_URL . 'assets/css/player-newsviews-classic.css',
			[ 'piperless-player-base' ],
			PIPERLESS_VERSION
		);

		wp_enqueue_style(
			'piperless-player-minimal',
			PIPERLESS_PLUGIN_URL . 'assets/css/player-minimal.css',
			[ 'piperless-player-base' ],
			PIPERLESS_VERSION
		);

		// Player max-width inline style for the admin preview.
		$settings  = get_option( 'piperless_settings', [] );
		$max_width = (int) ( $settings['player_max_width'] ?? 680 );
		if ( $max_width > 0 ) {
			wp_add_inline_style( 'piperless-player-base', '.piperless-player{max-width:' . $max_width . 'px}' );
		} else {
			wp_add_inline_style( 'piperless-player-base', '.piperless-player{max-width:none}' );
		}

		wp_enqueue_script(
			'piperless-admin',
			PIPERLESS_PLUGIN_URL . 'assets/js/admin.js',
			[],
			PIPERLESS_VERSION,
			true
		);

		// Pass initial data to admin JS.
		$stats = $this->cache->stats();

		wp_localize_script( 'piperless-admin', 'piperlessAdmin', [
			'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
			'nonce'            => wp_create_nonce( 'piperless_admin' ),
			'currentStyle'     => get_option( 'piperless_settings', [] )['player_style'] ?? 'classic',
			'cacheStats'       => $stats,
			'previewAudioUrl'  => $this->get_preview_audio_url(),
			'models'           => $this->piper->scan_models(),
			'i18n'             => [
				'testing'        => __( 'Testing…', 'piperless' ),
				'testOk'         => __( 'Piper is working correctly.', 'piperless' ),
				'testFail'       => __( 'Piper test failed.', 'piperless' ),
				'clearConfirm'    => __( 'Are you sure? This will delete all cached audio files.', 'piperless' ),
				'orphansConfirm'  => __( 'This will delete cache files not referenced by any post.', 'piperless' ),
				'flushed'        => __( 'Cache flushed.', 'piperless' ),
				'orphansCleared' => __( 'Orphaned files cleared.', 'piperless' ),
				'logCleared'     => __( 'Log cleared.', 'piperless' ),
				'previewModel'   => __( 'Preview', 'piperless' ),
				'generatingPreview' => __( 'Generating…', 'piperless' ),
				'previewFailed'  => __( 'Failed to generate preview.', 'piperless' ),
			],
		] );
	}

	// ── AJAX Handlers ────────────────────────────────────────────────────────

	/**
	 * AJAX: test Piper connection.
	 */
	public function ajax_test_piper(): void {
		check_ajax_referer( 'piperless_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'piperless' ) ], 403 );
		}

		$result = $this->piper->test();

		if ( $result['ok'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * AJAX: get cache stats.
	 */
	public function ajax_cache_stats(): void {
		check_ajax_referer( 'piperless_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}

		wp_send_json_success( $this->cache->stats() );
	}

	/**
	 * AJAX: flush cache.
	 */
	public function ajax_flush_cache(): void {
		check_ajax_referer( 'piperless_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}

		$count = $this->cache->flush();
		wp_send_json_success( [ 'deleted' => $count ] );
	}

	/**
	 * AJAX: clear orphans.
	 */
	public function ajax_clear_orphans(): void {
		check_ajax_referer( 'piperless_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}

		$count = $this->cache->clear_orphans();
		wp_send_json_success( [ 'deleted' => $count ] );
	}

	/**
	 * AJAX: clear log.
	 */
	public function ajax_clear_log(): void {
		check_ajax_referer( 'piperless_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}

		$this->logger->clear();
		wp_send_json_success();
	}

	/**
	 * AJAX: generate a test preview WAV.
	 */
	public function ajax_generate_preview(): void {
		check_ajax_referer( 'piperless_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}

		$style = sanitize_text_field( $_POST['style'] ?? 'classic' );
		// Return the preview URL and applied style.
		wp_send_json_success( [
			'style' => $style,
			'url'   => $this->get_preview_audio_url(),
		] );
	}

	/**
	 * AJAX: return last N lines of the debug log.
	 */
	public function ajax_log_tail(): void {
		check_ajax_referer( 'piperless_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}

		wp_send_json_success( [
			'lines' => $this->logger->tail( 100 ),
			'path'  => $this->logger->log_file_path(),
		] );
	}

	/**
	 * AJAX: browse cache entries with pagination.
	 */
	public function ajax_cache_browse(): void {
		check_ajax_referer( 'piperless_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}

		$page     = max( 1, (int) ( $_POST['page'] ?? 1 ) );
		$per_page = max( 5, min( 50, (int) ( $_POST['per_page'] ?? 20 ) ) );
		$sort_by  = in_array( $_POST['sort_by'] ?? 'created', [ 'created', 'size' ], true ) ? $_POST['sort_by'] : 'created';
		$sort_asc = ( 'asc' === ( $_POST['sort_order'] ?? 'desc' ) );

		wp_send_json_success( $this->cache->get_entries( $page, $per_page, $sort_by, $sort_asc ) );
	}

	/**
	 * AJAX: delete a specific cache entry.
	 */
	public function ajax_cache_delete_entry(): void {
		check_ajax_referer( 'piperless_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}

		$key = sanitize_text_field( $_POST['key'] ?? '' );

		if ( '' === $key || ! preg_match( '/^[a-zA-Z0-9_-]+$/', $key ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid cache key.', 'piperless' ) ] );
		}

		$deleted = $this->cache->delete_entry( $key );

		// Clear post meta if this entry was linked to a post.
		if ( $deleted ) {
			global $wpdb;
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_piperless_cache_key'
			) );

			foreach ( $rows as $row ) {
				$keys = maybe_unserialize( $row->meta_value );
				$owns = false;
				if ( is_array( $keys ) ) {
					$owns = array_key_exists( $key, $keys );
				} elseif ( is_string( $keys ) ) {
					$owns = ( $keys === $key );
				}

				if ( $owns ) {
					delete_post_meta( (int) $row->post_id, '_piperless_audio_url' );
					delete_post_meta( (int) $row->post_id, '_piperless_cache_key' );
					delete_post_meta( (int) $row->post_id, '_piperless_duration' );
					delete_post_meta( (int) $row->post_id, '_piperless_model_name' );
					delete_post_meta( (int) $row->post_id, '_piperless_generated_at' );
					delete_post_meta( (int) $row->post_id, '_piperless_audio_format' );
					break;
				}
			}
		}

		wp_send_json_success( [ 'deleted' => $deleted ] );
	}

	/**
	 * AJAX: generate (or retrieve) a preview clip for a specific model.
	 */
	public function ajax_model_preview(): void {
		check_ajax_referer( 'piperless_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'piperless' ) ], 403 );
		}

		$model_path = sanitize_text_field( $_POST['model_path'] ?? '' );
		$quality    = sanitize_text_field( $_POST['quality'] ?? 'medium' );

		if ( '' === $model_path || ! file_exists( $model_path ) ) {
			wp_send_json_error( [ 'message' => __( 'Model file not found.', 'piperless' ) ] );
		}

		$url = $this->get_model_preview_url( $model_path, $quality );

		if ( '' === $url ) {
			wp_send_json_error( [ 'message' => __( 'Failed to generate model preview.', 'piperless' ) ] );
		}

		wp_send_json_success( [ 'url' => $url ] );
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Sanitize custom CSS: strip HTML tags and block dangerous CSS
	 * constructs (url(), expression(), @import, behavior).
	 *
	 * @param string $css Raw CSS input.
	 * @return string
	 */
	private function sanitize_css( string $css ): string {
		// Remove HTML tags first.
		$css = wp_strip_all_tags( $css );

		// Remove lines containing dangerous URL references.
		$lines = explode( "\n", $css );
		$kept  = [];

		foreach ( $lines as $line ) {
			$lower = strtolower( $line );

			if (
				str_contains( $lower, 'url(' )
				|| str_contains( $lower, 'expression(' )
				|| str_contains( $lower, '@import' )
				|| str_contains( $lower, 'behavior:' )
				|| str_contains( $lower, '-moz-binding' )
			) {
				continue;
			}

			$kept[] = $line;
		}

		return implode( "\n", $kept );
	}

	/**
	 * Pre-flight check of the configured Piper binary.
	 *
	 * Performs only filesystem checks (file_exists, is_executable) —
	 * never invokes the binary.  Safe to call on every page load.
	 *
	 * @return string 'ok', 'not_configured', 'not_found', or 'not_executable'.
	 */
	private function check_binary_status(): string {
		$settings = get_option( 'piperless_settings', [] );
		$binary   = $settings['piper_binary'] ?? '';

		if ( '' === $binary ) {
			return 'not_configured';
		}

		// If open_basedir is in effect and the binary path falls outside
		// the allowed directories, don't even touch the filesystem.
		// file_exists() on blocked paths can hang on stale NFS mounts
		// or slow network filesystems.
		$open_basedir = ini_get( 'open_basedir' );
		if ( '' !== $open_basedir ) {
			$allowed = array_map( 'trim', explode( PATH_SEPARATOR, $open_basedir ) );
			$inside  = false;

			foreach ( $allowed as $dir ) {
				$dir = rtrim( $dir, '/' );
				if ( str_starts_with( $binary, $dir . '/' ) || $binary === $dir ) {
					$inside = true;
					break;
				}
			}

			if ( ! $inside ) {
				return 'not_found';
			}
		}

		if ( ! @file_exists( $binary ) ) {
			return 'not_found';
		}

		if ( ! @is_executable( $binary ) ) {
			return 'not_executable';
		}

		return 'ok';
	}

	/**
	 * Render the voice model preview table inline.
	 */
	private function render_model_preview_table(): void {
		$models   = $this->piper->scan_models();
		$settings = get_option( 'piperless_settings', [] );
		$aliases  = $settings['voice_aliases'] ?? [];

		if ( empty( $models ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No voice models found.', 'piperless' ) . '</p></div>';
			return;
		}

		?>
		<div id="piperless-model-list">
			<table class="wp-list-table widefat fixed striped piperless-model-table">
				<thead><tr>
					<th class="piperless-col-alias"><?php esc_html_e( 'Alias', 'piperless' ); ?></th>
					<th class="piperless-col-voice piperless-sortable" data-sort="voice"><?php esc_html_e( 'Voice', 'piperless' ); ?> <span class="piperless-sort-arrow"></span></th>
					<th class="piperless-col-lang"><?php esc_html_e( 'Language', 'piperless' ); ?></th>
					<th class="piperless-col-quality piperless-sortable" data-sort="quality"><?php esc_html_e( 'Quality', 'piperless' ); ?> <span class="piperless-sort-arrow"></span></th>
					<th class="piperless-col-preview"><?php esc_html_e( 'Preview', 'piperless' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $models as $model ) :
					$voice_name = $model['voice'];
					$alias_val  = $aliases[ $voice_name ] ?? '';
				?>
					<tr data-voice="<?php echo esc_attr( $voice_name ); ?>" data-quality="<?php echo esc_attr( $model['quality'] ); ?>">
						<td><input type="text" name="piperless_settings[voice_aliases][<?php echo esc_attr( $voice_name ); ?>]" value="<?php echo esc_attr( $alias_val ); ?>" class="regular-text piperless-alias-input" placeholder="<?php echo esc_attr( $voice_name ); ?>"></td>
						<td><?php echo esc_html( $voice_name ); ?></td>
						<td><?php echo esc_html( $model['language'] ); ?></td>
						<td><span class="piperless-quality-badge piperless-quality-badge--<?php echo esc_attr( $model['quality'] ); ?>"><?php echo esc_html( ucfirst( $model['quality'] ) ); ?></span></td>
						<td>
							<button type="button" class="button button-small piperless-model-preview-btn"
								data-model-path="<?php echo esc_attr( $model['path'] ); ?>"
								data-model-name="<?php echo esc_attr( $model['name'] ); ?>"
								data-quality="<?php echo esc_attr( $model['quality'] ); ?>">
								<span class="dashicons dashicons-controls-play piperless-preview-icon-play"></span>
								<span class="dashicons dashicons-controls-pause piperless-preview-icon-pause" style="display:none;"></span>
								<?php esc_html_e( 'Preview', 'piperless' ); ?>
							</button>
							<audio class="piperless-model-audio" preload="none" style="display:none;"></audio>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render the player style preview block inline.
	 */
	private function render_player_preview_block(): void {
		$preview_title = get_option( 'piperless_settings', [] )['player_title'] ?? '';
		?>
		<div id="piperless-player-preview" class="piperless-preview-area">
			<div id="piperless-preview-player" class="piperless-player piperless-player--classic">
				<?php if ( '' !== $preview_title ) : ?>
					<div class="piperless-player__title"><?php echo esc_html( $preview_title ); ?></div>
				<?php endif; ?>
				<button class="piperless-player__play" aria-label="<?php esc_attr_e( 'Play', 'piperless' ); ?>">
					<svg class="piperless-player__play-icon" viewBox="0 0 24 24" width="24" height="24"><polygon points="6,3 20,12 6,21"/></svg>
					<svg class="piperless-player__pause-icon" viewBox="0 0 24 24" width="24" height="24"><rect x="5" y="3" width="5" height="18"/><rect x="14" y="3" width="5" height="18"/></svg>
				</button>
				<div class="piperless-player__progress-wrapper"><div class="piperless-player__progress"><div class="piperless-player__progress-bar" style="width:30%"></div></div></div>
				<div class="piperless-player__time"><span class="piperless-player__current">00:03</span><span class="piperless-player__separator">/</span><span class="piperless-player__duration">00:10</span></div>
				<div class="piperless-player__volume">
					<button class="piperless-player__volume-btn" type="button" aria-label="<?php esc_attr_e( 'Volume', 'piperless' ); ?>">
						<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><polygon points="11,5 6,9 2,9 2,15 6,15 11,19"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07" fill="none" stroke="currentColor" stroke-width="2"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14" fill="none" stroke="currentColor" stroke-width="2"/></svg>
					</button>
					<div class="piperless-player__volume-slider">
						<input type="range" min="0" max="100" value="100" step="1" orient="vertical" aria-label="<?php esc_attr_e( 'Volume', 'piperless' ); ?>">
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Build the full voice dropdown HTML while scan_models() works.
	 *
	 * @return string
	 */
	private function render_voice_dropdown_html(): string {
		$settings = get_option( 'piperless_settings', [] );
		$current  = $settings['default_voice'] ?? '';
		$aliases  = $settings['voice_aliases'] ?? [];

		ob_start();
		echo '<select id="default_voice" name="piperless_settings[default_voice]" class="regular-text">';
		printf(
			'<option value="" %s>%s</option>',
			selected( $current, '', false ),
			esc_html__( '— Use first available —', 'piperless' )
		);
		foreach ( $this->voice_list_cache as $voice_name ) {
			$alias    = $aliases[ $voice_name ] ?? '';
			$display  = ( '' !== $alias ) ? $alias : $voice_name;
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $voice_name ),
				selected( $current, $voice_name, false ),
				esc_html( $display )
			);
		}
		echo '</select>';
		return (string) ob_get_clean();
	}

	/**
	 * Extract unique voice names from discovered models.
	 *
	 * @return array<int,string>
	 */
	private function get_voice_list(): array {
		$models = $this->piper->scan_models();
		$voices = [];

		foreach ( $models as $m ) {
			$voices[ $m['voice'] ] = true;
		}

		$voice_list = array_keys( $voices );
		sort( $voice_list );

		return $voice_list;
	}

	/**
	 * Get (or generate) a short preview WAV for a specific voice model.
	 *
	 * Uses a standard preview phrase synthesised with the given model.
	 * Results are cached by model path + quality.
	 *
	 * @param string $model_path Absolute path to .onnx model.
	 * @param string $quality    Quality tier.
	 * @return string URL to preview WAV, or empty string on failure.
	 */
	private function get_model_preview_url( string $model_path, string $quality = 'medium' ): string {
		$cache_key = 'model_preview_' . md5( $model_path . '|' . $quality );

		if ( $this->cache->exists( $cache_key ) ) {
			return $this->cache->proxy_url( $cache_key );
		}

		if ( ! $this->cache->ensure_dir() ) {
			return '';
		}

		// Short preview phrase — keep it brief so generation is fast.
		$preview_text = __(
			'Hello. This is a preview of the Piper text to speech voice.',
			'piperless'
		);

		$this->logger->info( 'Generating model preview for {model}', [ 'model' => basename( $model_path ) ] );

		$wav = $this->piper->synthesise( $preview_text, $model_path, $quality );

		if ( null === $wav ) {
			$this->logger->error( 'Failed to generate model preview for {model}', [ 'model' => basename( $model_path ) ] );
			return '';
		}

		if ( ! $this->cache->put( $cache_key, $wav ) ) {
			return '';
		}

		return $this->cache->proxy_url( $cache_key );
	}

	/**
	 * Get (or generate) a short test WAV for style previews.
	 *
	 * @return string URL to test WAV.
	 */
	private function get_preview_audio_url(): string {
		$test_key  = 'piperless_test_preview';
		$test_path = $this->cache->file_path( $test_key );

		if ( $this->cache->exists( $test_key ) ) {
			return $this->cache->file_url( $test_key );
		}

		if ( ! $this->cache->ensure_dir() ) {
			return '';
		}

		// Generate a 2-second 440 Hz sine wave test tone.
		$wav = $this->generate_test_tone( 440, 2 );

		if ( null === $wav ) {
			return '';
		}

		$this->cache->put( $test_key, $wav );

		return $this->cache->file_url( $test_key );
	}

	/**
	 * Generate a test tone WAV in memory.
	 *
	 * @param int   $frequency Hz.
	 * @param float $duration  Seconds.
	 * @return string|null WAV data.
	 */
	private function generate_test_tone( int $frequency = 440, float $duration = 2.0 ): ?string {
		$sample_rate    = 22050;
		$num_samples    = (int) ( $sample_rate * $duration );
		$amplitude      = 0x3FFF; // Half max to avoid clipping.

		$data = '';
		for ( $i = 0; $i < $num_samples; $i++ ) {
			$sample = (int) ( $amplitude * sin( 2 * M_PI * $frequency * $i / $sample_rate ) );
			$data  .= pack( 'v', $sample ); // 16-bit little-endian.
		}

		$byte_rate     = $sample_rate * 2; // 16-bit mono.
		$block_align   = 2;
		$bits_sample   = 16;
		$data_size     = strlen( $data );

		$header = pack(
			'A4VA4A4VvvVVvvA4V',
			'RIFF',
			36 + $data_size,
			'WAVE',
			'fmt ',
			16,
			1,       // PCM
			1,       // mono
			$sample_rate,
			$byte_rate,
			$block_align,
			$bits_sample,
			'data',
			$data_size
		);

		return $header . $data;
	}
}
