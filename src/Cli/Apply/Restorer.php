<?php
/**
 * Snapshot restorer — orchestrates `wp fp apply` in fp.snapshot/v2.
 *
 * Inverse of {@see \FrankenPress\Cli\Snapshot\Capturer}. Reads a
 * snapshot directory, verifies its integrity, imports the WXR via
 * WP-Importer, applies the scoped options sidecar, fires adapter
 * post_apply() hooks, and stamps idempotency markers.
 *
 * Why WP-Importer (rather than `wp db import`):
 *
 *   - WXR is additive — only INSERTs new posts; skips existing terms
 *     by slug; remaps post IDs on collision and fixes up postmeta
 *     references automatically.
 *   - NO DROPs, NO DELETEs, NO TRUNCATES anywhere in the apply path.
 *   - Tables outside the snapshot scope (wc_orders, wp_users,
 *     wp_comments) cannot be touched — they're literally not in the
 *     WXR file, and the options sidecar only touches option_names
 *     declared in scope.
 *
 * Idempotency boundary:
 *
 *   wp_options.fp_snapshot_applied_ref     === manifest.id
 *   wp_options.fp_snapshot_applied_sha256  === manifest.contents.wxr_sha256
 *
 * Both match → skipped. Either differs → re-runs the full apply.
 *
 * @package FrankenPress\Cli\Apply
 */

declare(strict_types=1);

namespace FrankenPress\Cli\Apply;

use FrankenPress\Cli\Adapters\AdapterInterface;
use RuntimeException;

final class Restorer {

	public const APPLIED_REF_OPTION    = 'fp_snapshot_applied_ref';
	public const APPLIED_SHA256_OPTION = 'fp_snapshot_applied_sha256';

	/**
	 * @param string                       $snapshot_dir   Local directory with manifest.json + content.xml.gz + options.json.
	 * @param string                       $target_url     home_url() at apply time.
	 * @param array<int, AdapterInterface> $adapters       Registered adapters (must match manifest.adapters_fired by name).
	 * @param callable                     $wp_runner      fn(string $cmd, array $assoc): mixed — wraps WP_CLI::runcommand.
	 * @param callable                     $option_reader  fn(string $key): mixed.
	 * @param callable                     $option_writer  fn(string $key, mixed $value, bool $autoload): bool.
	 * @param callable                     $theme_mod_set  fn(string $stylesheet, string $key, mixed $value): void.
	 */
	public function __construct(
		private string $snapshot_dir,
		private string $target_url,
		private array $adapters,
		private $wp_runner,
		private $option_reader,
		private $option_writer,
		private $theme_mod_set,
	) {}

	/**
	 * Apply the snapshot. Returns "applied" or "skipped".
	 *
	 * @throws RuntimeException on integrity / IO failure.
	 */
	public function apply(): string {
		$manifest = $this->read_manifest();
		$id       = (string) ( $manifest['id'] ?? '' );

		$expected_sha = (string) ( $manifest['contents']['wxr_sha256'] ?? '' );
		if ( '' === $id || '' === $expected_sha ) {
			throw new RuntimeException( 'manifest is missing required fields (id, contents.wxr_sha256)' );
		}

		$schema = (string) ( $manifest['schema'] ?? '' );
		if ( 'fp.snapshot/v2' !== $schema ) {
			throw new RuntimeException( "manifest schema {$schema} is not supported by this fp build (accepts fp.snapshot/v2)" );
		}

		if ( $this->already_applied( $id, $expected_sha ) ) {
			return 'skipped';
		}

		$wxr_path = $this->snapshot_dir . '/content.xml.gz';
		$this->verify_blob( $wxr_path, $expected_sha );

		// Stage 1: import the WXR.
		$this->import_wxr( $wxr_path );

		// Stage 2: apply the scoped options.
		$this->apply_options();

		// Stage 3: search-replace URLs in the imported content.
		$source_url = (string) ( $manifest['source']['site_url'] ?? '' );
		if ( '' !== $source_url && $source_url !== $this->target_url ) {
			$this->retarget_urls( $source_url, $this->target_url );
		}

		// Stage 4: adapter post_apply hooks.
		$this->fire_adapters(
			(array) ( $manifest['adapters_fired'] ?? array() ),
			(array) ( $manifest['adapter_state'] ?? array() )
		);

		// Stage 5: idempotency markers.
		( $this->option_writer )( self::APPLIED_REF_OPTION, $id, true );
		( $this->option_writer )( self::APPLIED_SHA256_OPTION, $expected_sha, true );

		return 'applied';
	}

	/**
	 * @return array<string, mixed>
	 */
	private function read_manifest(): array {
		$path = $this->snapshot_dir . '/manifest.json';
		if ( ! is_file( $path ) ) {
			throw new RuntimeException( "manifest.json not found in {$this->snapshot_dir}" );
		}
		$raw = file_get_contents( $path );
		if ( false === $raw || '' === $raw ) {
			throw new RuntimeException( "manifest.json at {$path} is empty or unreadable" );
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			throw new RuntimeException( "manifest.json at {$path} is not a JSON object" );
		}
		return $decoded;
	}

	private function already_applied( string $id, string $sha256 ): bool {
		$prior_ref = ( $this->option_reader )( self::APPLIED_REF_OPTION );
		$prior_sha = ( $this->option_reader )( self::APPLIED_SHA256_OPTION );
		return $id === $prior_ref && $sha256 === $prior_sha;
	}

	private function verify_blob( string $path, string $expected_sha ): void {
		if ( ! is_file( $path ) ) {
			throw new RuntimeException( "content.xml.gz not found at {$path}" );
		}
		$actual = hash_file( 'sha256', $path );
		if ( $actual !== $expected_sha ) {
			throw new RuntimeException(
				"content.xml.gz integrity check failed: manifest says {$expected_sha}, actual {$actual}"
			);
		}
	}

	/**
	 * Run the WP-Importer against content.xml.gz. The importer plugin
	 * is the canonical WordPress mechanism for ingesting WXR; it
	 * handles ID remapping, term dedup, attachment refs, etc.
	 *
	 * WP-Importer lifecycle: managed transparently by ensure_wxr_importer()
	 * and torn down in finally. Three modes the apply path navigates:
	 *
	 *   borrowed  — plugin already in active_plugins when we started.
	 *               Don't touch it on teardown. The site is using it.
	 *   activated — plugin was installed (file on disk) but inactive.
	 *               We activated it. Leave it active on teardown (the
	 *               file persists, the site can keep using it).
	 *   installed — plugin wasn't on disk. We installed AND activated
	 *               it. The file is in the install Job's overlay FS
	 *               only — gone when the pod GC's. We MUST deactivate
	 *               on teardown to keep wp_options.active_plugins
	 *               consistent with the next web Pod's disk state.
	 *
	 * `--authors=skip` because designer-side WXR exports include the
	 * designer's user as the post_author; we don't want to ship user
	 * accounts. `wp post` calls preserve existing post authors if the
	 * referenced user doesn't exist.
	 */
	private function import_wxr( string $gz_path ): void {
		$mode = $this->ensure_wxr_importer();

		// Decompress to a tmp .xml file. wp import expects a path.
		$tmp = tempnam( sys_get_temp_dir(), 'fp-wxr-' );
		if ( false === $tmp ) {
			$this->teardown_wxr_importer( $mode );
			throw new RuntimeException( 'could not create temp file for WXR' );
		}
		$xml_path = $tmp . '.xml';
		try {
			$gz = gzopen( $gz_path, 'rb' );
			if ( false === $gz ) {
				throw new RuntimeException( "could not gzopen {$gz_path}" );
			}
			$out = fopen( $xml_path, 'wb' );
			if ( false === $out ) {
				gzclose( $gz );
				throw new RuntimeException( "could not open {$xml_path}" );
			}
			while ( ! gzeof( $gz ) ) {
				$chunk = gzread( $gz, 64 * 1024 );
				if ( false === $chunk || '' === $chunk ) {
					break;
				}
				fwrite( $out, $chunk );
			}
			fclose( $out );
			gzclose( $gz );

			// Run `wp import` as a fresh subprocess (NOT via
			// WP_CLI::runcommand with `launch => false`).
			//
			// WP-Importer's main file early-returns unless
			// `WP_LOAD_IMPORTERS` is defined. wp-cli's `import` command
			// package defines that constant in its `load_import_class()`
			// helper at top-level dispatch — but `runcommand(..., launch
			// => false)` shares the parent process where wp-cli already
			// loaded all plugins (incl. WP-Importer) with `WP_LOAD_IMPORTERS`
			// UNDEFINED. Result: WP-Importer's main file short-circuited
			// on first load, `WP_Import` class never defined, and the
			// inner `import` command bails with "WordPress Importer
			// needs to be activated." PHP's `require_once` won't re-
			// evaluate the file no matter how late we define the
			// constant — once it's in `get_included_files()`, it's done.
			//
			// proc_open of `wp import ...` as a fresh subprocess
			// guarantees wp-cli's top-level dispatch path runs:
			// `load_import_class()` defines WP_LOAD_IMPORTERS, then
			// `require_once` the plugin file fresh — and this time the
			// file executes its body, defining WP_Import. The whole
			// class hierarchy comes up cleanly.
			$this->run_wp_import( $xml_path );
		} finally {
			if ( file_exists( $xml_path ) ) {
				unlink( $xml_path );
			}
			if ( file_exists( $tmp ) ) {
				unlink( $tmp );
			}
			$this->teardown_wxr_importer( $mode );
		}
	}

	/**
	 * Run `wp import <file> --authors=skip --skip=image_resize` as a
	 * fresh subprocess (not via WP_CLI::runcommand). See the comment
	 * block in import_wxr() for why this can't go through the shared
	 * wp_runner closure.
	 *
	 * Stderr is targeted at a regular file (same pattern as
	 * WxrCapturer::run_export) — wp import emits a progress line per
	 * post and `wp_runner`'s default in-process invocation deadlocks
	 * when the kernel pipe buffer fills.
	 */
	private function run_wp_import( string $xml_path ): void {
		$wp_bin     = $this->locate_wp_binary();
		$stderr_log = $xml_path . '.stderr.log';
		$cmd        = array(
			$wp_bin,
			'--allow-root',
			'--path=' . $this->wp_path(),
			'import',
			$xml_path,
			'--authors=skip',
			'--skip=image_resize',
		);
		$descs      = array(
			0 => array( 'file', '/dev/null', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'file', $stderr_log, 'w' ),
		);
		$proc       = proc_open( $cmd, $descs, $pipes );
		if ( ! is_resource( $proc ) ) {
			throw new RuntimeException( 'apply: proc_open(wp import) failed' );
		}
		// Drain stdout to keep the child unblocked. wp-cli's import
		// command writes a progress line per post; we don't need the
		// content, just need to keep reading so the pipe doesn't fill.
		while ( ! feof( $pipes[1] ) ) {
			$chunk = fread( $pipes[1], 64 * 1024 );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
		}
		fclose( $pipes[1] );
		$exit_code = proc_close( $proc );

		$stderr = is_file( $stderr_log ) ? (string) file_get_contents( $stderr_log ) : '';
		@unlink( $stderr_log );

		if ( 0 !== $exit_code ) {
			throw new RuntimeException(
				sprintf(
					'apply: wp import exited %d%s',
					$exit_code,
					'' !== trim( $stderr ) ? ' (stderr: ' . trim( $stderr ) . ')' : ''
				)
			);
		}
	}

	private function locate_wp_binary(): string {
		foreach ( array( '/usr/local/bin/wp', '/usr/bin/wp' ) as $candidate ) {
			if ( is_executable( $candidate ) ) {
				return $candidate;
			}
		}
		return 'wp';
	}

	private function wp_path(): string {
		if ( defined( 'ABSPATH' ) ) {
			return rtrim( (string) constant( 'ABSPATH' ), '/' );
		}
		return '';
	}

	/**
	 * Make sure WP-Importer is active before `wp import` runs.
	 *
	 *   borrowed  — already active, leave it alone
	 *   activated — installed but inactive → activate (option write only)
	 *   installed — neither installed nor active → install + activate
	 *
	 * `wp plugin install` requires (a) a writable filesystem and
	 * (b) `DISALLOW_FILE_MODS` not defined or false. Both are arranged
	 * by the chart's install Job: it runs with
	 * `readOnlyRootFilesystem: false` and writes a one-shot
	 * `/tmp/lockdown-override.php` (sourced via wp-cli `--require=`)
	 * that pre-defines the lockdown constants to `false` before
	 * wp-config.php loads. Web Pods (where the lockdown protects
	 * against supply-chain mutation) are unaffected.
	 *
	 * @return 'borrowed' | 'activated' | 'installed'
	 */
	private function ensure_wxr_importer(): string {
		$slug = 'wordpress-importer/wordpress-importer.php';

		$active_plugins = (array) ( ( $this->option_reader )( 'active_plugins' ) ?? array() );
		if ( in_array( $slug, $active_plugins, true ) ) {
			return 'borrowed';
		}

		$plugin_dir  = defined( 'WP_PLUGIN_DIR' ) ? (string) constant( 'WP_PLUGIN_DIR' ) : '';
		$plugin_file = '' !== $plugin_dir ? rtrim( $plugin_dir, '/' ) . '/' . $slug : '';
		$installed   = '' !== $plugin_file && is_file( $plugin_file );

		if ( $installed ) {
			// Disk has it, options doesn't — activate. Pure option-write,
			// no filesystem mutation, safe even on a RO root.
			( $this->wp_runner )(
				sprintf( 'plugin activate %s', escapeshellarg( 'wordpress-importer' ) ),
				array()
			);
			return 'activated';
		}

		// Not on disk — install + activate in one shot. Requires the
		// chart-side install Job relaxations described above. The plugin
		// file lives in the Job pod's writable overlay only.
		( $this->wp_runner )(
			sprintf( 'plugin install %s --activate', escapeshellarg( 'wordpress-importer' ) ),
			array()
		);
		return 'installed';
	}

	/**
	 * Reverse `ensure_wxr_importer()`'s side effects appropriately for
	 * the mode it returned. Only `installed` requires action: the plugin
	 * file is in the Job pod's ephemeral overlay, so we deactivate so
	 * the next web Pod doesn't try to load a file that's no longer on
	 * disk (`require_once` would fatal on each request).
	 */
	private function teardown_wxr_importer( string $mode ): void {
		if ( 'installed' !== $mode ) {
			return;
		}
		try {
			( $this->wp_runner )(
				sprintf( 'plugin deactivate %s', escapeshellarg( 'wordpress-importer' ) ),
				array()
			);
		} catch ( \Throwable $e ) {
			// Deactivation failure during teardown is loud-but-non-fatal:
			// the apply itself has either succeeded or already thrown
			// the real error. We don't want to mask that with a
			// secondary teardown exception. Best-effort.
			error_log( 'fp apply: WP-Importer teardown deactivate failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Apply the options.json sidecar — `update_option` for each entry,
	 * `set_theme_mod` for each theme_mods entry.
	 */
	private function apply_options(): void {
		$path = $this->snapshot_dir . '/options.json';
		if ( ! is_file( $path ) ) {
			return;
		}
		$raw = file_get_contents( $path );
		if ( false === $raw || '' === $raw ) {
			return;
		}
		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) ) {
			return;
		}

		$options = (array) ( $payload['options'] ?? array() );
		foreach ( $options as $key => $value ) {
			( $this->option_writer )( (string) $key, $value, true );
		}

		$theme_mods = (array) ( $payload['theme_mods'] ?? array() );
		foreach ( $theme_mods as $stylesheet => $mods ) {
			if ( ! is_array( $mods ) ) {
				continue;
			}
			foreach ( $mods as $key => $value ) {
				( $this->theme_mod_set )( (string) $stylesheet, (string) $key, $value );
			}
		}
	}

	private function retarget_urls( string $source_url, string $target_url ): void {
		( $this->wp_runner )(
			sprintf(
				'search-replace %s %s --all-tables --report-changed-only',
				escapeshellarg( $source_url ),
				escapeshellarg( $target_url )
			),
			array()
		);
	}

	/**
	 * @param array<int, string>                  $adapters_fired
	 * @param array<string, array<string, mixed>> $adapter_state
	 */
	private function fire_adapters( array $adapters_fired, array $adapter_state ): void {
		foreach ( $this->adapters as $adapter ) {
			$name = $adapter->name();
			if ( ! in_array( $name, $adapters_fired, true ) ) {
				continue;
			}
			$state = $adapter_state[ $name ] ?? array();
			$adapter->post_apply( $state );
		}
	}
}
