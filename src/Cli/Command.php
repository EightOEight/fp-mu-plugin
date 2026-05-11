<?php
/**
 * `wp fp ...` WP-CLI command surface.
 *
 * Two subcommands in Phase 0 of the FrankenPress promotion CLI:
 *
 *   wp fp snapshot --slug=<id> [--note=<text>] [--output-dir=<path>]
 *     Capture local site state to a snapshot directory (manifest +
 *     sanitised DB dump + composer-patch + uploads-manifest). Run on
 *     the designer's docker-compose stack.
 *
 *   wp fp apply --snapshot-dir=<path>
 *     Apply a snapshot directory to the current site. Imports the DB,
 *     retargets URLs, fires adapter post_restore hooks, stamps
 *     idempotency markers. Run inside the chart's post-install Helm
 *     hook Job (extended in `charts/site/templates/job-install.yaml`)
 *     OR on a designer's local stack to round-trip iterate.
 *
 * Thin orchestrator over {@see \FrankenPress\Cli\Snapshot\Capturer}
 * and {@see \FrankenPress\Cli\Apply\Restorer}; no business logic
 * lives in this file beyond arg parsing, WP-state collection, and
 * status reporting.
 *
 * Registered by {@see \FrankenPress\MuPlugin::bootstrap()} only when
 * WP_CLI is defined, so this class adds zero overhead on web requests.
 *
 * @package FrankenPress\Cli
 */

declare(strict_types=1);

namespace FrankenPress\Cli;

use FrankenPress\Cli\Adapters\The7;
use FrankenPress\Cli\Apply\Restorer;
use FrankenPress\Cli\Snapshot\Capturer;
use FrankenPress\Cli\Snapshot\Sanitiser;
use Throwable;

/**
 * @phpstan-type AssocArgs array<string, string|bool|null>
 */
final class Command {

	/**
	 * Capture local site state to a snapshot directory.
	 *
	 * ## OPTIONS
	 *
	 * --slug=<slug>
	 * : Short identifier for this snapshot (lowercase + hyphens, e.g. "architect-2").
	 *
	 * [--note=<text>]
	 * : Optional designer note embedded in the manifest.
	 *
	 * [--output-dir=<path>]
	 * : Where to write the snapshot directory. Defaults to
	 *   `<site-root>/fp-snapshots/<slug>-<timestamp>` inside the WP
	 *   root. The directory is created if missing and emptied if
	 *   non-empty (this command owns its contents).
	 *
	 * ## EXAMPLES
	 *
	 *     wp fp snapshot --slug=architect-2 --note="The7 FSE Architect demo + accent colour tweak"
	 *
	 * @param array<int, string> $args        Positional args (unused).
	 * @param AssocArgs          $assoc_args  Flag values.
	 */
	public function snapshot( array $args, array $assoc_args ): void {
		$slug       = $this->require_assoc( $assoc_args, 'slug' );
		$note       = (string) ( $assoc_args['note'] ?? '' );
		$output_dir = $this->resolve_output_dir( $assoc_args, $slug );

		$capturer = new Capturer(
			$output_dir,
			$slug,
			$note,
			defined( 'WP_PLUGIN_DIR' ) ? (string) constant( 'WP_PLUGIN_DIR' ) : '',
			$this->uploads_dir(),
			$this->composer_json_path(),
			(array) get_option( 'active_plugins', array() ),
			(string) home_url(),
			$this->wp_version_safe(),
			(string) get_option( 'stylesheet', '' ),
			new Sanitiser(),
			$this->adapters(),
			function ( $fh ): void {
				$this->run_wp_db_export( $fh );
			}
		);

		try {
			$manifest_path = $capturer->capture();
		} catch ( Throwable $e ) {
			\WP_CLI::error( 'snapshot failed: ' . $e->getMessage() );
			return;
		}

		\WP_CLI::log( "snapshot written: {$manifest_path}" );
		\WP_CLI::log( "review the manifest + composer-patch.json, then run:  make promote SLUG={$slug} ENV=stg" );
		\WP_CLI::success( 'snapshot complete' );
	}

	/**
	 * Apply a snapshot to the current site.
	 *
	 * ## OPTIONS
	 *
	 * --snapshot-dir=<path>
	 * : Local directory containing manifest.json + db.sql.gz +
	 *   composer-patch.json + uploads-manifest.txt. The chart's
	 *   post-install Job downloads the snapshot bundle from S3 into a
	 *   tmp dir before invoking this command.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fp apply --snapshot-dir=/tmp/fp-snapshot-architect-2-20260511-091422
	 *
	 * @param array<int, string> $args
	 * @param AssocArgs          $assoc_args
	 */
	public function apply( array $args, array $assoc_args ): void {
		$snapshot_dir = $this->require_assoc( $assoc_args, 'snapshot-dir' );
		if ( ! is_dir( $snapshot_dir ) ) {
			\WP_CLI::error( "snapshot-dir does not exist: {$snapshot_dir}" );
			return;
		}

		$restorer = new Restorer(
			$snapshot_dir,
			(string) home_url(),
			$this->adapters(),
			static function ( string $command, array $assoc ): mixed {
				return \WP_CLI::runcommand(
					$command,
					array(
						'return' => 'all',
						'launch' => false,
					) + $assoc
				);
			},
			static fn ( string $key ): mixed => get_option( $key, null ),
			static fn ( string $key, mixed $value, bool $autoload ): bool => update_option( $key, $value, $autoload ),
		);

		try {
			$result = $restorer->apply();
		} catch ( Throwable $e ) {
			\WP_CLI::error( 'apply failed: ' . $e->getMessage() );
			return;
		}

		if ( 'skipped' === $result ) {
			\WP_CLI::log( 'snapshot already applied (idempotency markers matched); no-op' );
			\WP_CLI::success( 'apply skipped' );
			return;
		}
		\WP_CLI::success( 'apply complete' );
	}

	/**
	 * @param AssocArgs $assoc_args
	 */
	private function require_assoc( array $assoc_args, string $name ): string {
		$value = $assoc_args[ $name ] ?? null;
		if ( null === $value || '' === $value ) {
			\WP_CLI::error( "missing required flag --{$name}" );
		}
		return (string) $value;
	}

	/**
	 * @param AssocArgs $assoc_args
	 */
	private function resolve_output_dir( array $assoc_args, string $slug ): string {
		$override = $assoc_args['output-dir'] ?? null;
		if ( null !== $override && '' !== $override ) {
			return (string) $override;
		}
		$root         = defined( 'ABSPATH' ) ? rtrim( (string) constant( 'ABSPATH' ), '/' ) : getcwd();
		$root         = rtrim( $root, '/' );
		$root_trimmed = preg_replace( '#/wp$#', '', $root );
		if ( null !== $root_trimmed && '' !== $root_trimmed ) {
			$root = $root_trimmed;
		}
		$stamp = gmdate( 'Ymd-His' );
		$safe  = strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $slug ) ?? 'snapshot' );
		return rtrim( $root, '/' ) . "/fp-snapshots/{$safe}-{$stamp}";
	}

	private function uploads_dir(): string {
		if ( function_exists( 'wp_get_upload_dir' ) ) {
			$dirs = wp_get_upload_dir();
			if ( is_array( $dirs ) && isset( $dirs['basedir'] ) ) {
				return (string) $dirs['basedir'];
			}
		}
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			return rtrim( (string) constant( 'WP_CONTENT_DIR' ), '/' ) . '/uploads';
		}
		return '';
	}

	private function composer_json_path(): string {
		// Bedrock layout: <site-root>/composer.json. ABSPATH points at
		// <site-root>/web/wp/ in Bedrock; the composer.json lives two
		// levels up.
		$candidates = array(
			defined( 'ABSPATH' )
				? dirname( rtrim( (string) constant( 'ABSPATH' ), '/' ), 2 ) . '/composer.json'
				: '',
			getcwd() . '/composer.json',
			'/app/composer.json',
		);
		foreach ( $candidates as $path ) {
			if ( '' !== $path && is_file( $path ) ) {
				return $path;
			}
		}
		return $candidates[0];
	}

	private function wp_version_safe(): string {
		global $wp_version;
		return isset( $wp_version ) ? (string) $wp_version : '';
	}

	/**
	 * Phase-0 adapter list: just The7. Phase 4 reads adapters from a
	 * registry populated by other components / plugins.
	 *
	 * @return array<int, The7>
	 */
	private function adapters(): array {
		return array( new The7() );
	}

	/**
	 * Run `wp db export -` against the loaded site, writing raw SQL to
	 * the given stream handle. Uses `--extended-insert=0` so the
	 * sanitiser can operate line-by-line.
	 *
	 * @param resource $stream
	 */
	private function run_wp_db_export( $stream ): void {
		// WP_CLI::runcommand can't pipe to an arbitrary fd, so we shell
		// out via proc_open. wp-cli is at a stable path in the runtime.
		$wp_bin = $this->wp_cli_binary();
		$descs  = array(
			0 => array( 'pipe', 'r' ),
			1 => $stream,
			2 => array( 'pipe', 'w' ),
		);
		$proc   = proc_open(
			array(
				$wp_bin,
				'--allow-root',
				'--path=' . ( defined( 'ABSPATH' ) ? rtrim( (string) constant( 'ABSPATH' ), '/' ) : '' ),
				'db',
				'export',
				'-',
				'--extended-insert=0',
				'--add-drop-table',
				'--skip-themes',
				'--skip-plugins',
			),
			$descs,
			$pipes
		);
		if ( ! is_resource( $proc ) ) {
			throw new \RuntimeException( 'could not exec wp db export' );
		}
		fclose( $pipes[0] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[2] );
		$status = proc_close( $proc );
		if ( 0 !== $status ) {
			throw new \RuntimeException( 'wp db export exited ' . $status . ': ' . trim( (string) $stderr ) );
		}
	}

	private function wp_cli_binary(): string {
		foreach ( array( '/usr/local/bin/wp', '/usr/bin/wp', 'wp' ) as $cand ) {
			$resolved = trim( (string) shell_exec( "command -v {$cand} 2>/dev/null" ) );
			if ( '' !== $resolved ) {
				return $resolved;
			}
		}
		return 'wp';
	}
}
