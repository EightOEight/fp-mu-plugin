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

use FrankenPress\Cli\Adapters\AdapterInterface;
use FrankenPress\Cli\Adapters\The7;
use FrankenPress\Cli\Apply\Restorer;
use FrankenPress\Cli\Snapshot\Capturer;
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

		$wp_runner  = static function ( string $command, array $assoc ): mixed {
			return \WP_CLI::runcommand(
				$command,
				array(
					'return'     => 'all',
					// launch=true → spawn a subprocess per inner wp-cli
					// invocation. Required because some wp-cli commands
					// (notably `wp export`) call exit() internally; with
					// launch=false they'd terminate the outer wp fp
					// process silently mid-flight, leaving partial output
					// + no diagnostic. Subprocess isolation is the
					// safety boundary.
					'launch'     => true,
					'exit_error' => false,
				) + $assoc
			);
		};
		$sql_runner = static function ( string $sql ): array {
			global $wpdb;
			// SQL is composed by the snapshot capturers from adapter-
			// declared patterns (e.g. `the7_%`) — not from user input
			// — so $wpdb->prepare() placeholders don't apply here. The
			// capturers escape values defensively before composing.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $sql, ARRAY_A );
			return is_array( $rows ) ? $rows : array();
		};
		$option_get = static fn ( string $key ): mixed => get_option( $key );

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
			$this->adapters(),
			new \FrankenPress\Cli\Snapshot\WxrCapturer( $wp_runner, $sql_runner ),
			new \FrankenPress\Cli\Snapshot\OptionsCapturer( $sql_runner, $option_get ),
		);

		try {
			$manifest_path = $capturer->capture();
		} catch ( Throwable $e ) {
			\WP_CLI::error( 'snapshot failed: ' . $e->getMessage() );
			return;
		}

		\WP_CLI::log( "snapshot written: {$manifest_path}" );
		\WP_CLI::log( 'review the manifest + composer-patch.json, then commit web/imports/' . $slug . '/ and open a site-repo PR.' );
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
			static function ( string $stylesheet, string $key, mixed $value ): void {
				// set_theme_mod operates on the CURRENT theme. To set
				// mods for an arbitrary stylesheet, write directly to
				// the option_name `theme_mods_<stylesheet>`. WP reads
				// theme mods from there, so this is the canonical write.
				$key_name = 'theme_mods_' . $stylesheet;
				$current  = get_option( $key_name );
				if ( ! is_array( $current ) ) {
					$current = array();
				}
				$current[ $key ] = $value;
				update_option( $key_name, $current, true );
			},
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
	 * Default output dir: <site-root>/web/imports/<safe-slug>/.
	 *
	 * Image-baked snapshots are versioned alongside the rest of the
	 * site code in git. The site Dockerfile COPYs `web/imports/` into
	 * the runtime image; the chart's install Job iterates the dir and
	 * runs `wp fp apply` per snapshot subdir.
	 *
	 * No timestamp suffix on the dir — same slug = same destination,
	 * re-running `wp fp snapshot --slug=X` cleanly overwrites. That's
	 * what designers want for iterating ("I changed the demo, re-snap
	 * and re-commit"); the snapshot ID inside manifest.json carries
	 * the timestamp for traceability.
	 *
	 * @param AssocArgs $assoc_args
	 */
	private function resolve_output_dir( array $assoc_args, string $slug ): string {
		$override = $assoc_args['output-dir'] ?? null;
		if ( null !== $override && '' !== $override ) {
			return (string) $override;
		}
		// Bedrock layout: ABSPATH = <site-root>/web/wp/. We want
		// <site-root>/web/imports/<slug>/. dirname(..., 2) climbs from
		// /app/web/wp to /app (site root) cleanly; the older
		// preg_replace('#/wp$#') trick stripped only one segment and
		// produced /app/web/web/imports (the "web" appears twice).
		$abspath = defined( 'ABSPATH' ) ? rtrim( (string) constant( 'ABSPATH' ), '/' ) : '';
		if ( '' !== $abspath && '/web/wp' === substr( $abspath, -7 ) ) {
			$root = dirname( $abspath, 2 );
		} elseif ( '' !== $abspath ) {
			$root = dirname( $abspath );
		} else {
			$root = (string) getcwd();
		}
		$safe = strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $slug ) ?? 'snapshot' );
		$safe = trim( $safe, '-' );
		if ( '' === $safe ) {
			$safe = 'snapshot';
		}
		return rtrim( $root, '/' ) . "/web/imports/{$safe}";
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
	 * Registered premium-theme adapters. v0.8.0 hard-codes The7;
	 * Phase 4 swaps this for a registry pattern so other components
	 * (or even site repos themselves) can contribute adapters.
	 *
	 * @return array<int, AdapterInterface>
	 */
	private function adapters(): array {
		return array( new The7() );
	}
}
