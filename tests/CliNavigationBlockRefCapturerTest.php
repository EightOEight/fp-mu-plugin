<?php
/**
 * Unit tests for FrankenPress\Cli\Snapshot\NavigationBlockRefCapturer.
 *
 * @package FrankenPress\Tests
 */

declare(strict_types=1);

namespace FrankenPress\Tests;

use FrankenPress\Cli\Snapshot\NavigationBlockRefCapturer;
use PHPUnit\Framework\TestCase;

final class CliNavigationBlockRefCapturerTest extends TestCase {

	public function test_capture_collects_id_from_navigation_link_block(): void {
		$blocks = array(
			array(
				'blockName'   => 'core/navigation-link',
				'attrs'       => array(
					'id'   => 42,
					'kind' => 'post-type',
					'type' => 'page',
					'url'  => '/about',
				),
				'innerBlocks' => array(),
			),
		);
		$pages  = array(
			42 => array(
				'slug' => 'about',
				'type' => 'page',
			),
		);
		$cap    = $this->capturer( $blocks, $pages );

		$refs = $cap->capture_refs( '<!-- wp:navigation-link {"id":42} /-->' );

		$this->assertSame(
			array(
				42 => array(
					'slug' => 'about',
					'type' => 'page',
				),
			),
			$refs
		);
	}

	public function test_capture_walks_inner_blocks_in_submenu(): void {
		// wp:navigation-submenu contains nested wp:navigation-link blocks.
		// Capture must recurse into innerBlocks AND record the submenu's
		// own id.
		$blocks = array(
			array(
				'blockName'   => 'core/navigation-submenu',
				'attrs'       => array(
					'id'   => 10,
					'type' => 'page',
				),
				'innerBlocks' => array(
					array(
						'blockName'   => 'core/navigation-link',
						'attrs'       => array(
							'id'   => 20,
							'type' => 'page',
						),
						'innerBlocks' => array(),
					),
					array(
						'blockName'   => 'core/navigation-link',
						'attrs'       => array(
							'id'   => 30,
							'type' => 'page',
						),
						'innerBlocks' => array(),
					),
				),
			),
		);
		$pages  = array(
			10 => array(
				'slug' => 'services',
				'type' => 'page',
			),
			20 => array(
				'slug' => 'web-design',
				'type' => 'page',
			),
			30 => array(
				'slug' => 'seo',
				'type' => 'page',
			),
		);

		$refs = $this->capturer( $blocks, $pages )->capture_refs( '<nav>...</nav>' );

		$this->assertSame(
			array(
				10 => array(
					'slug' => 'services',
					'type' => 'page',
				),
				20 => array(
					'slug' => 'web-design',
					'type' => 'page',
				),
				30 => array(
					'slug' => 'seo',
					'type' => 'page',
				),
			),
			$refs
		);
	}

	public function test_capture_skips_non_navigation_blocks(): void {
		// A nav post containing a wp:spacer (or any non-navigation
		// block) — the spacer's `id` attr (if it had one) must NOT
		// be collected.
		$blocks = array(
			array(
				'blockName' => 'core/spacer',
				'attrs'     => array( 'id' => 999 ),
			),
			array(
				'blockName' => 'core/navigation-link',
				'attrs'     => array(
					'id'   => 5,
					'type' => 'page',
				),
			),
		);
		$pages  = array(
			5   => array(
				'slug' => 'home',
				'type' => 'page',
			),
			999 => array(
				'slug' => 'shouldnt-appear',
				'type' => 'page',
			),
		);

		$refs = $this->capturer( $blocks, $pages )->capture_refs( '<nav>...</nav>' );

		$this->assertArrayHasKey( 5, $refs );
		$this->assertArrayNotHasKey( 999, $refs );
	}

	public function test_capture_skips_refs_that_dont_resolve(): void {
		// Captured nav-link points at a post that doesn't exist locally
		// (e.g. it was deleted before snapshot). page_resolver returns
		// null. Ref is omitted.
		$blocks = array(
			array(
				'blockName' => 'core/navigation-link',
				'attrs'     => array(
					'id'   => 42,
					'type' => 'page',
				),
			),
		);
		$pages  = array(); // 42 is not present

		$refs = $this->capturer( $blocks, $pages )->capture_refs( '<nav>...</nav>' );

		$this->assertSame( array(), $refs );
	}

	public function test_capture_returns_empty_for_empty_content(): void {
		$refs = $this->capturer( array(), array() )->capture_refs( '' );
		$this->assertSame( array(), $refs );
	}

	public function test_capture_handles_blocks_with_missing_id_attr(): void {
		// wp:navigation-link with no `id` (e.g. an external URL link).
		// Capture must NOT crash; just skip the ref.
		$blocks = array(
			array(
				'blockName' => 'core/navigation-link',
				'attrs'     => array(
					'kind' => 'custom',
					'url'  => 'https://example.com',
				),
			),
		);

		$refs = $this->capturer( $blocks, array() )->capture_refs( '<nav>...</nav>' );

		$this->assertSame( array(), $refs );
	}

	public function test_capture_dedups_duplicate_ids(): void {
		// Same page referenced twice in the menu — record once.
		$blocks = array(
			array(
				'blockName' => 'core/navigation-link',
				'attrs'     => array(
					'id'   => 42,
					'type' => 'page',
				),
			),
			array(
				'blockName' => 'core/navigation-link',
				'attrs'     => array(
					'id'   => 42,
					'type' => 'page',
				),
			),
		);
		$pages  = array(
			42 => array(
				'slug' => 'about',
				'type' => 'page',
			),
		);

		$refs = $this->capturer( $blocks, $pages )->capture_refs( '<nav>...</nav>' );

		$this->assertCount( 1, $refs );
		$this->assertArrayHasKey( 42, $refs );
	}

	/**
	 * @param array<int, array<string, mixed>>                                       $blocks
	 * @param array<int, array{slug: string, type: string}>                          $pages
	 */
	private function capturer( array $blocks, array $pages ): NavigationBlockRefCapturer {
		return new NavigationBlockRefCapturer(
			static fn ( string $content ): array => $blocks,
			static fn ( int $id ): ?array => $pages[ $id ] ?? null,
		);
	}
}
