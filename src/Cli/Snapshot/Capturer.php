<?php
/**
 * Snapshot capturer — orchestrates `wp fp snapshot` in fp.snapshot/v4.
 *
 * Produces a snapshot directory containing:
 *
 *   manifest.yaml         — fp.snapshot/v4 manifest (canonical form)
 *   manifest.json         — JSON sidecar for the apply path
 *   content.xml.gz        — WXR for `post_types_additive` only
 *                           (page, post, attachment — INSERT-only on apply)
 *   templates.json        — Owned-CPT sidecar with upsert semantics
 *                           (wp_template, wp_template_part,
 *                            wp_global_styles, wp_navigation)
 *   options.json          — scoped wp_options + theme_mods JSON sidecar
 *   uploads-manifest.txt  — sha256 + size per file under uploads/
 *                           (audit log; not load-bearing for apply)
 *
 * Capture flow:
 *
 *   1. For each registered adapter, run detect()
 *   2. Exactly one adapter must fire — error otherwise
 *   3. WxrCapturer runs `wp export` against post_types_additive
 *   4. OwnedPostsCapturer dumps post_types_owned rows to templates.json
 *   5. OptionsCapturer dumps scoped options + theme_mods to JSON
 *   6. Walk uploads/ → uploads-manifest.txt
 *   7. The fired adapter contributes a capture_state() blob
 *   8. Emit manifest.yaml + manifest.json
 *
 * v4 changes vs v3:
 *
 *   - Scope split into `post_types_additive` + `post_types_owned`.
 *   - WXR now ships only additive content (smaller WXR).
 *   - New `templates.json` sidecar for owned-CPT upsert. See
 *     `iteration-ux.md` in the workspace .aidocs/ for the empirical
 *     motivation (v3's silent-skip on second-apply of FSE CPTs).
 *
 * @package FrankenPress\Cli\Snapshot
 */

declare(strict_types=1);

namespace FrankenPress\Cli\Snapshot;

use FrankenPress\Cli\Adapters\AdapterInterface;
use RuntimeException;

final class Capturer {

	/**
	 * @param string                            $output_dir    Absolute path where the snapshot directory is written.
	 * @param string                            $slug          Designer-chosen short id.
	 * @param string                            $note          Optional designer note.
	 * @param string                            $uploads_dir   WP_CONTENT_DIR/uploads.
	 * @param string                            $site_url      home_url() at capture time.
	 * @param string                            $wp_version    WordPress version string.
	 * @param string                            $active_theme  Stylesheet slug.
	 * @param array<int, AdapterInterface>      $adapters      Registered adapters; only one may detect() positively.
	 * @param WxrCapturer                       $wxr           WXR capturer (additive posts).
	 * @param OwnedPostsCapturer                $owned         Owned-post capturer (FSE CPTs → templates.json).
	 * @param OptionsCapturer                   $opts          Options capturer.
	 */
	public function __construct(
		private string $output_dir,
		private string $slug,
		private string $note,
		private string $uploads_dir,
		private string $site_url,
		private string $wp_version,
		private string $active_theme,
		private array $adapters,
		private WxrCapturer $wxr,
		private OwnedPostsCapturer $owned,
		private OptionsCapturer $opts,
	) {}

	/**
	 * Execute the capture. Returns the absolute path of manifest.yaml.
	 *
	 * @throws RuntimeException when no adapter fired, or when more than
	 *                          one adapter fires (composition is not
	 *                          supported).
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
				"no snapshot adapter detected. fp will not produce an empty snapshot because there's no safe scope to operate on. " .
				'Activate a block theme (FSE-mode) so the bundled Fse adapter detects, or extend mu-plugin with a custom adapter, before snapshotting.'
			);
		}
		if ( count( $fired ) > 1 ) {
			$names = implode( ', ', array_map( static fn ( AdapterInterface $a ): string => $a->name(), $fired ) );
			throw new RuntimeException(
				"multiple adapters detected ({$names}); only a single adapter per snapshot is supported. " .
				'Adjust adapter detect() so exactly one fires.'
			);
		}
		$adapter = $fired[0];
		$scope   = $adapter->scope();

		if ( $scope->is_empty() ) {
			throw new RuntimeException( 'adapter scope is empty; refusing to snapshot' );
		}

		$wxr_path    = $this->output_dir . '/content.xml.gz';
		$wxr_summary = $this->wxr->capture( $scope, $wxr_path );

		$owned_payload = $this->owned->capture( $scope );
		$owned_json    = json_encode( $owned_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
		file_put_contents( $this->output_dir . '/templates.json', $owned_json );
		$owned_sha256 = hash( 'sha256', $owned_json );
		$owned_count  = $this->count_owned_entries( $owned_payload );

		$options_payload = $this->opts->capture( $scope );
		$options_json    = json_encode( $options_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
		file_put_contents( $this->output_dir . '/options.json', $options_json );
		$options_sha256 = hash( 'sha256', $options_json );

		$uploads_manifest = $this->build_uploads_manifest();
		file_put_contents( $this->output_dir . '/uploads-manifest.txt', $uploads_manifest['text'] );

		$adapter_state = $adapter->capture_state();

		$manifest      = $this->build_manifest(
			$adapter->name(),
			$scope,
			$wxr_summary,
			$owned_sha256,
			$owned_count,
			$options_sha256,
			count( $options_payload['options'] ),
			$uploads_manifest,
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
		$excludes = array( '/s3-uploads-failures/', '/cache/' );

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
	 * @param array<string, array<string, array<string, mixed>>> $owned_payload
	 */
	private function count_owned_entries( array $owned_payload ): int {
		$n = 0;
		foreach ( $owned_payload as $by_slug ) {
			$n += count( $by_slug );
		}
		return $n;
	}

	/**
	 * @param array{post_count: int, sha256: string}                 $wxr_summary
	 * @param array{text: string, file_count: int, total_bytes: int} $uploads_manifest
	 * @param array<string, mixed>                                   $adapter_state
	 */
	private function build_manifest(
		string $adapter_name,
		SnapshotScope $scope,
		array $wxr_summary,
		string $owned_sha256,
		int $owned_count,
		string $options_sha256,
		int $options_count,
		array $uploads_manifest,
		array $adapter_state
	): Manifest {
		$id   = $this->generate_id();
		$data = array(
			'schema'   => Manifest::SCHEMA,
			'id'       => $id,
			'created'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'source'   => array(
				'site_url'     => $this->site_url,
				'wp_version'   => $this->wp_version,
				'source_theme' => $this->active_theme,
			),
			'author'   => array(
				'note' => $this->note,
			),
			'adapter'  => $adapter_name,
			'scope'    => array(
				'post_types_additive' => $scope->post_types_additive,
				'post_types_owned'    => $scope->post_types_owned,
				'option_keys'         => $scope->option_keys,
				'theme_mods_for'      => $scope->theme_mods_for,
			),
			'contents' => array(
				'wxr'                 => 'content.xml.gz',
				'wxr_sha256'          => $wxr_summary['sha256'],
				'wxr_post_count'      => $wxr_summary['post_count'],
				'templates'           => 'templates.json',
				'templates_sha256'    => $owned_sha256,
				'templates_count'     => $owned_count,
				'options'             => 'options.json',
				'options_sha256'      => $options_sha256,
				'options_count'       => $options_count,
				'uploads_manifest'    => 'uploads-manifest.txt',
				'uploads_file_count'  => $uploads_manifest['file_count'],
				'uploads_total_bytes' => $uploads_manifest['total_bytes'],
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
