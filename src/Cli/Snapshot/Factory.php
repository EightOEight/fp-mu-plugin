<?php
/**
 * Builds a fully-wired {@see Capturer} for both the WP-CLI `wp fp
 * snapshot` command and the request-path {@see \FrankenPress\SnapshotExporter}
 * component. Two callers, one wiring — keeps the closure semantics
 * (notably `launch=true` on `WP_CLI::runcommand`) in one place.
 *
 * The Capturer's dependencies bottom out at WP globals (`$wpdb`,
 * `get_option`, `wp_get_object_terms`, ...) and `WP_CLI::runcommand`,
 * so the Factory itself is side-effect-free: callable is built at
 * `make()` time, evaluated lazily inside the Capturer when it actually
 * runs. The wp_runner uses `WP_CLI::runcommand` which is only valid
 * under a WP-CLI process — the SnapshotExporter dispatches via
 * `wp_schedule_single_event` so the actual capture always runs inside
 * the K8s wpcron CronJob's `wp cron event run` process where WP-CLI
 * is loaded.
 *
 * @package FrankenPress\Cli\Snapshot
 */

declare(strict_types=1);

namespace FrankenPress\Cli\Snapshot;

use FrankenPress\Cli\Adapters\AdapterInterface;
use FrankenPress\Cli\Adapters\Fse;

final class Factory {

	/**
	 * Build a Capturer wired against the running WP install.
	 *
	 * @param string $output_dir Destination directory for the snapshot artifacts.
	 * @param string $slug       Snapshot slug (timestamp or designer-chosen).
	 * @param string $note       Optional manifest note.
	 */
	public function make( string $output_dir, string $slug, string $note ): Capturer {
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
		$term_reader       = static function ( int $post_id, string $taxonomy ): array {
			if ( ! function_exists( 'wp_get_object_terms' ) ) {
				return array();
			}
			$slugs = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'slugs' ) );
			if ( ! is_array( $slugs ) ) {
				return array();
			}
			return array_values( array_filter( $slugs, 'is_string' ) );
		};

		$post_loader   = static fn ( int $id ): ?object => get_post( $id );
		$blocks_parser = static fn ( string $content ): array => function_exists( 'parse_blocks' ) ? parse_blocks( $content ) : array();
		$page_resolver = static function ( int $post_id ): ?array {
			$post = get_post( $post_id );
			if ( ! is_object( $post ) ) {
				return null;
			}
			$slug = isset( $post->post_name ) ? (string) $post->post_name : '';
			$type = isset( $post->post_type ) ? (string) $post->post_type : '';
			if ( '' === $slug || '' === $type ) {
				return null;
			}
			return array(
				'slug' => $slug,
				'type' => $type,
			);
		};

		return new Capturer(
			$output_dir,
			$slug,
			$note,
			$this->uploads_dir(),
			(string) home_url(),
			$this->wp_version_safe(),
			$active_stylesheet,
			$this->adapters(),
			new WxrCapturer( $wp_runner, $sql_runner ),
			new OwnedPostsCapturer( $sql_runner, $meta_reader, $term_reader, $active_stylesheet ),
			new OptionsCapturer( $option_get, $page_resolver ),
			new AttachmentRefCapturer( $option_get, $post_loader, $meta_reader, $blocks_parser, $this->uploads_dir() ),
			new NavigationBlockRefCapturer( $blocks_parser, $page_resolver ),
			new DriftLinter(
				$this->composer_packages_reader(),
				$this->active_state_reader(),
				$this->site_tracked_reader(),
			),
			$this->attachment_enumerator(),
		);
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

	/**
	 * @return callable(): array{plugins: array<int, string>, themes: array<int, string>}
	 */
	private function composer_packages_reader(): callable {
		$root = $this->project_root();
		return static function () use ( $root ): array {
			$path = $root . '/vendor/composer/installed.json';
			if ( '' === $root || ! is_file( $path ) ) {
				return array(
					'plugins' => array(),
					'themes'  => array(),
				);
			}
			$raw     = (string) file_get_contents( $path );
			$decoded = json_decode( $raw, true );
			$pkgs    = is_array( $decoded['packages'] ?? null ) ? (array) $decoded['packages'] : array();
			$plugins = array();
			$themes  = array();
			foreach ( $pkgs as $pkg ) {
				if ( ! is_array( $pkg ) ) {
					continue;
				}
				$name = (string) ( $pkg['name'] ?? '' );
				$type = (string) ( $pkg['type'] ?? '' );
				if ( '' === $name ) {
					continue;
				}
				$slug = $name;
				if ( false !== strpos( $name, '/' ) ) {
					$slug = substr( $name, (int) strpos( $name, '/' ) + 1 );
				}
				if ( 'wordpress-plugin' === $type ) {
					$plugins[] = $slug;
				} elseif ( 'wordpress-theme' === $type ) {
					$themes[] = $slug;
				}
			}
			return array(
				'plugins' => $plugins,
				'themes'  => $themes,
			);
		};
	}

	/**
	 * @return callable(): array{plugins: array<int, string>, theme: string}
	 */
	private function active_state_reader(): callable {
		return static function (): array {
			$raw     = function_exists( 'get_option' ) ? get_option( 'active_plugins', array() ) : array();
			$plugins = array();
			if ( is_array( $raw ) ) {
				foreach ( $raw as $path ) {
					$path = (string) $path;
					if ( '' === $path ) {
						continue;
					}
					$slug      = false !== strpos( $path, '/' )
						? substr( $path, 0, (int) strpos( $path, '/' ) )
						: $path;
					$plugins[] = $slug;
				}
			}
			$theme = function_exists( 'get_option' ) ? (string) get_option( 'stylesheet', '' ) : '';
			return array(
				'plugins' => $plugins,
				'theme'   => $theme,
			);
		};
	}

	/**
	 * @return callable(): array{plugins: array<int, string>, themes: array<int, string>}
	 */
	private function site_tracked_reader(): callable {
		$content_dir = defined( 'WP_CONTENT_DIR' ) ? rtrim( (string) constant( 'WP_CONTENT_DIR' ), '/' ) : '';
		return static function () use ( $content_dir ): array {
			$out = array(
				'plugins' => array(),
				'themes'  => array(),
			);
			if ( '' === $content_dir ) {
				return $out;
			}
			foreach ( array( 'plugins', 'themes' ) as $kind ) {
				$dir = $content_dir . '/' . $kind;
				if ( ! is_dir( $dir ) ) {
					continue;
				}
				$entries = scandir( $dir );
				if ( false === $entries ) {
					continue;
				}
				foreach ( $entries as $entry ) {
					if ( '.' === $entry || '..' === $entry ) {
						continue;
					}
					$path = $dir . '/' . $entry;
					if ( ! is_dir( $path ) ) {
						continue;
					}
					$out[ $kind ][] = $entry;
				}
			}
			return $out;
		};
	}

	/**
	 * @return callable(): array<int, string>
	 */
	private function attachment_enumerator(): callable {
		return static function (): array {
			global $wpdb;
			if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
				return array();
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'" );
			if ( ! is_array( $rows ) ) {
				return array();
			}
			$out = array();
			foreach ( $rows as $row ) {
				$row = (string) $row;
				if ( '' !== $row ) {
					$out[] = $row;
				}
			}
			return $out;
		};
	}

	private function project_root(): string {
		$abspath = defined( 'ABSPATH' ) ? rtrim( (string) constant( 'ABSPATH' ), '/' ) : '';
		if ( '' === $abspath ) {
			return '';
		}
		if ( '/web/wp' === substr( $abspath, -7 ) ) {
			return dirname( $abspath, 2 );
		}
		return dirname( $abspath );
	}
}
