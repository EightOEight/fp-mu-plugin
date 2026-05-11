<?php
/**
 * Snapshot capturer — orchestrates `wp fp snapshot` in fp.snapshot/v2.
 *
 * Produces a snapshot directory containing:
 *
 *   manifest.yaml         — fp.snapshot/v2 manifest (canonical form;
 *                           cosign-signed in Phase 5)
 *   manifest.json         — JSON sidecar for the apply path
 *   content.xml.gz        — WXR content (posts, postmeta, terms, menus,
 *                           attachments) scoped to fired adapters
 *   options.json          — scoped wp_options + theme_mods JSON sidecar
 *   composer-patch.json   — proposed `composer require` set
 *   uploads-manifest.txt  — sha256 + size per file under uploads/
 *
 * Capture flow:
 *
 *   1. For each registered adapter, run detect()
 *   2. Merge the scope of every fired adapter into one combined scope
 *   3. Refuse to produce an empty snapshot (no fired adapters →
 *      explicit error; designer needs to install + activate a theme
 *      with a registered adapter before snapshotting)
 *   4. WxrCapturer runs `wp export` against the combined post-id set
 *   5. OptionsCapturer dumps scoped options + theme_mods to JSON
 *   6. PluginInspector emits composer-patch.json
 *   7. Walk uploads/ → uploads-manifest.txt
 *   8. Each fired adapter contributes a capture_state() blob
 *   9. Emit manifest.yaml + manifest.json
 *
 * Note: no `wp db export` anywhere. No SQL dump. No Sanitiser. The
 * snapshot can only carry rows an adapter declared in scope. By
 * construction, a WooCommerce store's `wc_orders` table is never
 * touched.
 *
 * @package FrankenPress\Cli\Snapshot
 */

declare(strict_types=1);

namespace FrankenPress\Cli\Snapshot;

use FrankenPress\Cli\Adapters\AdapterInterface;
use RuntimeException;

final class Capturer {

	/**
	 * @param string                            $output_dir         Absolute path where the snapshot directory is written.
	 * @param string                            $slug               Designer-chosen short id.
	 * @param string                            $note               Optional designer note.
	 * @param string                            $plugin_dir         WP_PLUGIN_DIR.
	 * @param string                            $uploads_dir        WP_CONTENT_DIR/uploads.
	 * @param string                            $composer_json_path Site composer.json path.
	 * @param array<int, string>                $active_plugins     active_plugins option value.
	 * @param string                            $site_url           home_url() at capture time.
	 * @param string                            $wp_version         WordPress version string.
	 * @param string                            $active_theme       Stylesheet slug.
	 * @param array<int, AdapterInterface>      $adapters           Registered adapters; only those that detect() get to contribute.
	 * @param WxrCapturer                       $wxr                WXR capturer (injected so tests can swap the wp-cli + sql runners).
	 * @param OptionsCapturer                   $opts               Options capturer (injected for the same reason).
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
		private array $adapters,
		private WxrCapturer $wxr,
		private OptionsCapturer $opts,
	) {}

	/**
	 * Execute the capture. Returns the absolute path of manifest.yaml.
	 *
	 * @throws RuntimeException when no adapter fired (an empty snapshot is never produced).
	 */
	public function capture(): string {
		$this->prepare_output_dir();

		$fired = array();
		foreach ( $this->adapters as $a ) {
			if ( $a->detect() ) {
				$fired[] = $a;
			}
		}

		if ( empty( $fired ) ) {
			throw new RuntimeException(
				"no premium-theme adapter detected. fp will not produce an empty snapshot because there's no safe scope to operate on. " .
				'Activate a theme that ships an adapter (The7, etc.), or extend mu-plugin with a custom adapter, before snapshotting.'
			);
		}

		$scope = $fired[0]->scope();
		for ( $i = 1; $i < count( $fired ); $i++ ) {
			$scope = $scope->merged_with( $fired[ $i ]->scope() );
		}

		if ( $scope->is_empty() ) {
			throw new RuntimeException( 'merged adapter scope is empty; refusing to snapshot' );
		}

		$wxr_path    = $this->output_dir . '/content.xml.gz';
		$wxr_summary = $this->wxr->capture( $scope, $wxr_path );

		$options_payload = $this->opts->capture( $scope );
		$options_json    = json_encode( $options_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
		file_put_contents( $this->output_dir . '/options.json', $options_json );
		$options_sha256 = hash( 'sha256', $options_json );

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
		file_put_contents( $this->output_dir . '/uploads-manifest.txt', $uploads_manifest['text'] );

		$adapter_state  = array();
		$adapters_fired = array();
		foreach ( $fired as $a ) {
			$state = $a->capture_state();
			if ( ! empty( $state ) ) {
				$adapter_state[ $a->name() ] = $state;
			}
			$adapters_fired[] = $a->name();
		}

		$manifest      = $this->build_manifest(
			$scope,
			$wxr_summary,
			$options_sha256,
			count( $options_payload['options'] ),
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
		$entries = glob( $this->output_dir . '/*' );
		foreach ( ( false === $entries ? array() : $entries ) as $entry ) {
			if ( is_file( $entry ) ) {
				unlink( $entry );
			}
		}
	}

	/**
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
	 * @param array{post_count: int, sha256: string}                                                $wxr_summary
	 * @param array{text: string, file_count: int, total_bytes: int}                                $uploads_manifest
	 * @param array{pending_requires: array<int, string>, unresolved: array<int, string>, rationale: string} $plugins_patch
	 * @param array<int, string>                                                                    $adapters_fired
	 * @param array<string, array<string, mixed>>                                                   $adapter_state
	 */
	private function build_manifest(
		SnapshotScope $scope,
		array $wxr_summary,
		string $options_sha256,
		int $options_count,
		array $uploads_manifest,
		array $plugins_patch,
		array $adapters_fired,
		array $adapter_state
	): Manifest {
		$id   = $this->generate_id();
		$data = array(
			'schema'                 => Manifest::SCHEMA,
			'id'                     => $id,
			'created'                => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'source'                 => array(
				'site_url'     => $this->site_url,
				'wp_version'   => $this->wp_version,
				'active_theme' => $this->active_theme,
			),
			'author'                 => array(
				'note' => $this->note,
			),
			'adapters_fired'         => $adapters_fired,
			'scope'                  => array(
				'post_types_with_marker'  => $scope->post_types_with_marker,
				'post_types_full_capture' => $scope->post_types_full_capture,
				'option_patterns'         => $scope->option_patterns,
				'theme_mods_for'          => $scope->theme_mods_for,
				'documented_exclusions'   => $scope->documented_exclusions,
			),
			'contents'               => array(
				'wxr'                 => 'content.xml.gz',
				'wxr_sha256'          => $wxr_summary['sha256'],
				'wxr_post_count'      => $wxr_summary['post_count'],
				'options'             => 'options.json',
				'options_sha256'      => $options_sha256,
				'options_count'       => $options_count,
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

	private function generate_id(): string {
		$slug = strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $this->slug ) ?? '' );
		$slug = trim( $slug, '-' );
		if ( '' === $slug ) {
			$slug = 'snapshot';
		}
		return $slug . '-' . gmdate( 'Ymd-His' );
	}
}
