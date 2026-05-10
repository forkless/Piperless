<?php
/**
 * Piper TTS CLI integration.
 *
 * @package Piperless
 */

declare(strict_types=1);

namespace Piperless;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Piper TTS CLI integration.
 *
 * Wraps the Piper text-to-speech command-line tool.  Communicates with
 * Piper via proc_open, supporting three interface modes:
 *
 *   - **raw** (native Piper CLI): text piped to stdin, raw PCM read
 *     from stdout, wrapped in a WAV container in-memory.  Streamed to
 *     a temp file for large outputs to keep memory bounded.
 *
 *   - **file** (Python wheel wrappers): text piped to stdin, Piper
 *     writes a WAV file via --output_file.  Read from disk afterward.
 *
 *   - **positional** (custom wrappers): model path and text passed as
 *     positional args; wrapper always writes output.wav in CWD.  Runs
 *     inside an isolated temp directory.
 *
 * Mode is auto-detected from --help output and cached for the process
 * lifetime.  Users can override via the piper_interface setting.
 *
 * ## Model discovery
 *
 * scan_models() recursively walks the configured models directory for
 * `.onnx` files, parses filenames into voice / language / quality, and
 * validates companion `.onnx.json` files (Git LFS pointer detection,
 * empty-file checks, JSON syntax).  Results are cached per-request.
 *
 * ## open_basedir awareness
 *
 * All filesystem checks are guarded by is_path_accessible(), which
 * compares the target path against PHP's open_basedir using pure
 * string-prefix matching — avoiding file_exists() on blocked paths
 * that can hang on stale NFS mounts or slow network filesystems.
 * Direct filesystem calls use @ suppression as a second layer.
 *
 * ## Process safety
 *
 * synthesise() wraps proc_open in a set_time_limit() guard (read from
 * the Performance tab, default 300s).  The original limit is restored
 * in a finally block even if an exception is thrown.
 *
 * @since 0.1.0
 */
class Piper {

	/** @var Logger */
	private Logger $logger;

	/** @var string|null Cached output mode: 'raw' or 'file'. */
	private ?string $output_mode = null;

	/** @var array<int, array>|null Cached model scan result. */
	private ?array $cached_models = null;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Generate speech from text.
	 *
	 * Auto-detects whether the Piper binary supports --output-raw
	 * (native CLI — raw PCM on stdout) or --output_file (Python wheel
	 * wrapper — writes a WAV file to disk).
	 *
	 * @param string $text    Text to synthesise.
	 * @param string $model   Absolute path to the .onnx model file.
	 * @param string $quality Quality tier (low, medium, high).
	 * @return string|null    WAV data or null on failure.
	 */
	public function synthesise( string $text, string $model, string $quality = 'medium', string $sentence_silence = '', string $length_scale_override = '' ): ?string {
		$binary = $this->get_binary();

		if ( '' === $binary ) {
			$this->logger->error( 'Piper binary not configured.' );
			return null;
		}

		if ( ! $this->is_path_accessible( $binary ) ) {
			$this->logger->error( 'Piper binary outside open_basedir: {path}', [ 'path' => $binary ] );
			return null;
		}

		if ( ! @file_exists( $binary ) || ! @is_executable( $binary ) ) {
			$this->logger->error( 'Piper binary not found or not executable: {path}', [ 'path' => $binary ] );
			return null;
		}

		if ( ! @file_exists( $model ) ) {
			$this->logger->error( 'Piper model file not found: {model}', [ 'model' => $model ] );
			return null;
		}

		// Prevent the PHP worker from being killed by max_execution_time
		// while Piper processes a long text.  Restored in finally block.
		// Read timeout from settings (default 300s), clamped 30–3600.
		$settings = get_option( 'piperless_settings', [] );
		$timeout  = max( 30, min( 3600, (int) ( $settings['piper_process_timeout'] ?? 300 ) ) );

		$prev_limit = (int) ini_get( 'max_execution_time' );
		try {
			if ( $prev_limit > 0 && $prev_limit < $timeout ) {
				set_time_limit( $timeout );
			}

			$mode = $this->detect_output_mode();

			return match ( $mode ) {
				'file'       => $this->synthesise_via_file( $text, $binary, $model, $quality, $sentence_silence, $length_scale_override ),
				'positional' => $this->synthesise_via_positional( $text, $binary, $model, $quality ),
				default      => $this->synthesise_via_raw( $text, $binary, $model, $quality, $sentence_silence, $length_scale_override ),
			};
		} finally {
			if ( $prev_limit > 0 ) {
				set_time_limit( $prev_limit );
			}
		}
	}

	/**
	 * Synthesise via --output-raw (raw PCM on stdout, wrap in WAV).
	 *
	 * @param string $text    Text.
	 * @param string $binary  Piper executable path.
	 * @param string $model   Model path.
	 * @param string $quality Quality tier.
	 * @return string|null WAV data.
	 */
	private function synthesise_via_raw( string $text, string $binary, string $model, string $quality, string $sentence_silence = '', string $length_scale_override = '' ): ?string {
		$extra = $this->extra_flags( $quality, $sentence_silence, $length_scale_override );
		$cmd = sprintf(
			'%s --model %s%s --output-raw',
			escapeshellarg( $binary ),
			escapeshellarg( $model ),
			$extra
		);

		$this->logger->debug( 'Running (raw mode): {cmd}', [ 'cmd' => $cmd ] );

		$process = proc_open( $cmd, [
			0 => [ 'pipe', 'r' ],
			1 => [ 'pipe', 'w' ],
			2 => [ 'pipe', 'w' ],
		], $pipes );

		if ( ! is_resource( $process ) ) {
			$this->logger->error( 'Failed to start Piper process (raw mode).' );
			return null;
		}

		fwrite( $pipes[0], $text );
		fclose( $pipes[0] );

		// Stream raw PCM to a temp file instead of loading into memory.
		// Long texts can produce 25+ MB of audio — keeping it on disk
		// avoids exhausting PHP's memory limit.
		$temp_raw = tempnam( sys_get_temp_dir(), 'piperless_raw_' );
		if ( false === $temp_raw ) {
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			proc_close( $process );
			$this->logger->error( 'Failed to create temp file for raw PCM.' );
			return null;
		}

		$fp_out = fopen( $temp_raw, 'wb' );
		if ( false === $fp_out ) {
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			proc_close( $process );
			@unlink( $temp_raw );
			$this->logger->error( 'Failed to open temp file for raw PCM.' );
			return null;
		}

		while ( ! feof( $pipes[1] ) ) {
			$chunk = fread( $pipes[1], 65536 );
			if ( false === $chunk ) {
				break;
			}
			fwrite( $fp_out, $chunk );
		}
		fclose( $fp_out );
		fclose( $pipes[1] );

		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[2] );

		$exit_code = proc_close( $process );

		$raw_size = filesize( $temp_raw );
		if ( 0 !== $exit_code || 0 === $raw_size ) {
			@unlink( $temp_raw );
			$this->logger->error(
				'Piper (raw mode) exited with code {code}: {stderr}',
				[ 'code' => $exit_code, 'stderr' => $this->strip_alsa_noise( $stderr ) ]
			);
			return null;
		}

		$sample_rate = $this->get_model_sample_rate( $model );
		$wav = $this->raw_file_to_wav( $temp_raw, $sample_rate );
		@unlink( $temp_raw );

		return $wav;
	}

	/**
	 * Synthesise via --output_file (Piper writes WAV to a temp file).
	 *
	 * Used by Python wheel wrappers and installs where stdout piping
	 * is not the default interface.
	 *
	 * @param string $text    Text.
	 * @param string $binary  Piper executable path.
	 * @param string $model   Model path.
	 * @param string $quality Quality tier.
	 * @return string|null WAV data.
	 */
	private function synthesise_via_file( string $text, string $binary, string $model, string $quality, string $sentence_silence = '', string $length_scale_override = '' ): ?string {
		$temp_file = tempnam( sys_get_temp_dir(), 'piperless_' ) . '.wav';

		$extra = $this->extra_flags( $quality, $sentence_silence, $length_scale_override );
		$cmd = sprintf(
			'%s --model %s%s --output_file %s',
			escapeshellarg( $binary ),
			escapeshellarg( $model ),
			$extra,
			escapeshellarg( $temp_file )
		);

		$this->logger->debug( 'Running (file mode): {cmd}', [ 'cmd' => $cmd ] );

		$process = proc_open( $cmd, [
			0 => [ 'pipe', 'r' ],
			1 => [ 'pipe', 'w' ],
			2 => [ 'pipe', 'w' ],
		], $pipes );

		if ( ! is_resource( $process ) ) {
			$this->logger->error( 'Failed to start Piper process (file mode).' );
			@unlink( $temp_file );
			return null;
		}

		fwrite( $pipes[0], $text );
		fclose( $pipes[0] );

		// Drain stdout / stderr so the process doesn't block.
		stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );

		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[2] );

		$exit_code = proc_close( $process );

		if ( 0 !== $exit_code || ! file_exists( $temp_file ) || 0 === filesize( $temp_file ) ) {
			$this->logger->error(
				'Piper (file mode) exited with code {code}: {stderr}',
				[ 'code' => $exit_code, 'stderr' => $this->strip_alsa_noise( $stderr ) ]
			);
			@unlink( $temp_file );
			return null;
		}

		$wav = @file_get_contents( $temp_file );
		@unlink( $temp_file );

		return ( false === $wav || '' === $wav ) ? null : $wav;
	}

	/**
	 * Synthesise via positional wrapper (binary model_path "text").
	 *
	 * For custom wrappers that take the model path and text as positional
	 * arguments and always write to `output.wav` in the current directory.
	 * Runs inside a temp directory so the output file is isolated.
	 *
	 * @param string $text    Text.
	 * @param string $binary  Piper executable path.
	 * @param string $model   Model path.
	 * @param string $quality Quality tier (ignored — wrapper controls quality via model choice).
	 * @return string|null WAV data.
	 */
	private function synthesise_via_positional( string $text, string $binary, string $model, string $quality ): ?string {
		// Create a temp directory as CWD so output.wav lands cleanly.
		$work_dir = sys_get_temp_dir() . '/piperless_' . uniqid();
		error_clear_last();
		if ( ! @mkdir( $work_dir, 0755, true ) ) {
			$this->logger->log_last_error( 'Create positional temp dir' );
			$this->logger->error( 'Failed to create temp work directory.' );
			return null;
		}

		$cmd = sprintf(
			'%s %s %s',
			escapeshellarg( $binary ),
			escapeshellarg( $model ),
			escapeshellarg( $text )
		);

		$this->logger->debug( 'Running (positional mode): {cmd}', [ 'cmd' => $cmd ] );

		$descriptorspec = [
			0 => [ 'pipe', 'r' ],
			1 => [ 'pipe', 'w' ],
			2 => [ 'pipe', 'w' ],
		];

		$process = proc_open( $cmd, $descriptorspec, $pipes, $work_dir );

		if ( ! is_resource( $process ) ) {
			$this->logger->error( 'Failed to start Piper process (positional mode).' );
			$this->rmdir_recursive( $work_dir );
			return null;
		}

		// Close stdin immediately — text is in the command args.
		fclose( $pipes[0] );

		$stdout = stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );

		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[2] );

		$exit_code = proc_close( $process );

		$output_file = $work_dir . '/output.wav';

		// WAV file missing or empty → definite failure.
		if ( ! file_exists( $output_file ) || 0 === filesize( $output_file ) ) {
			$this->logger->error(
				'Piper (positional mode) produced no output: {stderr}',
				[ 'stderr' => $this->strip_alsa_noise( $stderr . "\n" . $stdout ) ]
			);
			$this->rmdir_recursive( $work_dir );
			return null;
		}

		// WAV exists, but wrapper exited non-zero (e.g. aplay failure on headless
		// server). Log a warning and return the audio anyway.
		if ( 0 !== $exit_code ) {
			$noise_free = $this->strip_alsa_noise( $stderr . "\n" . $stdout );
			if ( '' !== $noise_free ) {
				$this->logger->warning(
					'Piper (positional mode) exited {code} but output.wav was produced: {stderr}',
					[ 'code' => $exit_code, 'stderr' => $noise_free ]
				);
			}
		}

		$wav = @file_get_contents( $output_file );
		$this->rmdir_recursive( $work_dir );

		return ( false === $wav || '' === $wav ) ? null : $wav;
	}

	/**
	 * Validate a model's companion .json config file.
	 *
	 * Detects Git LFS pointer files (common on Hugging Face when
	 * git-lfs is not installed) and empty/malformed JSON.
	 * Logs a specific warning and returns false for broken configs.
	 *
	 * @param string $json_path Path to the .onnx.json file.
	 * @param string $name      Model basename for log messages.
	 * @return bool True if the config is valid JSON.
	 */
	public function is_valid_model_config( string $json_path, string $name = '' ): bool {
		if ( ! file_exists( $json_path ) ) {
			// No .json is fine — native Piper reads config from the .onnx.
			// Only the Python wheel requires a separate .json.
			$this->logger->debug(
				'Model {name} has no companion .json config — native Piper mode will still work.',
				[ 'name' => $name ]
			);
			return true; // Allow — just warn at debug level.
		}

		$size = filesize( $json_path );

		if ( 0 === $size ) {
			$this->logger->warning(
				'Model {name} .json config is empty (0 bytes) — it will not work with the Python Piper wheel.',
				[ 'name' => $name ]
			);
			return false;
		}

		// Read the first bytes to detect Git LFS pointer files.
		error_clear_last();
		$head = @file_get_contents( $json_path, false, null, 0, 64 );

		if ( false === $head ) {
			$this->logger->log_last_error( 'Read model JSON header for ' . $name );
			$this->logger->warning(
				'Model {name} .json config cannot be read — check file permissions.',
				[ 'name' => $name ]
			);
			return false;
		}

		// Git LFS pointer detection (common on Hugging Face without git-lfs).
		if ( str_starts_with( ltrim( $head ), 'version https://git-lfs.github.com' ) ) {
			$this->logger->warning(
				'Model {name} .json is a Git LFS pointer, not actual JSON. Install git-lfs and run "git lfs pull", or download the real file from Hugging Face.',
				[ 'name' => $name ]
			);
			return false;
		}

		// Read full content for JSON validation.
		error_clear_last();
		$json = @file_get_contents( $json_path );

		if ( false === $json ) {
			$this->logger->log_last_error( 'Read model JSON for ' . $name );
			$this->logger->warning(
				'Model {name} .json config cannot be read — check file permissions.',
				[ 'name' => $name ]
			);
			return false;
		}

		json_decode( $json, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			// Show the first 120 chars of the file to help diagnose the issue.
			$preview = mb_substr( $json, 0, 120 );
			$this->logger->warning(
				'Model {name} .json config is not valid JSON ({error}). First bytes: {preview}',
				[ 'name' => $name, 'error' => json_last_error_msg(), 'preview' => $preview ]
			);
			return false;
		}

		return true;
	}

	/**
	 * Strip ALSA/aplay noise from stderr/stdout output.
	 *
	 * @param string $output Raw process output.
	 * @return string Cleaned output.
	 */
	private function strip_alsa_noise( string $output ): string {
		$lines = explode( "\n", $output );
		$kept  = [];

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );
			if ( '' === $trimmed ) {
				continue;
			}
			// Skip ALSA library chatter.
			if ( str_starts_with( $trimmed, 'ALSA lib' ) ) {
				continue;
			}
			// Skip aplay error lines.
			if ( str_starts_with( $trimmed, 'aplay:' ) ) {
				continue;
			}
			$kept[] = $line;
		}

		return implode( "\n", $kept );
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Path to remove.
	 */
	private function rmdir_recursive( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getRealPath() );
			} else {
				@unlink( $item->getRealPath() );
			}
		}
		@rmdir( $dir );
	}

	/**
	 * Detect whether the Piper binary supports --output-raw or --output_file.
	 *
	 * Runs `--help` once and caches the result.
	 *
	 * @return string 'raw' or 'file'.
	 */
	public function detect_output_mode(): string {
		if ( null !== $this->output_mode ) {
			return $this->output_mode;
		}

		// Honour explicit user override.
		$settings  = get_option( 'piperless_settings', [] );
		$interface = $settings['piper_interface'] ?? 'auto';

		if ( 'standard' === $interface ) {
			$this->output_mode = 'raw';
			return $this->output_mode;
		}

		if ( 'positional' === $interface ) {
			$this->output_mode = 'positional';
			return $this->output_mode;
		}

		// Auto-detect from --help output.
		$binary = $this->get_binary();

		if ( '' === $binary || ! file_exists( $binary ) ) {
			$this->output_mode = 'file'; // Safe default for unknown wrappers.
			return $this->output_mode;
		}

		$help      = $this->run_help();
		$help_text = implode( "\n", $help['output'] );

		if ( false !== strpos( $help_text, '--output-raw' ) ) {
			$this->output_mode = 'raw';
		} elseif ( false !== strpos( $help_text, '--output_file' ) || false !== strpos( $help_text, '--output-file' ) ) {
			$this->output_mode = 'file';
		} else {
			// Unknown wrapper — check for positional-arg signature
			// (help text with <required> placeholders and no --flags).
			if ( str_contains( $help_text, '<' ) && str_contains( $help_text, '>' )
				&& ! preg_match( '/--\w+/', $help_text )
			) {
				$this->output_mode = 'positional';
			} else {
				$this->output_mode = 'file';
			}
		}

		$this->logger->info(
			'Detected Piper output mode: {mode} for {binary}',
			[ 'mode' => $this->output_mode, 'binary' => $binary ]
		);

		return $this->output_mode;
	}

	/**
	 * Run --help and return output + exit code (cached once).
	 *
	 * @return array{output:array<int,string>,exit_code:int}
	 */
	private function run_help(): array {
		static $cached = null;

		if ( null !== $cached ) {
			return $cached;
		}

		$binary = $this->get_binary();
		$output = [];
		$ret    = 0;

		if ( '' !== $binary && file_exists( $binary ) ) {
			$safe_binary = escapeshellarg( $binary );
			exec( $safe_binary . ' --help 2>&1', $output, $ret );
		}

		$cached = [ 'output' => $output, 'exit_code' => $ret ];
		return $cached;
	}

	/**
	 * Check whether a filesystem path is accessible given PHP's open_basedir.
	 *
	 * @param string $path Absolute path to check.
	 * @return bool
	 */
	private function is_path_accessible( string $path ): bool {
		$open_basedir = ini_get( 'open_basedir' );

		if ( empty( $open_basedir ) ) {
			return true; // No restriction.
		}

		$real_path = @realpath( $path );
		if ( false === $real_path ) {
			// Path doesn't exist yet — check parent directory.
			// Stop at filesystem root to avoid triggering open_basedir
			// warnings on file_exists('/').
			$parent = dirname( $path );
			while ( '' !== $parent && '/' !== $parent && ! @file_exists( $parent ) ) {
				$parent = dirname( $parent );
			}
			$resolved = ( '' !== $parent ) ? @realpath( $parent ) : false;
			$real_path = ( false !== $resolved ) ? $resolved : $path;
		}

		$allowed_dirs = explode( PATH_SEPARATOR, $open_basedir );

		foreach ( $allowed_dirs as $allowed ) {
			$allowed = rtrim( trim( $allowed ), '/' );
			if ( '' !== $allowed && str_starts_with( $real_path, $allowed . '/' ) ) {
				return true;
			}
			if ( $real_path === $allowed ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get a human-readable description of the open_basedir restriction.
	 *
	 * @return string
	 */
	public function open_basedir_info(): string {
		$open_basedir = ini_get( 'open_basedir' );
		return ( '' === $open_basedir )
			? __( 'No restriction (open_basedir is not set).', 'piperless' )
			: sprintf(
				/* translators: %s: open_basedir paths */
				__( 'Restricted to: %s', 'piperless' ),
				$open_basedir
			);
	}

	/**
	 * Test the Piper binary and return version info.
	 *
	 * Tolerates wrappers that don't support --help — a binary that
	 * exists and is executable is reported OK even if --help fails.
	 *
	 * @return array{ok:bool,version:string|null,error:string|null,mode:string}
	 */
	public function test(): array {
		$binary = $this->get_binary();

		if ( '' === $binary ) {
			return [
				'ok'      => false,
				'version' => null,
				'error'   => __( 'Piper binary path is not configured.', 'piperless' ),
				'open_basedir' => $this->open_basedir_info(),
				'mode'    => null,
			];
		}

		if ( ! $this->is_path_accessible( $binary ) ) {
			return [
				'ok'      => false,
				'version' => null,
				'error'   => sprintf(
					/* translators: %1$s: binary path, %2$s: open_basedir */
					__( 'Piper binary path %1$s is outside PHP open_basedir (%2$s). Add /opt to open_basedir in your hosting config.', 'piperless' ),
					$binary,
					ini_get( 'open_basedir' )
				),
				'open_basedir' => $this->open_basedir_info(),
				'mode'    => null,
			];
		}

		if ( ! file_exists( $binary ) ) {
			return [
				'ok'      => false,
				'version' => null,
				'error'   => sprintf(
					/* translators: %s: file path that was checked */
					__( 'Piper binary not found at the configured path: %s', 'piperless' ),
					$binary
				),
				'open_basedir' => $this->open_basedir_info(),
				'mode'    => null,
			];
		}

		if ( ! is_executable( $binary ) ) {
			return [
				'ok'      => false,
				'version' => null,
				'error'   => __( 'Piper binary is not executable.', 'piperless' ),
				'open_basedir' => $this->open_basedir_info(),
				'mode'    => null,
			];
		}

		$help    = $this->run_help();
		$output  = $help['output'];
		$help_ok = ( 0 === $help['exit_code'] );
		$mode    = $this->detect_output_mode();

		// Try to extract version from help output.
		$version = null;
		if ( $help_ok ) {
			foreach ( $output as $line ) {
				if ( preg_match( '/(\d+\.\d+\.\d+)/', $line, $m ) ) {
					$version = $m[1];
					break;
				}
			}
		}

		// Binary exists and is executable — that's enough for OK.
		return [
			'ok'      => true,
			'version' => $version,
			'error'   => $help_ok
				? null
				: __( 'Binary found, but --help is not supported. Version unknown — synthesis may still work.', 'piperless' ),
			'mode'    => $mode,
		];
	}

	/**
	 * Scan the models directory and return discovered models.
	 *
	 * Each model entry includes voice, language, quality, and path.
	 *
	 * @return array<int,array{name:string,voice:string,language:string,quality:string,path:string}>
	 */
	public function scan_models(): array {
		// Return cached result to avoid re-scanning the directory multiple
		// times per request (admin page calls this 3×).
		if ( null !== $this->cached_models ) {
			return $this->cached_models;
		}

		$settings = get_option( 'piperless_settings', [] );
		$dir      = $settings['models_directory'] ?? '';

		// Skip filesystem access if the directory is outside open_basedir —
		// avoids hangs on stale NFS mounts or slow network filesystems.
		if ( '' === $dir ) {
			$this->cached_models = [];
			return [];
		}

		if ( ! $this->is_path_accessible( $dir ) ) {
			$this->logger->warning(
				'Models directory {dir} is outside open_basedir ({ob}).',
				[ 'dir' => $dir, 'ob' => ini_get( 'open_basedir' ) ]
			);
			$this->cached_models = [];
			return [];
		}

		if ( ! @is_dir( $dir ) ) {
			$this->logger->debug( 'Models directory not accessible: {dir}', [ 'dir' => $dir ] );
			$this->cached_models = [];
			return [];
		}

		// Recursive scan for .onnx files.
		$files = [];

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator(
					$dir,
					\FilesystemIterator::SKIP_DOTS
				)
			);

			foreach ( $iterator as $file_info ) {
				if ( ! $file_info->isFile() ) {
					continue;
				}

				$ext = pathinfo( $file_info->getPathname(), PATHINFO_EXTENSION );
				if ( 'onnx' === strtolower( $ext ) ) {
					$files[] = $file_info->getPathname();
				}
			}
		} catch ( \Exception $e ) {
			$this->logger->error(
				'Error scanning models directory {dir}: {msg}',
				[ 'dir' => $dir, 'msg' => $e->getMessage() ]
			);
			$this->cached_models = [];
			return [];
		}

		$this->logger->debug(
			'Found {count} .onnx file(s) in {dir}',
			[ 'count' => count( $files ), 'dir' => $dir ]
		);

		if ( [] === $files ) {
			$this->cached_models = [];
			return [];
		}

		$models = [];

		foreach ( $files as $file ) {
			$basename = basename( $file, '.onnx' );

			// Expected format: {language}_{voice}-{quality}  (e.g. en_US-amy-low)
			// Tolerate variations with fewer segments.
			$parts = explode( '-', $basename );
			$quality = 'medium';

			if ( count( $parts ) >= 2 ) {
				$quality_candidate = strtolower( end( $parts ) );
				$known_qualities = apply_filters(
					'piperless_quality_tiers',
					[ 'low', 'medium', 'high', 'lite', 'small', 'fast', 'quality' ]
				);
				if ( in_array( $quality_candidate, $known_qualities, true ) ) {
					$quality = $quality_candidate;
					array_pop( $parts );
				}
			}

			// After removing quality, remaining parts are:
			// ['en_US', 'lessac']       → language=first, voice=rest
			// ['en_US', 'lessac', 'low'] → (if quality not matched)
			$language  = 'en_US';
			$voice     = 'default';

			if ( count( $parts ) >= 1 ) {
				$lang_part = array_shift( $parts );
				$lang_segs = explode( '_', $lang_part );

				if ( count( $lang_segs ) >= 2 ) {
					$language = $lang_segs[0] . '_' . $lang_segs[1];
				} else {
					$language = $lang_part;
				}
			}

			if ( count( $parts ) >= 1 ) {
				$voice = implode( '-', $parts );
			}

			// Validate the companion .json — skip models with broken configs.
			$json_path  = $file . '.json';
			$json_valid = $this->is_valid_model_config( $json_path, $basename );

			if ( ! $json_valid ) {
				continue; // Skip this model — logged inside is_valid_model_config.
			}

			$models[] = [
				'name'     => $basename,
				'voice'    => $voice,
				'language' => $language,
				'quality'  => $quality,
				'path'     => $file,
			];
		}

		$this->cached_models = $models;
		return $models;
	}

	/**
	 * Get the model file for a given voice, language, and quality.
	 *
	 * @param string $voice    Voice name.
	 * @param string $language Language code.
	 * @param string $quality  Quality tier.
	 * @return string|null Absolute path or null if not found.
	 */
	public function find_model( string $voice, string $language, string $quality ): ?string {
		$models = $this->scan_models();

		// Exact match first.
		foreach ( $models as $model ) {
			if (
				$model['voice'] === $voice &&
				$model['language'] === $language &&
				$model['quality'] === $quality
			) {
				return $model['path'];
			}
		}

		// Fuzzy: match voice + language, any quality.
		foreach ( $models as $model ) {
			if ( $model['voice'] === $voice && $model['language'] === $language ) {
				return $model['path'];
			}
		}

		// Fuzzy: match language, any voice/quality.
		foreach ( $models as $model ) {
			if ( $model['language'] === $language ) {
				return $model['path'];
			}
		}

		// Return first available model as fallback.
		if ( [] !== $models ) {
			return $models[0]['path'];
		}

		return null;
	}

	/**
	 * Build extra CLI flags from advanced settings.
	 *
	 * @param string $quality Quality tier (for default length_scale).
	 * @return string Space-prefixed flags or empty string.
	 */
	private function extra_flags( string $quality, string $sentence_silence = '', string $length_scale_override = '' ): string {
		$settings = get_option( 'piperless_settings', [] );
		$flags    = '';

		// Sentence silence — per-post override wins over global setting.
		$silence = ( '' !== $sentence_silence ) ? $sentence_silence : ( $settings['piper_sentence_silence'] ?? '' );
		if ( '' !== $silence && is_numeric( $silence ) ) {
			$flags .= ' --sentence_silence ' . escapeshellarg( $silence );
		}

		// Length scale — per-post > global override > quality-based default.
		if ( '' !== $length_scale_override && is_numeric( $length_scale_override ) ) {
			$flags .= ' --length-scale ' . escapeshellarg( $length_scale_override );
		} else {
			$scale = $settings['piper_length_scale'] ?? '';
			if ( '' !== $scale && is_numeric( $scale ) ) {
				$flags .= ' --length-scale ' . escapeshellarg( $scale );
			} else {
				$flags .= ' --length-scale ' . escapeshellarg( (string) $this->length_scale( $quality ) );
			}
		}

		return $flags;
	}

	/**
	 * Get the configured Piper binary path.
	 *
	 * @return string
	 */
	private function get_binary(): string {
		$settings = get_option( 'piperless_settings', [] );
		return $settings['piper_binary'] ?? '';
	}

	/**
	 * Map quality string to Piper's --length-scale value.
	 *
	 * Lower = faster/lower quality, higher = slower/higher quality.
	 *
	 * @param string $quality low|medium|high.
	 * @return float
	 */
	private function length_scale( string $quality ): float {
		return match ( $quality ) {
			'low'    => 0.6,
			'high'   => 1.2,
			default  => 1.0, // medium
		};
	}

	/**
	 * Try to determine sample rate from the model's companion .json file.
	 *
	 * @param string $model_path Path to .onnx model.
	 * @return int Sample rate (default 22050).
	 */
	private function get_model_sample_rate( string $model_path ): int {
		$json_path = $model_path . '.json';

		if ( ! file_exists( $json_path ) ) {
			return 22050;
		}

		$json = @file_get_contents( $json_path );
		if ( false === $json ) {
			return 22050;
		}

		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return 22050;
		}

		// Piper model JSON typically has "audio" → "sample_rate".
		return (int) ( $data['audio']['sample_rate'] ?? $data['sample_rate'] ?? 22050 );
	}

	/**
	 * Wrap a raw PCM temp file in a WAV container.
	 *
	 * Reads the raw PCM from disk only once — avoids keeping 25+ MB
	 * in memory alongside the WAV output during synthesis.
	 *
	 * @param string $raw_path    Path to raw PCM temp file.
	 * @param int    $sample_rate Sample rate in Hz.
	 * @return string|null WAV file data, or null if file is empty.
	 */
	private function raw_file_to_wav( string $raw_path, int $sample_rate = 22050 ): ?string {
		$raw_size = filesize( $raw_path );
		if ( 0 === $raw_size ) {
			return null;
		}

		$num_channels    = 1;
		$bits_per_sample = 16;
		$byte_rate       = $sample_rate * $num_channels * ( $bits_per_sample / 8 );
		$block_align     = $num_channels * ( $bits_per_sample / 8 );

		$header = pack(
			'A4VA4A4VvvVVvvA4V',
			'RIFF',
			36 + $raw_size,
			'WAVE',
			'fmt ',
			16,
			1,              // PCM
			$num_channels,
			$sample_rate,
			$byte_rate,
			$block_align,
			$bits_per_sample,
			'data',
			$raw_size
		);

		$raw = @file_get_contents( $raw_path );
		return ( false === $raw ) ? null : ( $header . $raw );
	}

	/**
	 * Wrap raw 16-bit mono PCM in a WAV container.
	 *
	 * @param string $raw         Raw PCM audio data.
	 * @param int    $sample_rate Sample rate in Hz.
	 * @return string WAV file data.
	 */
	private function raw_to_wav( string $raw, int $sample_rate = 22050 ): string {
		$num_channels   = 1;
		$bits_per_sample = 16;
		$byte_rate      = $sample_rate * $num_channels * ( $bits_per_sample / 8 );
		$block_align    = $num_channels * ( $bits_per_sample / 8 );
		$data_size      = strlen( $raw );

		$header = pack(
			'A4VA4A4VvvVVvvA4V',
			'RIFF',
			36 + $data_size,
			'WAVE',
			'fmt ',
			16,             // PCM
			1,              // format = PCM
			$num_channels,
			$sample_rate,
			$byte_rate,
			$block_align,
			$bits_per_sample,
			'data',
			$data_size
		);

		return $header . $raw;
	}
}
