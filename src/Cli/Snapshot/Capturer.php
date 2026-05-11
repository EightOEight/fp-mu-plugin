<?php
/**
 * Snapshot capturer — orchestrates `wp fp snapshot`.
 *
 * Reads local site state and writes a snapshot directory containing:
 *
 *   manifest.yaml          — fp.snapshot/v1 manifest (committed to git;
 *                            canonical form, cosign-signed in Phase 2)
 *   manifest.json          — same data in JSON. Apply-side reads this
 *                            so we don't need a PHP YAML parser in the
 *                            runtime image. Phase 1 Go binary uses YAML.
 *   composer-patch.json    — proposed `composer require` set (committed)
 *   db.sql.gz              — sanitised mariadb dump (blob; gitignored)
 *   uploads-manifest.txt   — list of files under wp-content/uploads/
 *                            with size + sha256 (committed in Phase 0;
 *                            Phase 2 replaces with S3 sync of the actual
 *                            bytes)
 *
 * Side effects: runs `wp db export -` against the loaded site, walks
 * the filesystem under WP_CONTENT_DIR/uploads, calls adapter
 * `capture()` methods. All file writes are confined to the output dir.
 *
 * Testability seam: the `db_exporter` constructor argument is a
 * callable that takes a stream pointer and writes sanitised SQL to it,
 * letting unit tests substitute a canned dump for the real
 * `wp db export -` invocation.
 *
 * @package FrankenPress\Cli\Snapshot
 */

declare(strict_types=1);

namespace FrankenPress\Cli\Snapshot;

use FrankenPress\Cli\Adapters\The7;
use RuntimeException;

final class Capturer {

	/**
	 * @param string                    $output_dir         Absolute path to where the snapshot directory will be written (created if missing; emptied if non-empty).
	 * @param string                    $slug               Designer-chosen short identifier (e.g. "architect-2").
	 * @param string                    $note               Free-form designer note (goes into manifest.notes).
	 * @param string                    $plugin_dir         WP_PLUGIN_DIR.
	 * @param string                    $uploads_dir        WP_CONTENT_DIR/uploads.
	 * @param string                    $composer_json_path Site composer.json path.
	 * @param array<int, string>        $active_plugins     active_plugins option value.
	 * @param string                    $site_url           home_url() at capture time.
	 * @param string                    $wp_version         WordPress version string.
	 * @param string                    $active_theme       Stylesheet slug.
	 * @param Sanitiser                 $sanitiser          SQL line sanitiser.
	 * @param array<int, The7>          $adapters           Premium-theme adapters (Phase 0: just [The7] when detect()ed).
	 * @param callable                  $db_exporter        Function ($fh): void — write raw SQL dump to $fh. Production caller passes a closure that invokes `wp db export - --extended-insert=0`.
	 */
	public function __construct(
		private string $output_dir,
		private string $slug,
		private string $note,
		private string $plugin_dir,
		private string $uploads_dir,
		private string $composer_json_path,
		private array $active_plugins,
		private string $site_url,
		private string $wp_version,
		private string $active_theme,
		private Sanitiser $sanitiser,
		private array $adapters,
		private $db_exporter,
	) {}

	/**
	 * Execute the capture. Returns the absolute path of the written
	 * manifest.yaml so callers can echo it / link to it.
	 */
	public function capture(): string {
		$this->prepare_output_dir();

		$db_path   = $this->output_dir . '/db.sql.gz';
		$db_sha256 = $this->write_sanitised_dump( $db_path );

		$plugins_patch = ( new PluginInspector(
			$this->plugin_dir,
			$this->composer_json_path,
			$this->active_plugins
		) )->build_patch();
		file_put_contents(
			$this->output_dir . '/composer-patch.json',
			json_encode( $plugins_patch, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
		);

		$uploads_manifest = $this->build_uploads_manifest();
		file_put_contents(
			$this->output_dir . '/uploads-manifest.txt',
			$uploads_manifest['text']
		);

		$adapter_state  = array();
		$adapters_fired = array();
		foreach ( $this->adapters as $adapter ) {
			if ( ! $adapter->detect() ) {
				continue;
			}
			$state = $adapter->capture();
			if ( ! empty( $state ) ) {
				$adapter_state[ $adapter::NAME ] = $state;
			}
			$adapters_fired[] = $adapter::NAME;
		}

		$manifest      = $this->build_manifest(
			$db_sha256,
			$uploads_manifest,
			$plugins_patch,
			$adapters_fired,
			$adapter_state
		);
		$manifest_path = $this->output_dir . '/manifest.yaml';
		file_put_contents( $manifest_path, $manifest->to_yaml() );
		file_put_contents(
			$this->output_dir . '/manifest.json',
			json_encode( $manifest->to_array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
		);

		return $manifest_path;
	}

	private function prepare_output_dir(): void {
		if ( ! is_dir( $this->output_dir ) ) {
			if ( ! mkdir( $this->output_dir, 0755, true ) && ! is_dir( $this->output_dir ) ) {
				throw new RuntimeException( "could not create output directory: {$this->output_dir}" );
			}
			return;
		}
		// Clear any prior snapshot artefacts in the same dir — we own
		// the dir contents.
		$entries = glob( $this->output_dir . '/*' );
		foreach ( ( false === $entries ? array() : $entries ) as $entry ) {
			if ( is_file( $entry ) ) {
				unlink( $entry );
			}
		}
	}

	/**
	 * Stream a raw SQL dump through the sanitiser into a gzipped file.
	 * Returns the sha256 of the compressed bytes — the manifest pins
	 * this so the apply side can verify integrity before importing.
	 */
	private function write_sanitised_dump( string $gz_path ): string {
		$raw_path = $this->output_dir . '/db.sql.raw';
		$fh       = fopen( $raw_path, 'wb' );
		if ( false === $fh ) {
			throw new RuntimeException( "could not open {$raw_path} for writing" );
		}

		try {
			( $this->db_exporter )( $fh );
		} finally {
			fclose( $fh );
		}

		// Stream the raw dump through the sanitiser into the gzipped output.
		$in  = fopen( $raw_path, 'rb' );
		$out = gzopen( $gz_path, 'wb9' );
		if ( false === $in || false === $out ) {
			throw new RuntimeException( 'sanitise stream pipeline could not open files' );
		}
		try {
			while ( ! feof( $in ) ) {
				$line = fgets( $in );
				if ( false === $line ) {
					break;
				}
				$rstripped = rtrim( $line, "\r\n" );
				$sanitised = $this->sanitiser->sanitise( $rstripped );
				if ( null === $sanitised ) {
					continue;
				}
				gzwrite( $out, $sanitised . "\n" );
			}
		} finally {
			fclose( $in );
			gzclose( $out );
		}
		unlink( $raw_path );

		return hash_file( 'sha256', $gz_path );
	}

	/**
	 * Walk the uploads dir and produce a manifest line per file:
	 *
	 *   <sha256>  <bytes>  <relative-path>
	 *
	 * Files under `the7-css/`, `s3-uploads-failures/`, `cache/` are
	 * excluded — they're env-specific caches that regenerate on first
	 * request in the apply env.
	 *
	 * @return array{text: string, file_count: int, total_bytes: int}
	 */
	private function build_uploads_manifest(): array {
		$text     = '';
		$count    = 0;
		$bytes    = 0;
		$excludes = array( '/the7-css/', '/s3-uploads-failures/', '/cache/' );

		if ( ! is_dir( $this->uploads_dir ) ) {
			return array(
				'text'        => '',
				'file_count'  => 0,
				'total_bytes' => 0,
			);
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->uploads_dir, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$rel = str_replace( $this->uploads_dir . '/', '', $file->getPathname() );
			foreach ( $excludes as $skip ) {
				if ( false !== strpos( '/' . $rel, $skip ) ) {
					continue 2;
				}
			}
			$sha   = hash_file( 'sha256', $file->getPathname() );
			$sz    = $file->getSize();
			$text .= sprintf( "%s  %d  %s\n", $sha, $sz, $rel );
			++$count;
			$bytes += $sz;
		}

		return array(
			'text'        => $text,
			'file_count'  => $count,
			'total_bytes' => $bytes,
		);
	}

	/**
	 * @param array{pending_requires: array<int, string>, unresolved: array<int, string>, rationale: string} $plugins_patch
	 * @param array{file_count: int, total_bytes: int} $uploads_manifest
	 * @param array<int, string> $adapters_fired
	 * @param array<string, array<string, mixed>> $adapter_state
	 */
	private function build_manifest(
		string $db_sha256,
		array $uploads_manifest,
		array $plugins_patch,
		array $adapters_fired,
		array $adapter_state
	): Manifest {
		$id      = $this->generate_id();
		$created = gmdate( 'Y-m-d\TH:i:s\Z' );

		$data = array(
			'schema'                 => Manifest::SCHEMA,
			'id'                     => $id,
			'created'                => $created,
			'source'                 => array(
				'site_url'     => $this->site_url,
				'wp_version'   => $this->wp_version,
				'active_theme' => $this->active_theme,
			),
			'author'                 => array(
				'note' => $this->note,
			),
			'adapters_fired'         => $adapters_fired,
			'contents'               => array(
				'db'                  => 'db.sql.gz',
				'db_sha256'           => $db_sha256,
				'composer_patch'      => 'composer-patch.json',
				'uploads_manifest'    => 'uploads-manifest.txt',
				'uploads_file_count'  => $uploads_manifest['file_count'],
				'uploads_total_bytes' => $uploads_manifest['total_bytes'],
			),
			'composer_patch_summary' => array(
				'pending_count'    => count( $plugins_patch['pending_requires'] ),
				'unresolved_count' => count( $plugins_patch['unresolved'] ),
			),
		);

		if ( ! empty( $adapter_state ) ) {
			$data['adapter_state'] = $adapter_state;
		}

		return new Manifest( $data );
	}

	/**
	 * Produce a snapshot id: lower-cased slug + UTC timestamp, separated
	 * by `-`. ULIDs would be nicer but pulling in a ULID lib for this
	 * one field isn't worth it — the timestamp+slug form sorts
	 * chronologically and is human-readable.
	 */
	private function generate_id(): string {
		$slug = strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $this->slug ) ?? '' );
		$slug = trim( $slug, '-' );
		if ( '' === $slug ) {
			$slug = 'snapshot';
		}
		return $slug . '-' . gmdate( 'Ymd-His' );
	}
}
