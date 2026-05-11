<?php
/**
 * Dashboard widget — audio storage at a glance.
 *
 * @package Piperless
 */

declare(strict_types=1);

namespace Piperless;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard widget.
 *
 * Registers a WordPress dashboard widget (togglable via Screen Options)
 * that shows:
 *
 *   - Counts and total size of WAV, MP3, and Opus files in the cache
 *   - Number of published posts without an audio transcript
 *   - Available disk space on the server
 *
 * @since 1.1.4
 */
class Dashboard {

	/** @var Logger */
	private Logger $logger;

	/** @var Cache_Manager */
	private Cache_Manager $cache;

	/** @var string Widget slug. */
	private string $widget_id = 'piperless_dashboard';

	/**
	 * Constructor.
	 *
	 * @param Logger        $logger Logger.
	 * @param Cache_Manager $cache  Cache manager.
	 */
	public function __construct( Logger $logger, Cache_Manager $cache ) {
		$this->logger = $logger;
		$this->cache  = $cache;
	}

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'wp_dashboard_setup', [ $this, 'register_widget' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_styles' ] );
	}

	/**
	 * Register the dashboard widget.
	 */
	public function register_widget(): void {
		wp_add_dashboard_widget(
			$this->widget_id,
			__( 'Piperless — Audio Storage', 'piperless' ),
			[ $this, 'render' ]
		);
	}

	/**
	 * Render the dashboard widget.
	 */
	public function render(): void {
		$file_stats   = $this->get_file_stats();
		$without      = $this->get_posts_without_audio();
		$disk_free    = $this->get_disk_free();
		$total_files  = ( $file_stats['mp3']['count'] ?? 0 )
			+ ( $file_stats['wav']['count'] ?? 0 )
			+ ( $file_stats['opus']['count'] ?? 0 );
		?>

		<div class="piperless-dashboard">
			<?php if ( 0 === $total_files ) : ?>
				<p class="piperless-dash-empty"><?php esc_html_e( 'No audio files generated yet. Publish a post or generate audio from the editor sidebar.', 'piperless' ); ?></p>
			<?php else : ?>
				<div class="piperless-dash-grid">
					<?php $this->render_file_card(
						__( 'MP3', 'piperless' ),
						$file_stats['mp3'] ?? [ 'count' => 0, 'bytes' => 0 ],
						'#008a20'
					); ?>
					<?php $this->render_file_card(
						__( 'Opus', 'piperless' ),
						$file_stats['opus'] ?? [ 'count' => 0, 'bytes' => 0 ],
						'#1565c0'
					); ?>
					<?php $this->render_file_card(
						__( 'WAV', 'piperless' ),
						$file_stats['wav'] ?? [ 'count' => 0, 'bytes' => 0 ],
						'#f56e28'
					); ?>
				</div>
			<?php endif; ?>

			<div class="piperless-dash-info">
				<div class="piperless-dash-info-item piperless-dash-info-posts">
					<span class="piperless-dash-info-icon">&#9998;</span>
					<span class="piperless-dash-info-text">
						<?php
						printf(
							/* translators: %d: number of posts without audio */
							esc_html( _n(
								'%d post without an audio transcript.',
								'%d posts without an audio transcript.',
								$without,
								'piperless'
							) ),
							$without
						);
						?>
					</span>
					<?php if ( $without > 0 ) : ?>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=post' ) ); ?>" class="piperless-dash-info-link">
							<?php esc_html_e( 'View posts', 'piperless' ); ?>
						</a>
					<?php endif; ?>
				</div>

				<div class="piperless-dash-info-item piperless-dash-info-storage">
					<span class="piperless-dash-info-icon">&#9000;</span>
					<span class="piperless-dash-info-text">
						<?php
						printf(
							/* translators: %s: human-readable free disk space */
							esc_html__( 'Server storage available: %s', 'piperless' ),
							esc_html( $disk_free )
						);
						?>
					</span>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a single file-type stat card.
	 *
	 * @param string $label  e.g. "MP3".
	 * @param array  $stats  ['count' => int, 'bytes' => int].
	 * @param string $color  Accent color hex.
	 */
	private function render_file_card( string $label, array $stats, string $color ): void {
		$count = $stats['count'] ?? 0;
		$size  = $this->human_size( $stats['bytes'] ?? 0 );
		?>
		<div class="piperless-dash-card" style="--card-accent: <?php echo esc_attr( $color ); ?>;">
			<div class="piperless-dash-card-value"><?php echo esc_html( (string) $count ); ?></div>
			<div class="piperless-dash-card-label"><?php echo esc_html( $label ); ?></div>
			<div class="piperless-dash-card-sub"><?php echo esc_html( $size ); ?></div>
		</div>
		<?php
	}

	/**
	 * Scan the cache directory and return per-format file counts and byte totals.
	 *
	 * @return array<string,array{count:int,bytes:int}>
	 */
	private function get_file_stats(): array {
		$cache_dir = $this->cache->dir();
		$stats     = [
			'mp3'  => [ 'count' => 0, 'bytes' => 0 ],
			'wav'  => [ 'count' => 0, 'bytes' => 0 ],
			'opus' => [ 'count' => 0, 'bytes' => 0 ],
		];

		if ( ! is_dir( $cache_dir ) ) {
			return $stats;
		}

		foreach ( [ 'mp3', 'wav', 'opus' ] as $ext ) {
			$files = (array) glob( $cache_dir . '/*.' . $ext );
			foreach ( $files as $file ) {
				$size = @filesize( $file );
				if ( false !== $size ) {
					$stats[ $ext ]['count']++;
					$stats[ $ext ]['bytes'] += $size;
				}
			}
		}

		return $stats;
	}

	/**
	 * Count published posts / pages without an audio transcript.
	 *
	 * @return int
	 */
	private function get_posts_without_audio(): int {
		global $wpdb;

		$types = $this->supported_post_types();
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->posts} p
				WHERE p.post_type IN ( {$placeholders} )
					AND p.post_status = 'publish'
					AND NOT EXISTS (
						SELECT 1 FROM {$wpdb->postmeta} pm
						WHERE pm.post_id = p.ID
							AND pm.meta_key = '_piperless_audio_url'
							AND pm.meta_value != ''
					)",
				...$types
			)
		);

		return (int) $count;
	}

	/**
	 * Get human-readable free disk space on the uploads directory volume.
	 *
	 * @return string e.g. "12.3 GB"
	 */
	private function get_disk_free(): string {
		$dir = $this->cache->dir();

		// Walk up until we find a directory that exists.
		while ( ! is_dir( $dir ) && '/' !== $dir ) {
			$dir = dirname( $dir );
		}

		$free = @disk_free_space( $dir );

		if ( false === $free || null === $free ) {
			return __( 'Unavailable', 'piperless' );
		}

		return $this->human_size( $free );
	}

	/**
	 * Format bytes as a human-readable string.
	 *
	 * @param int $bytes Raw byte count.
	 * @return string e.g. "24.5 MB"
	 */
	private function human_size( int $bytes ): string {
		if ( 0 === $bytes ) {
			return '0 B';
		}

		$units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		$exp   = (int) floor( log( $bytes, 1024 ) );
		$exp   = min( $exp, count( $units ) - 1 );

		$val = $bytes / ( 1024 ** $exp );

		return round( $val, 1 ) . ' ' . $units[ $exp ];
	}

	/**
	 * Enqueue dashboard-specific styles on the dashboard page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_styles( string $hook_suffix ): void {
		if ( 'index.php' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'piperless-dashboard',
			PIPERLESS_PLUGIN_URL . 'assets/css/dashboard.css',
			[],
			PIPERLESS_VERSION
		);
	}

	/**
	 * Supported post types for audio transcripts.
	 *
	 * @return string[]
	 */
	private function supported_post_types(): array {
		$types = apply_filters( 'piperless_post_types', [ 'post', 'page' ] );
		return is_array( $types ) ? $types : [ 'post' ];
	}
}
