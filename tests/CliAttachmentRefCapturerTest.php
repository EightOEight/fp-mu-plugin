<?php
/**
 * Unit tests for FrankenPress\Cli\Snapshot\AttachmentRefCapturer.
 *
 * @package FrankenPress\Tests
 */

declare(strict_types=1);

namespace FrankenPress\Tests;

use FrankenPress\Cli\Snapshot\AttachmentRefCapturer;
use FrankenPress\Cli\Snapshot\SnapshotScope;
use PHPUnit\Framework\TestCase;

final class CliAttachmentRefCapturerTest extends TestCase {

	/**
	 * Helper: build a fake blocks_parser that returns a fixed
	 * tree for any input. Tests that don't exercise the
	 * block-scanning path pass a no-op parser.
	 *
	 * @param array<int, array<string, mixed>> $tree
	 * @return callable
	 */
	private function fake_blocks_parser( array $tree = array() ): callable {
		return static fn ( string $content ): array => $tree;
	}

	public function test_capture_empty_scope_returns_empty(): void {
		$c   = new AttachmentRefCapturer(
			static fn ( string $key ): mixed => null,
			static fn ( int $id ): ?object => null,
			static fn ( int $id, string $key ): mixed => '',
			$this->fake_blocks_parser(),
			'/tmp/nonexistent'
		);
		$out = $c->capture( new SnapshotScope() );
		$this->assertSame( array(), $out['by_file'] );
		$this->assertSame( array(), $out['option_ref_to_file'] );
	}

	public function test_capture_records_logo_attachment_with_meta_and_files(): void {
		$options = array(
			'site_logo' => '42',
			'site_icon' => '43',
		);
		$posts   = array(
			42 => (object) array(
				'ID'             => 42,
				'post_type'      => 'attachment',
				'post_title'     => 'logo',
				'post_excerpt'   => '',
				'post_content'   => '',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/png',
			),
			43 => (object) array(
				'ID'             => 43,
				'post_type'      => 'attachment',
				'post_title'     => 'favicon',
				'post_excerpt'   => '',
				'post_content'   => '',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/x-icon',
			),
		);
		$meta    = array(
			42 => array(
				'_wp_attached_file'       => '2026/05/logo.png',
				'_wp_attachment_metadata' => array(
					'sizes' => array(
						'thumbnail' => array( 'file' => 'logo-150x150.png' ),
						'medium'    => array( 'file' => 'logo-300x300.png' ),
					),
				),
			),
			43 => array(
				'_wp_attached_file'       => '2026/05/favicon.ico',
				'_wp_attachment_metadata' => array(),
			),
		);

		$c = new AttachmentRefCapturer(
			static fn ( string $key ): mixed => $options[ $key ] ?? null,
			static fn ( int $id ): ?object => $posts[ $id ] ?? null,
			static fn ( int $id, string $key ): mixed => $meta[ $id ][ $key ] ?? '',
			$this->fake_blocks_parser(),
			'/tmp/nonexistent'
		);

		$out = $c->capture(
			new SnapshotScope( option_keys_attachment_refs: array( 'site_logo', 'site_icon' ) )
		);

		$this->assertArrayHasKey( '2026/05/logo.png', $out['by_file'] );
		$this->assertArrayHasKey( '2026/05/favicon.ico', $out['by_file'] );

		$logo = $out['by_file']['2026/05/logo.png'];
		$this->assertSame( 42, $logo['captured_id'] );
		$this->assertSame( 'logo', $logo['post_title'] );
		$this->assertContains( '2026/05/logo.png', $logo['files'] );
		$this->assertContains( '2026/05/logo-150x150.png', $logo['files'] );
		$this->assertContains( '2026/05/logo-300x300.png', $logo['files'] );

		$this->assertSame( '2026/05/logo.png', $out['option_ref_to_file']['site_logo'] );
		$this->assertSame( '2026/05/favicon.ico', $out['option_ref_to_file']['site_icon'] );
	}

	public function test_capture_skips_non_attachment_posts(): void {
		$c = new AttachmentRefCapturer(
			static fn ( string $key ): mixed => '99',
			static fn ( int $id ): ?object => (object) array(
				'ID'        => 99,
				'post_type' => 'page',
			),
			static fn ( int $id, string $key ): mixed => 'should-not-be-read',
			$this->fake_blocks_parser(),
			'/tmp/nonexistent'
		);

		$out = $c->capture(
			new SnapshotScope( option_keys_attachment_refs: array( 'site_logo' ) )
		);

		$this->assertSame( array(), $out['by_file'] );
		$this->assertSame( array(), $out['option_ref_to_file'] );
	}

	public function test_capture_dedups_by_file_when_two_options_reference_same_attachment(): void {
		$options = array(
			'site_logo'   => '42',
			'custom_logo' => '42',
		);
		$post    = (object) array(
			'ID'             => 42,
			'post_type'      => 'attachment',
			'post_title'     => 'logo',
			'post_excerpt'   => '',
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/png',
		);
		$meta    = array(
			'_wp_attached_file'       => '2026/05/logo.png',
			'_wp_attachment_metadata' => array(),
		);

		$c = new AttachmentRefCapturer(
			static fn ( string $key ): mixed => $options[ $key ] ?? null,
			static fn ( int $id ): ?object => 42 === $id ? $post : null,
			static fn ( int $id, string $key ): mixed => $meta[ $key ] ?? '',
			$this->fake_blocks_parser(),
			'/tmp/nonexistent'
		);

		$out = $c->capture(
			new SnapshotScope( option_keys_attachment_refs: array( 'site_logo', 'custom_logo' ) )
		);

		$this->assertCount( 1, $out['by_file'] );
		$this->assertSame( '2026/05/logo.png', $out['option_ref_to_file']['site_logo'] );
		$this->assertSame( '2026/05/logo.png', $out['option_ref_to_file']['custom_logo'] );
	}

	public function test_capture_discovers_attachments_from_owned_post_block_content(): void {
		// Designer placed an `<!-- wp:image {"id":77,"url":"..."} -->`
		// block inside a wp_template_part (the footer). The
		// AttachmentRefCapturer should pick up id=77, validate it as
		// an attachment, and ship it.
		$attachment  = (object) array(
			'ID'             => 77,
			'post_type'      => 'attachment',
			'post_title'     => 'footer-image',
			'post_excerpt'   => '',
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/png',
		);
		$blocks_tree = array(
			array(
				'blockName'   => 'core/image',
				'attrs'       => array(
					'id'  => 77,
					'url' => 'http://localhost:8080/...',
				),
				'innerBlocks' => array(),
			),
		);
		$meta        = array(
			'_wp_attached_file'       => '2026/05/footer.png',
			'_wp_attachment_metadata' => array(),
		);

		$c = new AttachmentRefCapturer(
			static fn ( string $key ): mixed => null,
			static fn ( int $id ): ?object => 77 === $id ? $attachment : null,
			static fn ( int $id, string $key ): mixed => $meta[ $key ] ?? '',
			static fn ( string $content ): array => $blocks_tree,
			'/tmp/nonexistent'
		);

		$out = $c->capture(
			new SnapshotScope( post_types_owned: array( 'wp_template_part' ) ),
			array(
				'wp_template_part' => array(
					'footer' => array(
						'post_content' => '<!-- wp:image {"id":77} /-->',
					),
				),
			)
		);

		$this->assertArrayHasKey( '2026/05/footer.png', $out['by_file'] );
		$this->assertSame( 77, $out['by_file']['2026/05/footer.png']['captured_id'] );
		// No option pointed at it — option_ref_to_file stays empty.
		$this->assertSame( array(), $out['option_ref_to_file'] );
	}

	public function test_capture_block_scan_filters_non_attachment_ids(): void {
		// Block attrs often carry IDs for non-attachment things (post
		// IDs in `wp:post-content`, taxonomy IDs in `wp:query`, etc.).
		// The capturer should only ship attachments — other IDs get
		// filtered by the post_type check.
		$blocks_tree = array(
			array(
				'blockName'   => 'core/query',
				'attrs'       => array(
					'queryId' => 1,
					'query'   => array( 'perPage' => 10 ),
				),
				'innerBlocks' => array(),
			),
		);
		$c           = new AttachmentRefCapturer(
			static fn ( string $key ): mixed => null,
			static fn ( int $id ): ?object => (object) array(
				'ID'        => $id,
				'post_type' => 'wp_template', // not an attachment
			),
			static fn ( int $id, string $key ): mixed => '',
			static fn ( string $content ): array => $blocks_tree,
			'/tmp/nonexistent'
		);

		$out = $c->capture(
			new SnapshotScope( post_types_owned: array( 'wp_template' ) ),
			array(
				'wp_template' => array(
					'home' => array( 'post_content' => '<!-- wp:query ... -->' ),
				),
			)
		);

		$this->assertSame( array(), $out['by_file'] );
	}

	public function test_capture_block_scan_walks_nested_blocks(): void {
		// Nested blocks (e.g. wp:cover containing wp:image) should also
		// have their IDs discovered.
		$attachment  = (object) array(
			'ID'             => 88,
			'post_type'      => 'attachment',
			'post_title'     => 'cover',
			'post_excerpt'   => '',
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/jpeg',
		);
		$blocks_tree = array(
			array(
				'blockName'   => 'core/cover',
				'attrs'       => array( 'overlayColor' => 'primary' ),
				'innerBlocks' => array(
					array(
						'blockName'   => 'core/image',
						'attrs'       => array( 'id' => 88 ),
						'innerBlocks' => array(),
					),
				),
			),
		);
		$c           = new AttachmentRefCapturer(
			static fn ( string $key ): mixed => null,
			static fn ( int $id ): ?object => 88 === $id ? $attachment : null,
			static fn ( int $id, string $key ): mixed => '_wp_attached_file' === $key ? '2026/05/cover.jpg' : '',
			static fn ( string $content ): array => $blocks_tree,
			'/tmp/nonexistent'
		);

		$out = $c->capture(
			new SnapshotScope( post_types_owned: array( 'wp_template' ) ),
			array(
				'wp_template' => array(
					'home' => array( 'post_content' => '<!-- wp:cover -->...<!-- /wp:cover -->' ),
				),
			)
		);

		$this->assertArrayHasKey( '2026/05/cover.jpg', $out['by_file'] );
	}
}
