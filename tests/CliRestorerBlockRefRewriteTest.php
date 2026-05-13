<?php
/**
 * Unit tests for FrankenPress\Cli\Apply\Restorer's
 * rewrite_block_ref_ids_in_content() — the wp:block "ref":N remap
 * applied to non-wp_block owned-post content at apply time.
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

final class CliRestorerBlockRefRewriteTest extends TestCase {

	public function test_rewrites_ref_attr_in_wp_block(): void {
		$content = '<!-- wp:block {"ref":42} /-->';
		$out     = $this->rewrite( $content, array( 42 => 7 ) );
		$this->assertStringContainsString( '"ref":7', $out );
		$this->assertStringNotContainsString( '"ref":42', $out );
	}

	public function test_rewrites_multiple_wp_block_refs(): void {
		$content = <<<'HTML'
<!-- wp:group -->
<!-- wp:block {"ref":42} /-->
<!-- wp:block {"ref":50} /-->
<!-- wp:block {"ref":60} /-->
<!-- /wp:group -->
HTML;
		$remap   = array(
			42 => 7,
			50 => 8,
			60 => 9,
		);
		$out     = $this->rewrite( $content, $remap );
		$this->assertStringContainsString( '"ref":7', $out );
		$this->assertStringContainsString( '"ref":8', $out );
		$this->assertStringContainsString( '"ref":9', $out );
		$this->assertStringNotContainsString( '"ref":42', $out );
		$this->assertStringNotContainsString( '"ref":50', $out );
		$this->assertStringNotContainsString( '"ref":60', $out );
	}

	public function test_leaves_unresolved_ref_untouched(): void {
		// A captured ref NOT in the remap (e.g. the snapshotted wp_block
		// failed to upsert locally). The captured id stays — better than
		// stamping a wrong local id and certainly better than wiping the
		// markup.
		$content = '<!-- wp:block {"ref":42} /-->';
		$out     = $this->rewrite( $content, array( 50 => 8 ) );
		$this->assertSame( $content, $out );
	}

	public function test_passthrough_on_empty_remap(): void {
		$content = '<!-- wp:block {"ref":42} /--><!-- wp:image {"id":33} -->';
		$this->assertSame( $content, $this->rewrite( $content, array() ) );
	}

	public function test_does_not_touch_non_wp_block_refs(): void {
		// A `"ref":N` attribute inside a non-wp:block delimiter (e.g.
		// wp:navigation `{"ref":4}` referencing a wp_navigation post)
		// must NOT be rewritten by this method. The nav-link rewriter
		// handles wp:navigation refs separately if/when relevant.
		$content = '<!-- wp:navigation {"ref":4,"overlayBackgroundColor":"base"} /--><!-- wp:block {"ref":42} /-->';
		$out     = $this->rewrite(
			$content,
			array(
				4  => 999,
				42 => 7,
			)
		);
		$this->assertStringContainsString( '"ref":4', $out, 'nav ref untouched' );
		$this->assertStringContainsString( '"ref":7', $out, 'wp:block ref remapped' );
		$this->assertStringNotContainsString( '"ref":42', $out, 'old wp:block ref gone' );
	}

	public function test_handles_whitespace_around_colon(): void {
		$content = '<!-- wp:block {"ref" :  42} /-->';
		$out     = $this->rewrite( $content, array( 42 => 7 ) );
		$this->assertStringContainsString( '7', $out );
		$this->assertStringNotContainsString( '"ref" :  42', $out );
	}

	private function rewrite( string $content, array $remap ): string {
		$snapshot_dir = sys_get_temp_dir() . '/fp-block-ref-rewrite-test-' . uniqid();
		mkdir( $snapshot_dir, 0755, true );

		$restorer = new Restorer(
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
			static fn ( string $slug, string $type ): ?int => null,
			static fn ( string $pt, ?string $theme ): array => array(),
			static function (): void {},
			'/tmp/uploads',
			'http://target.example/app/uploads',
		);

		$method = new ReflectionMethod( Restorer::class, 'rewrite_block_ref_ids_in_content' );
		$result = (string) $method->invoke( $restorer, $content, $remap );

		rmdir( $snapshot_dir );
		return $result;
	}
}
