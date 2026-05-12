<?php
/**
 * Attachment-reference capturer — for `option_keys_attachment_refs`,
 * captures the referenced attachment posts (fields + key postmeta)
 * AND copies their underlying binary files (original + all WP-
 * generated size variants) into the snapshot's `uploads/` subdir.
 *
 * The motivation: site_logo / site_icon / custom_logo options carry
 * attachment IDs. On the local stack site_logo might be 42; on stg
 * the attachment will get a different ID. To ship a designer's
 * choice of logo, three things must travel:
 *
 *   1. The attachment post (post_title, post_excerpt, post_status,
 *      _wp_attached_file, _wp_attachment_metadata)
 *   2. The binary files themselves (the original + WP's auto-generated
 *      thumbnails — typically 5–6 files per upload)
 *   3. The fact that option <X> referenced this attachment (so the
 *      apply path can remap the captured ID to the target's local ID)
 *
 * Items 1 + 2 land in this capturer's output:
 *
 *   {
 *     "by_file": {
 *       "2026/05/logo.png": {
 *         "captured_id":   42,
 *         "post_title":    "logo",
 *         "post_excerpt":  "",
 *         "post_content":  "",
 *         "post_status":   "inherit",
 *         "post_mime_type": "image/png",
 *         "meta": {
 *           "_wp_attached_file":       "2026/05/logo.png",
 *           "_wp_attachment_metadata": "<serialized PHP>"
 *         },
 *         "files": ["2026/05/logo.png", "2026/05/logo-300x300.png", ...]
 *       }
 *     },
 *     "option_ref_to_file": {
 *       "site_logo":   "2026/05/logo.png",
 *       "custom_logo": "2026/05/logo.png"
 *     }
 *   }
 *
 * Keyed by `_wp_attached_file` (the relative path under uploads/) —
 * that's stable across local → stg → prd because the source file
 * path is identical. `captured_id` is informational only; the apply
 * path looks up the target's matching attachment by file path.
 *
 * Item 3 (option remapping) is the `option_ref_to_file` map: at
 * apply time, when we'd write `update_option('site_logo', 42)`, we
 * instead lookup the local attachment whose `_wp_attached_file`
 * matches `2026/05/logo.png` and write its ID.
 *
 * Binaries are copied into `$snapshot_dir/uploads/<relative-path>`
 * by {@see capture_binaries()}. The apply path's binary-restore
 * stage copies them back into `wp_upload_dir()` — when the
 * S3UploadsBootstrap is active, that's an `s3://` stream wrapper
 * write so the file lands directly in S3.
 *
 * @package FrankenPress\Cli\Snapshot
 */

declare(strict_types=1);

namespace FrankenPress\Cli\Snapshot;

use RuntimeException;

final class AttachmentRefCapturer {

	/**
	 * @param callable $option_get      fn(string $key): mixed — wraps get_option.
	 * @param callable $post_loader     fn(int $id): ?object — wraps get_post; returns post row or null.
	 * @param callable $meta_reader     fn(int $post_id, string $key): mixed — wraps get_post_meta.
	 * @param string   $uploads_basedir Absolute filesystem path to WP_CONTENT_DIR/uploads (the source for binaries).
	 */
	public function __construct(
		private $option_get,
		private $post_loader,
		private $meta_reader,
		private string $uploads_basedir,
	) {}

	/**
	 * Discover + capture attachments referenced by the scope's
	 * `option_keys_attachment_refs`. Returns the structured payload
	 * to be written as `attachments.json`.
	 *
	 * @return array{by_file: array<string, array<string, mixed>>, option_ref_to_file: array<string, string>}
	 */
	public function capture( SnapshotScope $scope ): array {
		$by_file            = array();
		$option_ref_to_file = array();

		foreach ( $scope->option_keys_attachment_refs as $option_key ) {
			$raw = ( $this->option_get )( $option_key );
			if ( null === $raw || '' === $raw || false === $raw ) {
				continue;
			}
			$id = (int) $raw;
			if ( $id <= 0 ) {
				continue;
			}

			$post = ( $this->post_loader )( $id );
			if ( ! is_object( $post ) ) {
				continue;
			}
			if ( ! isset( $post->post_type ) || 'attachment' !== $post->post_type ) {
				continue;
			}

			$attached_file = (string) ( $this->meta_reader )( $id, '_wp_attached_file' );
			if ( '' === $attached_file ) {
				continue;
			}

			if ( ! isset( $by_file[ $attached_file ] ) ) {
				$metadata                  = ( $this->meta_reader )( $id, '_wp_attachment_metadata' );
				$by_file[ $attached_file ] = array(
					'captured_id'    => $id,
					'post_title'     => (string) ( $post->post_title ?? '' ),
					'post_excerpt'   => (string) ( $post->post_excerpt ?? '' ),
					'post_content'   => (string) ( $post->post_content ?? '' ),
					'post_status'    => (string) ( $post->post_status ?? 'inherit' ),
					'post_mime_type' => (string) ( $post->post_mime_type ?? '' ),
					'meta'           => array(
						'_wp_attached_file'       => $attached_file,
						'_wp_attachment_metadata' => $metadata,
					),
					'files'          => $this->resolve_file_set( $attached_file, $metadata ),
				);
			}

			$option_ref_to_file[ $option_key ] = $attached_file;
		}

		ksort( $by_file );
		ksort( $option_ref_to_file );

		return array(
			'by_file'            => $by_file,
			'option_ref_to_file' => $option_ref_to_file,
		);
	}

	/**
	 * Copy the binary files for every captured attachment into
	 * `$snapshot_dir/uploads/<relative-path>`. Includes the original
	 * file + every WP-generated size variant declared in
	 * `_wp_attachment_metadata.sizes`.
	 *
	 * @param array<string, array<string, mixed>> $by_file  Result of {@see capture()}'s `by_file`.
	 * @return array{file_count: int, total_bytes: int}
	 */
	public function capture_binaries( array $by_file, string $snapshot_dir ): array {
		$count     = 0;
		$bytes     = 0;
		$dest_root = $snapshot_dir . '/uploads';

		foreach ( $by_file as $entry ) {
			$files = (array) ( $entry['files'] ?? array() );
			foreach ( $files as $rel ) {
				$rel  = (string) $rel;
				$src  = $this->uploads_basedir . '/' . $rel;
				$dest = $dest_root . '/' . $rel;
				if ( ! is_file( $src ) ) {
					// Source binary missing on the designer's local
					// stack. Skip silently rather than failing capture
					// — the resulting snapshot will still have the
					// attachment metadata, just no binaries to copy.
					continue;
				}
				if ( ! is_dir( dirname( $dest ) ) ) {
					if ( ! mkdir( dirname( $dest ), 0755, true ) && ! is_dir( dirname( $dest ) ) ) {
						throw new RuntimeException( 'could not create directory: ' . dirname( $dest ) );
					}
				}
				if ( ! copy( $src, $dest ) ) {
					throw new RuntimeException( "could not copy binary {$src} → {$dest}" );
				}
				++$count;
				$bytes += filesize( $src );
			}
		}

		return array(
			'file_count'  => $count,
			'total_bytes' => $bytes,
		);
	}

	/**
	 * Given the primary file path + the `_wp_attachment_metadata`
	 * blob (containing `sizes`), build the complete list of files
	 * for this attachment.
	 *
	 * @param mixed $metadata The `_wp_attachment_metadata` value as
	 *     stored — typically a deserialised array, but can be a raw
	 *     serialised string or scalar if the meta is unset / corrupt.
	 * @return array<int, string>
	 */
	private function resolve_file_set( string $attached_file, $metadata ): array {
		$files = array( $attached_file );
		if ( ! is_array( $metadata ) ) {
			return $files;
		}
		$dir   = dirname( $attached_file );
		$sizes = isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array();
		foreach ( $sizes as $size_info ) {
			if ( is_array( $size_info ) && isset( $size_info['file'] ) ) {
				$files[] = ( '.' === $dir || '' === $dir ) ? (string) $size_info['file'] : $dir . '/' . (string) $size_info['file'];
			}
		}
		return array_values( array_unique( $files ) );
	}
}
