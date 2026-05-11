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
	 * **WP-Importer must be installed and active before apply runs.**
	 * The plugin is *not* installed by this command — `wp plugin
	 * install` requires a writable `WP_CONTENT_DIR/upgrade` and the
	 * runtime image is read-only by design (immutable image,
	 * DISALLOW_FILE_MODS=true). Sites consuming `wp fp apply` must
	 * declare `wpackagist-plugin/wordpress-importer` in their
	 * composer.json so the plugin is baked into the image.
	 *
	 * `--authors=skip` because designer-side WXR exports include the
	 * designer's user as the post_author; we don't want to ship user
	 * accounts. `wp post` calls preserve existing post authors if the
	 * referenced user doesn't exist.
	 */
	private function import_wxr( string $gz_path ): void {
		$this->require_wxr_importer();

		// Decompress to a tmp .xml file. wp import expects a path.
		$tmp = tempnam( sys_get_temp_dir(), 'fp-wxr-' );
		if ( false === $tmp ) {
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

			( $this->wp_runner )(
				sprintf( 'import %s --authors=skip --skip=image_resize', escapeshellarg( $xml_path ) ),
				array()
			);
		} finally {
			if ( file_exists( $xml_path ) ) {
				unlink( $xml_path );
			}
			if ( file_exists( $tmp ) ) {
				unlink( $tmp );
			}
		}
	}

	/**
	 * Fail fast with a clear, actionable message if WP-Importer isn't
	 * present. The plugin must be baked into the consumer site's image
	 * via `composer require wpackagist-plugin/wordpress-importer`. We
	 * don't attempt `wp plugin install` — it requires a writable
	 * upgrade directory that the runtime intentionally lacks.
	 */
	private function require_wxr_importer(): void {
		$active = function_exists( 'is_plugin_active' )
			? \is_plugin_active( 'wordpress-importer/wordpress-importer.php' )
			: in_array(
				'wordpress-importer/wordpress-importer.php',
				(array) ( ( $this->option_reader )( 'active_plugins' ) ?? array() ),
				true
			);
		if ( $active ) {
			return;
		}
		throw new RuntimeException(
			'snapshot apply requires the WP-Importer plugin to be installed and active. '
				. 'Add `wpackagist-plugin/wordpress-importer` (^0.9) to your site repo\'s composer.json '
				. 'and rebuild the image; the runtime is read-only at apply time so '
				. '`wp plugin install` cannot be used to fetch it on demand.'
		);
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
