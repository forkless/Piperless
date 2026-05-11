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
		$disk         = $this->get_disk_usage();
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
						__( 'Opus', 'piperless' ),
						$file_stats['opus'] ?? [ 'count' => 0, 'bytes' => 0 ],
						'#1565c0'
					); ?>
					<?php $this->render_file_card(
						__( 'MP3', 'piperless' ),
						$file_stats['mp3'] ?? [ 'count' => 0, 'bytes' => 0 ],
						'#008a20'
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
					<div class="piperless-dash-info-body">
						<span class="piperless-dash-info-text">
							<?php
							printf(
								/* translators: 1: free space, 2: total space */
								esc_html__( 'Server storage: %1$s free of %2$s', 'piperless' ),
								esc_html( $this->human_size( $disk['free'] ) ),
								esc_html( $this->human_size( $disk['total'] ) )
							);
							?>
						</span>
						<div class="piperless-dash-bar">
							<div class="piperless-dash-bar-fill" style="width:<?php echo esc_attr( (string) round( $disk['pct'] ) ); ?>%;"></div>
						</div>
						<span class="piperless-dash-bar-label">
							<?php
							printf(
								/* translators: %s: percentage used */
								esc_html__( '%s%% used', 'piperless' ),
								esc_html( (string) round( $disk['pct'] ) )
							);
							?>
						</span>
					</div>
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
	 * Get disk usage stats for the uploads directory volume.
	 *
	 * @return array{total:float,free:float,used:float,pct:float}
	 */
	private function get_disk_usage(): array {
		$dir = $this->cache->dir();

		// Walk up until we find a directory that exists.
		while ( ! is_dir( $dir ) && '/' !== $dir ) {
			$dir = dirname( $dir );
		}

		$total = @disk_total_space( $dir );
		$free  = @disk_free_space( $dir );

		$fallback = [ 'total' => 0.0, 'free' => 0.0, 'used' => 0.0, 'pct' => 0.0 ];

		if ( false === $total || null === $total || false === $free || null === $free || $total <= 0.0 ) {
			return $fallback;
		}

		$used = $total - $free;
		$pct  = ( $used / $total ) * 100.0;

		return [
			'total' => (float) $total,
			'free'  => (float) $free,
			'used'  => (float) $used,
			'pct'   => (float) $pct,
		];
	}

	/**
	 * Format bytes as a human-readable string.
	 *
	 * @param float $bytes Raw byte count.
	 * @return string e.g. "24.5 MB"
	 */
	private function human_size( float $bytes ): string {
		if ( $bytes <= 0.0 ) {
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
