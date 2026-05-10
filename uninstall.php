<?php
/**
 * Piperless — Uninstall Handler
 *
 * Cleans up all plugin data when the plugin is deleted via the WordPress admin.
 * Only runs on uninstall, not on deactivation.
 *
 * @package Piperless
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// ── Remove plugin options ─────────────────────────────────────────────────
delete_option( 'piperless_settings' );

// ── Remove all post meta ──────────────────────────────────────────────────
global $wpdb;

$meta_keys = [
	'_piperless_voice',
	'_piperless_language',
	'_piperless_quality',
	'_piperless_audio_url',
	'_piperless_duration',
	'_piperless_cache_key',
	'_piperless_generated_at',
];

foreach ( $meta_keys as $key ) {
	$wpdb->delete(
		$wpdb->postmeta,
		[ 'meta_key' => $key ],
		[ '%s' ]
	);
}

// ── Remove cached audio files ─────────────────────────────────────────────
$upload_dir    = wp_upload_dir();
$piperless_dir = $upload_dir['basedir'] . '/piperless';

if ( is_dir( $piperless_dir ) ) {
	$files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $piperless_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $files as $file ) {
		if ( $file->isDir() ) {
			@rmdir( $file->getRealPath() );
		} else {
			@unlink( $file->getRealPath() );
		}
	}

	@rmdir( $piperless_dir );
}

// ── Clear scheduled hooks ─────────────────────────────────────────────────
wp_clear_scheduled_hook( 'piperless_daily_cleanup' );
