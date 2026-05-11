<?php
/**
 * WXR capturer — runs `wp export` against the union of adapter scopes
 * and gzips the result into `content.xml.gz`.
 *
 * The WXR (WordPress eXtended RSS) format is what every premium-theme
 * demo importer round-trips on the WP side; using it for our snapshot
 * means we inherit WP-Importer's mature handling of:
 *
 *   - Post ID conflicts on apply (re-assigns IDs, fixes up postmeta
 *     references, fixes post_parent / menu_order)
 *   - Term creation (skips existing terms by slug)
 *   - Author remapping (we use `--authors=skip` on apply)
 *   - Attachment handling (we capture only manifest refs, not bytes)
 *   - Nav menus (a special CPT in WP; handled natively)
 *
 * Why we don't use `wp db export`: it's a full DB dump including
 * tables that have no place in a designer snapshot (`wc_orders`,
 * `wp_users`, `wp_comments`). Applying such a dump in production
 * with `wp db import` destroys whatever exists in those tables. WXR
 * is strictly additive — `wp import` only INSERTs (and skips existing
 * terms/attachments by reference).
 *
 * @package FrankenPress\Cli\Snapshot
 */

declare(strict_types=1);

namespace FrankenPress\Cli\Snapshot;

use RuntimeException;

final class WxrCapturer {

	/**
	 * @param callable $wp_runner  Function ($command, $assoc_args): mixed
	 *     — runs a wp-cli command. Production caller injects a closure
	 *     over `WP_CLI::runcommand`. Tests inject a fake that records
	 *     the call.
	 * @param callable $sql_runner Function (string $sql): array<int, array<string, mixed>>
	 *     — runs a SELECT against the live DB and returns rows as
	 *     assoc arrays. Production caller injects a closure over
	 *     `$wpdb->get_results( $sql, ARRAY_A )`.
	 */
	public function __construct(
		private $wp_runner,
		private $sql_runner,
	) {}

	/**
	 * Capture the scoped post set as a gzipped WXR file at $output_path.
	 *
	 * @return array{post_count: int, sha256: string} Summary of what was captured.
	 */
	public function capture( SnapshotScope $scope, string $output_path ): array {
		$ids = $this->resolve_post_ids( $scope );

		$tmp_xml = $output_path . '.tmp.xml';

		if ( empty( $ids ) ) {
			// Emit a minimal empty WXR so the apply path always has a
			// file to read — simpler than branching on emptiness.
			file_put_contents( $tmp_xml, $this->empty_wxr() );
		} else {
			$this->run_export( $ids, $tmp_xml );
		}

		$this->gzip_file( $tmp_xml, $output_path );
		@unlink( $tmp_xml );

		return array(
			'post_count' => count( $ids ),
			'sha256'     => hash_file( 'sha256', $output_path ),
		);
	}

	/**
	 * Run the SQL queries that turn a SnapshotScope into a concrete
	 * list of post IDs. Two paths:
	 *
	 *   - post_types_with_marker: posts of the right type that ALSO
	 *     have the marker postmeta. INNER JOIN posts + postmeta.
	 *   - post_types_full_capture: every post of the listed types.
	 *
	 * @return array<int, int>
	 */
	private function resolve_post_ids( SnapshotScope $scope ): array {
		$ids = array();

		foreach ( $scope->post_types_with_marker as $post_type => $meta_key ) {
			$sql  = sprintf(
				"SELECT DISTINCT p.ID FROM %s p INNER JOIN %s m ON p.ID = m.post_id WHERE p.post_type = '%s' AND m.meta_key = '%s'",
				$this->table( 'posts' ),
				$this->table( 'postmeta' ),
				$this->escape_string( $post_type ),
				$this->escape_string( $meta_key )
			);
			$rows = ( $this->sql_runner )( $sql );
			foreach ( $rows as $row ) {
				$ids[ (int) $row['ID'] ] = true;
			}
		}

		foreach ( $scope->post_types_full_capture as $post_type ) {
			$sql  = sprintf(
				"SELECT ID FROM %s WHERE post_type = '%s'",
				$this->table( 'posts' ),
				$this->escape_string( $post_type )
			);
			$rows = ( $this->sql_runner )( $sql );
			foreach ( $rows as $row ) {
				$ids[ (int) $row['ID'] ] = true;
			}
		}

		$out = array_keys( $ids );
		sort( $out );
		return $out;
	}

	/**
	 * @param array<int, int> $ids
	 */
	private function run_export( array $ids, string $output_path ): void {
		// `wp export` has no --filename flag — its CLI accepts --dir
		// (with an auto-generated filename inside) or --stdout. We use
		// --stdout because it lets us write to an arbitrary path
		// without parsing the auto-generated name out of wp-cli's
		// console output.
		//
		// The runner closure passes the subprocess's stdout back to
		// us via the WP_CLI::runcommand return object (return=>'all'),
		// so we can write it to disk ourselves at exactly the path
		// the manifest will point to.
		//
		// --skip_comments because comments are not part of the
		// designer-scope content; user-generated comments belong to
		// the live site and would be re-imported as duplicates on
		// apply anyway.
		// Shell out via proc_open instead of WP_CLI::runcommand:
		// runcommand's launch=true subprocess handling can deadlock on
		// large exports (observed in dogfood: ~180 post IDs hung
		// indefinitely). Direct proc_open with explicit /dev/null on
		// stdin avoids the whole class of issue.
		$wp_bin = $this->locate_wp_binary();
		$cmd    = array(
			$wp_bin,
			'--allow-root',
			'--path=' . $this->wp_path(),
			'export',
			'--post__in=' . implode( ',', array_map( 'intval', $ids ) ),
			'--stdout',
			'--skip_comments',
		);

		$descs = array(
			0 => array( 'file', '/dev/null', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$proc = proc_open( $cmd, $descs, $pipes );
		if ( ! is_resource( $proc ) ) {
			throw new RuntimeException( 'wxr-capturer: proc_open(wp export) failed' );
		}

		$out_fh = fopen( $output_path, 'wb' );
		if ( false === $out_fh ) {
			proc_close( $proc );
			throw new RuntimeException( "wxr-capturer: could not open {$output_path} for writing" );
		}
		try {
			while ( ! feof( $pipes[1] ) ) {
				$chunk = fread( $pipes[1], 64 * 1024 );
				if ( false === $chunk || '' === $chunk ) {
					break;
				}
				fwrite( $out_fh, $chunk );
			}
		} finally {
			fclose( $out_fh );
		}
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit_code = proc_close( $proc );

		if ( 0 !== $exit_code ) {
			@unlink( $output_path );
			throw new RuntimeException(
				sprintf(
					'wxr-capturer: wp export exited %d%s',
					$exit_code,
					'' !== trim( $stderr ) ? ' (stderr: ' . trim( $stderr ) . ')' : ''
				)
			);
		}

		if ( ! is_file( $output_path ) || 0 === filesize( $output_path ) ) {
			throw new RuntimeException( "wxr-capturer: wp export produced no output at {$output_path}" );
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

	private function gzip_file( string $in_path, string $out_path ): void {
		$in = fopen( $in_path, 'rb' );
		if ( false === $in ) {
			throw new RuntimeException( "wxr-capturer: could not open {$in_path} for reading" );
		}
		$out = gzopen( $out_path, 'wb9' );
		if ( false === $out ) {
			fclose( $in );
			throw new RuntimeException( "wxr-capturer: could not open {$out_path} for gzip writing" );
		}
		try {
			while ( ! feof( $in ) ) {
				$chunk = fread( $in, 64 * 1024 );
				if ( false === $chunk || '' === $chunk ) {
					break;
				}
				gzwrite( $out, $chunk );
			}
		} finally {
			fclose( $in );
			gzclose( $out );
		}
	}

	private function empty_wxr(): string {
		return <<<XML
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0"
     xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
     xmlns:content="http://purl.org/rss/1.0/modules/content/"
     xmlns:wfw="http://wellformedweb.org/CommentAPI/"
     xmlns:dc="http://purl.org/dc/elements/1.1/"
     xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
  <wp:wxr_version>1.2</wp:wxr_version>
</channel>
</rss>

XML;
	}

	/**
	 * Returns the table name including the WP table prefix. Read from
	 * the live $wpdb so multi-site / custom-prefix installs work
	 * correctly.
	 */
	private function table( string $unprefixed ): string {
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->{$unprefixed} ) ) {
			return (string) $wpdb->{$unprefixed};
		}
		return 'wp_' . $unprefixed;
	}

	/**
	 * Conservative SQL string escape. We control the inputs (adapter
	 * config), so the only realistic chars to handle are quotes and
	 * backslashes. wpdb's `_real_escape` would be the right canonical
	 * source but we want this class testable without booting WP.
	 */
	private function escape_string( string $in ): string {
		return strtr(
			$in,
			array(
				'\\' => '\\\\',
				"'"  => "\\'",
			)
		);
	}
}
