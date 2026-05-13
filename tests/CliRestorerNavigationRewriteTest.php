<?php
/**
 * Unit tests for the v0.14 wp:navigation-link / wp:navigation-submenu
 * id rewriter in FrankenPress\Cli\Apply\Restorer.
 *
 * Two methods under test:
 *
 *   resolve_block_refs_to_local_ids(array $block_refs): array<int, int>
 *   rewrite_navigation_link_ids_in_content(string $content, array $page_id_remap): string
 *
 * Reached via reflection.
 *
 * @package FrankenPress\Tests
 */

declare(strict_types=1);

namespace FrankenPress\Tests;

use FrankenPress\Cli\Apply\Restorer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CliRestorerNavigationRewriteTest extends TestCase {

	public function test_resolve_block_refs_returns_local_ids_when_slug_found(): void {
		$block_refs  = array(
			42 => array(
				'slug' => 'about',
				'type' => 'page',
			),
			50 => array(
				'slug' => 'services',
				'type' => 'page',
			),
		);
		$page_finder = static function ( string $slug, string $type ): ?int {
			$map = array(
				'about'    => 5,
				'services' => 7,
			);
			return $map[ $slug ] ?? null;
		};

		$out = $this->resolve_refs( $block_refs, $page_finder );

		$this->assertSame(
			array(
				42 => 5,
				50 => 7,
			),
			$out
		);
	}

	public function test_resolve_block_refs_omits_unresolvable_slugs(): void {
		// Captured slug doesn't exist on target — omitted from the remap.
		// The rewriter then leaves the corresponding `id` attr untouched.
		$block_refs  = array(
			42 => array(
				'slug' => 'gone',
				'type' => 'page',
			),
			50 => array(
				'slug' => 'about',
				'type' => 'page',
			),
		);
		$page_finder = static function ( string $slug, string $type ): ?int {
			return 'about' === $slug ? 5 : null;
		};

		$out = $this->resolve_refs( $block_refs, $page_finder );

		$this->assertSame( array( 50 => 5 ), $out );
	}

	public function test_rewrite_replaces_id_in_navigation_link_block(): void {
		$content = '<!-- wp:navigation-link {"id":42,"kind":"post-type","type":"page","url":"/about"} /-->';
		$out     = $this->rewrite( $content, array( 42 => 5 ) );
		$this->assertStringContainsString( '"id":5', $out );
		$this->assertStringNotContainsString( '"id":42', $out );
	}

	public function test_rewrite_replaces_id_in_navigation_submenu_block(): void {
		$content = '<!-- wp:navigation-submenu {"id":10,"type":"page","label":"Services"} -->...<!-- /wp:navigation-submenu -->';
		$out     = $this->rewrite( $content, array( 10 => 99 ) );
		$this->assertStringContainsString( '"id":99', $out );
	}

	public function test_rewrite_handles_multiple_nav_links(): void {
		$content = <<<'HTML'
<!-- wp:navigation -->
<!-- wp:navigation-link {"id":42,"type":"page","url":"/about"} /-->
<!-- wp:navigation-link {"id":50,"type":"page","url":"/services"} /-->
<!-- wp:navigation-submenu {"id":60,"type":"page","label":"More"} -->
<!-- wp:navigation-link {"id":70,"type":"page","url":"/more/contact"} /-->
<!-- /wp:navigation-submenu -->
<!-- /wp:navigation -->
HTML;
		$remap   = array(
			42 => 5,
			50 => 7,
			60 => 9,
			70 => 11,
		);
		$out     = $this->rewrite( $content, $remap );
		$this->assertStringContainsString( '"id":5', $out );
		$this->assertStringContainsString( '"id":7', $out );
		$this->assertStringContainsString( '"id":9', $out );
		$this->assertStringContainsString( '"id":11', $out );
		// All captured IDs replaced.
		$this->assertStringNotContainsString( '"id":42', $out );
		$this->assertStringNotContainsString( '"id":50', $out );
		$this->assertStringNotContainsString( '"id":60', $out );
		$this->assertStringNotContainsString( '"id":70', $out );
	}

	public function test_rewrite_leaves_unresolved_ids_untouched(): void {
		// A captured ID NOT in the remap (because page_finder couldn't
		// find its slug on the target) stays as-is — better to keep the
		// dangling link than silently delete it; URL search-replace
		// still rewrites the href to the target host.
		$content = '<!-- wp:navigation-link {"id":42,"type":"page","url":"/missing"} /-->';
		$out     = $this->rewrite( $content, array() ); // empty remap
		$this->assertSame( $content, $out );
	}

	public function test_rewrite_does_not_touch_non_navigation_blocks(): void {
		// A wp:image inside a nav post (rare, but possible). The image
		// `id` attr must NOT be touched by this rewriter — that's
		// rewrite_attachment_ids_in_content's job.
		$content = <<<'HTML'
<!-- wp:image {"id":33} -->
<figure class="wp-block-image"><img src="..." class="wp-image-33"/></figure>
<!-- /wp:image -->
<!-- wp:navigation-link {"id":42,"type":"page"} /-->
HTML;
		// Both 33 and 42 are in the page_id_remap (theoretically), but
		// only the nav-link should be rewritten.
		$out = $this->rewrite(
			$content,
			array(
				33 => 999,
				42 => 5,
			)
		);
		$this->assertStringContainsString( '"id":33', $out, 'image id stayed untouched' );
		$this->assertStringContainsString( 'wp-image-33', $out, 'image CSS class stayed untouched' );
		$this->assertStringContainsString( '"id":5', $out, 'nav-link id was rewritten' );
	}

	public function test_rewrite_passthrough_on_empty_remap(): void {
		$content = '<!-- wp:navigation-link {"id":42,"type":"page"} /-->';
		$this->assertSame( $content, $this->rewrite( $content, array() ) );
	}

	private function resolve_refs( array $block_refs, callable $page_finder ): array {
		$restorer = $this->build_restorer( $page_finder );
		$method   = new ReflectionMethod( Restorer::class, 'resolve_block_refs_to_local_ids' );
		return (array) $method->invoke( $restorer, $block_refs );
	}

	private function rewrite( string $content, array $page_id_remap ): string {
		$restorer = $this->build_restorer( static fn (): ?int => null );
		$method   = new ReflectionMethod( Restorer::class, 'rewrite_navigation_link_ids_in_content' );
		return (string) $method->invoke( $restorer, $content, $page_id_remap );
	}

	private function build_restorer( callable $page_finder ): Restorer {
		$snapshot_dir = sys_get_temp_dir() . '/fp-nav-rewrite-test-' . uniqid();
		mkdir( $snapshot_dir, 0755, true );
		return new Restorer(
			$snapshot_dir,
			'http://target.example',
			array(),
			static fn ( string $cmd, array $assoc ): mixed => null,
			static fn ( string $key ): mixed => null,
			static fn ( string $key, mixed $value, bool $autoload ): bool => true,
			static function (): void {},
			static fn ( string $pt, string $slug, array $terms = array() ): ?int => null,
			static function (): void {},
			static fn (): int => 0,
			static fn ( string $rel ): ?int => null,
			static function (): void {},
			static fn (): int => 0,
			$page_finder,
			static fn ( string $pt, ?string $theme ): array => array(),
			static function (): void {},
			'/tmp/uploads',
			'http://target.example/app/uploads',
		);
	}
}
