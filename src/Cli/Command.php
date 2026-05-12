<?php
/**
 * `wp fp ...` WP-CLI command surface.
 *
 * Two subcommands in Phase 0 of the FrankenPress promotion CLI:
 *
 *   wp fp snapshot --slug=<id> [--note=<text>] [--output-dir=<path>]
 *     Capture local site state to a snapshot directory (manifest +
 *     scoped WXR + options sidecar + uploads-manifest audit log). Run
 *     on the designer's docker-compose stack.
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
use FrankenPress\Cli\Adapters\Fse;
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
	 *     wp fp snapshot --slug=homepage-rev2 --note="Block-pattern refresh + accent colour tweak"
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
			// SQL is composed by WxrCapturer from adapter-declared
			// post-type names — not from user input — so $wpdb->prepare()
			// placeholders don't apply here. The capturer escapes values
			// defensively before composing.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $sql, ARRAY_A );
			return is_array( $rows ) ? $rows : array();
		};
		$option_get = static fn ( string $key ): mixed => get_option( $key );

		$active_stylesheet = (string) get_option( 'stylesheet', '' );
		$meta_reader       = static fn ( int $post_id, string $key ): mixed => get_post_meta( $post_id, $key, true );

		$post_loader   = static fn ( int $id ): ?object => get_post( $id );
		$blocks_parser = static fn ( string $content ): array => function_exists( 'parse_blocks' ) ? parse_blocks( $content ) : array();

		$capturer = new Capturer(
			$output_dir,
			$slug,
			$note,
			$this->uploads_dir(),
			(string) home_url(),
			$this->wp_version_safe(),
			$active_stylesheet,
			$this->adapters(),
			new \FrankenPress\Cli\Snapshot\WxrCapturer( $wp_runner, $sql_runner ),
			new \FrankenPress\Cli\Snapshot\OwnedPostsCapturer( $sql_runner, $meta_reader, $active_stylesheet ),
			new \FrankenPress\Cli\Snapshot\OptionsCapturer( $option_get ),
			new \FrankenPress\Cli\Snapshot\AttachmentRefCapturer( $option_get, $post_loader, $meta_reader, $blocks_parser, $this->uploads_dir() ),
		);

		try {
			$manifest_path = $capturer->capture();
		} catch ( Throwable $e ) {
			\WP_CLI::error( 'snapshot failed: ' . $e->getMessage() );
			return;
		}

		\WP_CLI::log( "snapshot written: {$manifest_path}" );
		\WP_CLI::log( 'review the manifest + options.json, then commit web/imports/' . $slug . '/ and open a site-repo PR.' );
		\WP_CLI::success( 'snapshot complete' );
	}

	/**
	 * Apply a snapshot to the current site.
	 *
	 * ## OPTIONS
	 *
	 * --snapshot-dir=<path>
	 * : Local directory containing manifest.json + content.xml.gz +
	 *   options.json + uploads-manifest.txt. The chart's install Job
	 *   iterates `web/imports/<slug>/` directories baked into the site
	 *   image and invokes this command per snapshot.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fp apply --snapshot-dir=/app/web/imports/homepage-rev2
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
				// exit_error=false → if the inner wp-cli command fails,
				// runcommand returns the result object (with stderr +
				// non-zero return_code) instead of calling WP_CLI::halt(1)
				// and killing the outer process silently. Without this,
				// a failure like "WP-Importer not installed" produces a
				// blank exit-1 from `wp fp apply` with zero diagnostic.
				$result = \WP_CLI::runcommand(
					$command,
					array(
						'return'     => 'all',
						'launch'     => false,
						'exit_error' => false,
					) + $assoc
				);
				if ( is_object( $result ) && isset( $result->return_code ) && 0 !== (int) $result->return_code ) {
					$stderr = isset( $result->stderr ) ? (string) $result->stderr : '';
					$stdout = isset( $result->stdout ) ? (string) $result->stdout : '';
					$msg    = trim( $stderr ) !== '' ? trim( $stderr ) : trim( $stdout );
					throw new \RuntimeException(
						sprintf(
							'inner wp-cli command "%s" exited %d: %s',
							$command,
							(int) $result->return_code,
							'' !== $msg ? $msg : '(no output)'
						)
					);
				}
				return $result;
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
			// owned_finder: look up existing post by slug+post_type.
			// Returns ID or null. Use suppress_filters so any installed
			// query filters don't hide rows we own.
			static function ( string $post_type, string $slug ): ?int {
				$found = get_posts(
					array(
						'post_type'        => $post_type,
						'name'             => $slug,
						'post_status'      => 'any',
						'numberposts'      => 1,
						'fields'           => 'ids',
						'suppress_filters' => true,
					)
				);
				if ( ! is_array( $found ) || empty( $found ) ) {
					return null;
				}
				return (int) $found[0];
			},
			// owned_updater: wp_update_post the snapshot's fields, then
			// write each meta key.
			static function ( int $post_id, array $fields, array $meta ): void {
				wp_update_post( array( 'ID' => $post_id ) + $fields );
				foreach ( $meta as $k => $v ) {
					update_post_meta( $post_id, (string) $k, $v );
				}
			},
			// owned_inserter: wp_insert_post with the snapshot's fields
			// + slug + post_type, then write each meta key. Returns the
			// new post ID for caller bookkeeping.
			static function ( string $post_type, string $slug, array $fields, array $meta ): int {
				$id = wp_insert_post(
					array(
						'post_type' => $post_type,
						'post_name' => $slug,
					) + $fields
				);
				if ( is_wp_error( $id ) || ! is_int( $id ) || $id <= 0 ) {
					throw new \RuntimeException(
						sprintf( 'apply: wp_insert_post for %s/%s failed', $post_type, $slug )
					);
				}
				foreach ( $meta as $k => $v ) {
					update_post_meta( $id, (string) $k, $v );
				}
				return $id;
			},
			// att_finder: look up an existing attachment post by
			// `_wp_attached_file` postmeta. That's the stable key
			// across local → stg → prd (the source filename is
			// identical, only the attachment post ID varies).
			static function ( string $relative_file ): ?int {
				$found = get_posts(
					array(
						'post_type'        => 'attachment',
						'post_status'      => 'any',
						'numberposts'      => 1,
						'fields'           => 'ids',
						'suppress_filters' => true,
						'meta_query'       => array(
							array(
								'key'   => '_wp_attached_file',
								'value' => $relative_file,
							),
						),
					)
				);
				if ( ! is_array( $found ) || empty( $found ) ) {
					return null;
				}
				return (int) $found[0];
			},
			// att_updater: wp_update_post + meta keys (attachment posts
			// follow the same update pattern as owned posts).
			static function ( int $post_id, array $fields, array $meta ): void {
				wp_update_post( array( 'ID' => $post_id ) + $fields );
				foreach ( $meta as $k => $v ) {
					update_post_meta( $post_id, (string) $k, $v );
				}
			},
			// att_inserter: wp_insert_post with post_type=attachment,
			// then write the meta keys. Returns the new local post ID
			// so the apply path can remap option values.
			static function ( array $fields, array $meta ): int {
				$id = wp_insert_post(
					array( 'post_type' => 'attachment' ) + $fields
				);
				if ( is_wp_error( $id ) || ! is_int( $id ) || $id <= 0 ) {
					throw new \RuntimeException( 'apply: wp_insert_post for attachment failed' );
				}
				foreach ( $meta as $k => $v ) {
					update_post_meta( $id, (string) $k, $v );
				}
				return $id;
			},
			// uploads_basedir: the canonical wp_upload_dir basedir.
			// When S3UploadsBootstrap is active this is an `s3://` stream
			// wrapper path, so `copy()` into this directory transparently
			// writes to S3.
			(string) ( wp_get_upload_dir()['basedir'] ?? $this->uploads_dir() ),
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

	private function wp_version_safe(): string {
		global $wp_version;
		return isset( $wp_version ) ? (string) $wp_version : '';
	}

	/**
	 * Registered snapshot adapters. v0.10.0 hard-codes Fse; a future
	 * phase swaps this for a registry pattern so other components (or
	 * even site repos themselves) can contribute adapters.
	 *
	 * @return array<int, AdapterInterface>
	 */
	private function adapters(): array {
		return array( new Fse() );
	}
}
