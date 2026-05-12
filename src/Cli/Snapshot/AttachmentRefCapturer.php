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
	 * @param callable $blocks_parser   fn(string $content): array — wraps parse_blocks(); returns the WP blocks tree. Tests inject a fake.
	 * @param string   $uploads_basedir Absolute filesystem path to WP_CONTENT_DIR/uploads (the source for binaries).
	 */
	public function __construct(
		private $option_get,
		private $post_loader,
		private $meta_reader,
		private $blocks_parser,
		private string $uploads_basedir,
	) {}

	/**
	 * Discover + capture attachments referenced by:
	 *   1. The scope's `option_keys_attachment_refs` option values
	 *      (e.g. `site_logo` → attachment ID).
	 *   2. Inline block-attribute references inside the captured
	 *      owned-posts' `post_content` (e.g. `<!-- wp:image
	 *      {"id":42} -->` inside a `wp_template_part`).
	 *
	 * Returns the structured payload to be written as `attachments.json`.
	 *
	 * @param array<string, array<string, array<string, mixed>>> $owned_payload  The output of OwnedPostsCapturer::capture(). Used for the block-content scan pass.
	 * @return array{by_file: array<string, array<string, mixed>>, option_ref_to_file: array<string, string>}
	 */
	public function capture( SnapshotScope $scope, array $owned_payload = array() ): array {
		$by_file            = array();
		$option_ref_to_file = array();

		// Pass 1: option-value references (site_logo, site_icon, custom_logo).
		foreach ( $scope->option_keys_attachment_refs as $option_key ) {
			$raw = ( $this->option_get )( $option_key );
			if ( null === $raw || '' === $raw || false === $raw ) {
				continue;
			}
			$id = (int) $raw;
			if ( $id <= 0 ) {
				continue;
			}
			$attached_file = $this->maybe_capture_attachment( $id, $by_file );
			if ( null !== $attached_file ) {
				$option_ref_to_file[ $option_key ] = $attached_file;
			}
		}

		// Pass 2: inline block-attribute references inside owned-posts'
		// post_content. Walks every captured wp_template / wp_template_part /
		// wp_global_styles / wp_navigation entry, parses its block tree,
		// extracts every numeric `id` / `mediaId` / etc. attr, and captures
		// any that resolve to attachment posts. False positives are filtered
		// by the post_type check.
		foreach ( $owned_payload as $by_slug ) {
			if ( ! is_array( $by_slug ) ) {
				continue;
			}
			foreach ( $by_slug as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$content = (string) ( $entry['post_content'] ?? '' );
				if ( '' === $content ) {
					continue;
				}
				$ids = $this->extract_block_attachment_ids( $content );
				foreach ( $ids as $id ) {
					$this->maybe_capture_attachment( $id, $by_file );
				}
			}
		}

		ksort( $by_file );
		ksort( $option_ref_to_file );

		return array(
			'by_file'            => $by_file,
			'option_ref_to_file' => $option_ref_to_file,
		);
	}

	/**
	 * Capture a single attachment post + meta into the by_file map.
	 * Returns the relative file path on success (so option-pass callers
	 * can populate option_ref_to_file), or null when the post isn't an
	 * attachment / has no `_wp_attached_file` / is already captured.
	 *
	 * @param array<string, array<string, mixed>> &$by_file
	 */
	private function maybe_capture_attachment( int $id, array &$by_file ): ?string {
		$post = ( $this->post_loader )( $id );
		if ( ! is_object( $post ) ) {
			return null;
		}
		if ( ! isset( $post->post_type ) || 'attachment' !== $post->post_type ) {
			return null;
		}

		$attached_file = (string) ( $this->meta_reader )( $id, '_wp_attached_file' );
		if ( '' === $attached_file ) {
			return null;
		}

		if ( isset( $by_file[ $attached_file ] ) ) {
			return $attached_file;
		}

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
		return $attached_file;
	}

	/**
	 * Walk a block tree extracted from `post_content` and return every
	 * integer attr value that could be an attachment ID. The caller
	 * filters by `get_post()->post_type === 'attachment'` so over-
	 * collection here is harmless.
	 *
	 * Block IDs we expect to find:
	 *   wp:image          → attrs.id
	 *   wp:cover          → attrs.id (background image)
	 *   wp:media-text     → attrs.mediaId
	 *   wp:gallery        → attrs.ids[] (legacy) or innerBlocks of wp:image
	 *   wp:video / audio  → attrs.id
	 *   site-logo block   → no attr (uses option) — already handled by pass 1
	 *
	 * Pragmatic implementation: walk every attrs leaf, collect any
	 * positive integer or numeric-string. Caller validates each.
	 *
	 * @return array<int, int>
	 */
	private function extract_block_attachment_ids( string $content ): array {
		$blocks = ( $this->blocks_parser )( $content );
		if ( ! is_array( $blocks ) ) {
			return array();
		}
		$out = array();
		$this->walk_blocks_for_ids( $blocks, $out );
		return array_values( array_unique( $out ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<int, int>                  &$out
	 */
	private function walk_blocks_for_ids( array $blocks, array &$out ): void {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				$this->collect_int_values( $block['attrs'], $out );
			}
			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$this->walk_blocks_for_ids( $block['innerBlocks'], $out );
			}
		}
	}

	/**
	 * Recursively collect every value in $tree that's a positive
	 * integer or all-digit string into $out.
	 *
	 * @param array<int|string, mixed> $tree
	 * @param array<int, int>          &$out
	 */
	private function collect_int_values( array $tree, array &$out ): void {
		foreach ( $tree as $value ) {
			if ( is_int( $value ) && $value > 0 ) {
				$out[] = $value;
				continue;
			}
			if ( is_string( $value ) && '' !== $value && ctype_digit( $value ) && (int) $value > 0 ) {
				$out[] = (int) $value;
				continue;
			}
			if ( is_array( $value ) ) {
				$this->collect_int_values( $value, $out );
			}
		}
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
