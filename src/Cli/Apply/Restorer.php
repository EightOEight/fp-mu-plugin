<?php
/**
 * Snapshot restorer — orchestrates `wp fp apply`.
 *
 * Inverse of {@see \FrankenPress\Cli\Snapshot\Capturer}. Reads a snapshot
 * directory (or a downloaded copy of one — the Go-side `fp` binary
 * fetches from S3 first), verifies its integrity, imports the SQL
 * dump, runs `wp search-replace` to retarget URLs to the current
 * site, fires premium-theme adapter `post_restore()` hooks, and stamps
 * idempotency markers so subsequent invocations no-op.
 *
 * Idempotency boundary:
 *
 *   wp_option `fp_snapshot_applied_ref`     === manifest.id
 *   wp_option `fp_snapshot_applied_sha256`  === manifest.contents.db_sha256
 *
 * Both must match to short-circuit. Phase 5 of the plan ("Helm hook
 * hardening") tightens this further — cosign-verifies the manifest
 * signature before this code runs.
 *
 * @package FrankenPress\Cli\Apply
 */

declare(strict_types=1);

namespace FrankenPress\Cli\Apply;

use FrankenPress\Cli\Adapters\The7;
use RuntimeException;

final class Restorer {

	public const APPLIED_REF_OPTION    = 'fp_snapshot_applied_ref';
	public const APPLIED_SHA256_OPTION = 'fp_snapshot_applied_sha256';

	/**
	 * @param string                              $snapshot_dir  Local directory containing manifest.json + db.sql.gz.
	 * @param string                              $target_url    home_url() at apply time (where to retarget).
	 * @param array<int, The7>                    $adapters      Adapter instances; only those listed in manifest.adapters_fired run.
	 * @param callable                            $wp_runner     fn(string $command, array $assoc_args): mixed — wraps WP_CLI::runcommand for testability.
	 * @param callable                            $option_reader fn(string $key): mixed — wraps get_option for testability.
	 * @param callable                            $option_writer fn(string $key, mixed $value, bool $autoload): bool — wraps update_option.
	 */
	public function __construct(
		private string $snapshot_dir,
		private string $target_url,
		private array $adapters,
		private $wp_runner,
		private $option_reader,
		private $option_writer,
	) {}

	/**
	 * Apply the snapshot. Returns:
	 *
	 *   "applied"  — the SQL was imported and adapters fired.
	 *   "skipped"  — idempotency markers already matched; nothing done.
	 *
	 * Throws RuntimeException on integrity / IO failure.
	 */
	public function apply(): string {
		$manifest     = $this->read_manifest();
		$id           = (string) ( $manifest['id'] ?? '' );
		$expected_sha = (string) ( $manifest['contents']['db_sha256'] ?? '' );

		if ( '' === $id || '' === $expected_sha ) {
			throw new RuntimeException( 'manifest is missing required fields (id, contents.db_sha256)' );
		}

		if ( $this->already_applied( $id, $expected_sha ) ) {
			return 'skipped';
		}

		$db_path = $this->snapshot_dir . '/db.sql.gz';
		$this->verify_dump( $db_path, $expected_sha );

		$source_url = (string) ( $manifest['source']['site_url'] ?? '' );

		$this->import_dump( $db_path );

		if ( '' !== $source_url && $source_url !== $this->target_url ) {
			$this->retarget_urls( $source_url, $this->target_url );
		}

		$this->fire_adapters(
			(array) ( $manifest['adapters_fired'] ?? array() ),
			(array) ( $manifest['adapter_state'] ?? array() )
		);

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

	private function verify_dump( string $path, string $expected_sha ): void {
		if ( ! is_file( $path ) ) {
			throw new RuntimeException( "db.sql.gz not found at {$path}" );
		}
		$actual = hash_file( 'sha256', $path );
		if ( $actual !== $expected_sha ) {
			throw new RuntimeException(
				"db.sql.gz integrity check failed: manifest says {$expected_sha}, actual {$actual}"
			);
		}
	}

	private function import_dump( string $gz_path ): void {
		// `wp db import -` reads from stdin. WP_CLI::runcommand doesn't
		// give us a stdin pipe; the cleanest route is to gunzip to a
		// temp file then `wp db import <tmpfile>`.
		$tmp = tempnam( sys_get_temp_dir(), 'fp-snapshot-' );
		if ( false === $tmp ) {
			throw new RuntimeException( 'could not create temp file for SQL import' );
		}
		try {
			$gz = gzopen( $gz_path, 'rb' );
			if ( false === $gz ) {
				throw new RuntimeException( "could not gzopen {$gz_path}" );
			}
			$fh = fopen( $tmp, 'wb' );
			if ( false === $fh ) {
				gzclose( $gz );
				throw new RuntimeException( "could not open temp file {$tmp}" );
			}
			while ( ! gzeof( $gz ) ) {
				$chunk = gzread( $gz, 1024 * 1024 );
				if ( false === $chunk || '' === $chunk ) {
					break;
				}
				fwrite( $fh, $chunk );
			}
			fclose( $fh );
			gzclose( $gz );

			( $this->wp_runner )( "db import {$tmp}", array() );
		} finally {
			if ( file_exists( $tmp ) ) {
				unlink( $tmp );
			}
		}
	}

	private function retarget_urls( string $source_url, string $target_url ): void {
		// wp search-replace handles serialised PHP correctly (deserialises,
		// replaces, re-serialises) — the only safe way to rewrite URLs
		// inside option_value / postmeta blobs.
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
	 * @param array<int, string>           $adapters_fired
	 * @param array<string, array<string, mixed>> $adapter_state
	 */
	private function fire_adapters( array $adapters_fired, array $adapter_state ): void {
		foreach ( $this->adapters as $adapter ) {
			$name = $adapter::NAME;
			if ( ! in_array( $name, $adapters_fired, true ) ) {
				continue;
			}
			$state = $adapter_state[ $name ] ?? array();
			$adapter->post_restore( $state );
		}
	}
}
