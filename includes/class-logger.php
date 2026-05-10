<?php
/**
 * PSR-3-inspired file logger for Piperless.
 *
 * @package Piperless
 */

declare(strict_types=1);

namespace Piperless;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PSR-3-inspired file logger.
 *
 * Writes to two channels:
 *   - WordPress debug.log (when WP_DEBUG + WP_DEBUG_LOG are enabled)
 *   - A dedicated piperless.log in wp-content/uploads/piperless/
 *
 * The dedicated log file is chmod'd 0600 after every write to prevent
 * world-readable access on servers without .htaccess protection
 * (Nginx, IIS, LiteSpeed).  If the dedicated file can't be written,
 * entries fall through to PHP's error_log() so diagnostics are never
 * completely lost.
 *
 * Severity levels (PSR-3 compatible): emergency, alert, critical,
 * error, warning, notice, info, debug.  The minimum level is
 * controlled by the logging_level setting.
 *
 * log_last_error() captures error_get_last() after @-suppressed
 * filesystem operations — call error_clear_last() before the @ call
 * for deterministic attribution.
 *
 * @since 0.1.0
 */
class Logger {

	public const EMERGENCY = 'emergency';
	public const ALERT     = 'alert';
	public const CRITICAL  = 'critical';
	public const ERROR     = 'error';
	public const WARNING   = 'warning';
	public const NOTICE    = 'notice';
	public const INFO      = 'info';
	public const DEBUG     = 'debug';

	/**
	 * Ordered severity (lowest number = most severe).
	 *
	 * @var array<string,int>
	 */
	private const LEVELS = [
		self::EMERGENCY => 0,
		self::ALERT     => 1,
		self::CRITICAL  => 2,
		self::ERROR     => 3,
		self::WARNING   => 4,
		self::NOTICE    => 5,
		self::INFO      => 6,
		self::DEBUG     => 7,
	];

	/** @var string Minimum level to record. */
	private string $threshold;

	/** @var string Path to log file. */
	private string $log_file;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$settings = get_option( 'piperless_settings', [] );
		$this->threshold = $settings['logging_level'] ?? self::WARNING;

		$upload_dir     = wp_upload_dir();
		$this->log_file = trailingslashit( $upload_dir['basedir'] ) . 'piperless/piperless.log';
	}

	/**
	 * System is unusable.
	 */
	public function emergency( string $message, array $context = [] ): void {
		$this->log( self::EMERGENCY, $message, $context );
	}

	/**
	 * Action must be taken immediately.
	 */
	public function alert( string $message, array $context = [] ): void {
		$this->log( self::ALERT, $message, $context );
	}

	/**
	 * Critical conditions.
	 */
	public function critical( string $message, array $context = [] ): void {
		$this->log( self::CRITICAL, $message, $context );
	}

	/**
	 * Runtime errors that do not require immediate action.
	 */
	public function error( string $message, array $context = [] ): void {
		$this->log( self::ERROR, $message, $context );
	}

	/**
	 * Exceptional occurrences that are not errors.
	 */
	public function warning( string $message, array $context = [] ): void {
		$this->log( self::WARNING, $message, $context );
	}

	/**
	 * Normal but significant events.
	 */
	public function notice( string $message, array $context = [] ): void {
		$this->log( self::NOTICE, $message, $context );
	}

	/**
	 * Interesting events.
	 */
	public function info( string $message, array $context = [] ): void {
		$this->log( self::INFO, $message, $context );
	}

	/**
	 * Detailed debug information.
	 */
	public function debug( string $message, array $context = [] ): void {
		$this->log( self::DEBUG, $message, $context );
	}

	/**
	 * Write a log entry.
	 *
	 * @param string               $level   Severity level.
	 * @param string               $message Log message.
	 * @param array<string,mixed>  $context Additional data.
	 */
	private function log( string $level, string $message, array $context = [] ): void {
		if ( ! isset( self::LEVELS[ $level ] ) ) {
			return;
		}

		if ( self::LEVELS[ $level ] > self::LEVELS[ $this->threshold ] ) {
			return;
		}

		$line = $this->format( $level, $message, $context );

		// WordPress debug log.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[piperless] ' . $line );
		}

		// Dedicated log file.
		$dir      = dirname( $this->log_file );
		$written  = false;

		if ( wp_mkdir_p( $dir ) ) {
			$written = ( false !== @file_put_contents( $this->log_file, $line . "\n", FILE_APPEND | LOCK_EX ) );
			// Restrict to owner-only: 0600 prevents web-server read on
			// hosts without .htaccess protection (Nginx, IIS, LiteSpeed).
			if ( $written ) {
				@chmod( $this->log_file, 0600 );
			}
		}

		// Fallback: if the dedicated log file couldn't be written,
		// send the entry to PHP's error_log so it reaches the server log
		// (or syslog).  This ensures diagnostics are not lost on hosts
		// where the uploads directory is not writable.
		if ( ! $written ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[piperless] [FALLBACK] ' . $line );
		}
	}

	/**
	 * Format a log line.
	 *
	 * @param string               $level   Severity.
	 * @param string               $message Message text.
	 * @param array<string,mixed>  $context Context data.
	 * @return string
	 */
	private function format( string $level, string $message, array $context ): string {
		$timestamp = gmdate( 'Y-m-d H:i:s' );
		$upper     = strtoupper( $level );

		// Interpolate context placeholders: {key}
		if ( [] !== $context ) {
			$replacements = [];
			foreach ( $context as $key => $val ) {
				if ( ! is_array( $val ) && ( ! is_object( $val ) || method_exists( $val, '__toString' ) ) ) {
					$replacements[ '{' . $key . '}' ] = (string) $val;
				}
			}
			$message = strtr( $message, $replacements );
		}

		return sprintf( '[%s] %s: %s', $timestamp, $upper, $message );
	}

	/**
	 * Return the log file path.
	 *
	 * @return string
	 */
	public function log_file_path(): string {
		return $this->log_file;
	}

	/**
	 * Read recent log entries.
	 *
	 * @param int $lines Number of lines to return.
	 * @return string[]
	 */
	public function tail( int $lines = 50 ): array {
		if ( ! file_exists( $this->log_file ) ) {
			return [];
		}

		$content = @file( $this->log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( false === $content ) {
			return [];
		}

		return array_slice( $content, -$lines );
	}

	/**
	 * Capture and log the last PHP error (typically after a @-suppressed
	 * filesystem operation).  Call immediately after a failed @ call to
	 * preserve the diagnostic information.
	 *
	 * @param string $context Description of what was being attempted.
	 */
	public function log_last_error( string $context ): void {
		$err = error_get_last();
		if ( null === $err ) {
			return;
		}

		$this->warning(
			'{context}: {type}: {message} in {file}:{line}',
			[
				'context' => $context,
				'type'    => $err['type'] ?? '?',
				'message' => $err['message'] ?? '?',
				'file'    => $err['file'] ?? '?',
				'line'    => $err['line'] ?? '?',
			]
		);
	}

	/**
	 * Clear the log file.
	 */
	public function clear(): void {
		if ( file_exists( $this->log_file ) ) {
			@unlink( $this->log_file );
		}
	}
}
