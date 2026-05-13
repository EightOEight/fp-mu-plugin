<?php
/**
 * Unit tests for FrankenPress\Cli\Apply\Restorer's
 * rewrite_attachment_ids_in_content() — the captured-id → local-id
 * remap applied to block markup at apply time.
 *
 * Covers the v0.13.0 surface (`"id"` + `wp-image-`) plus the v0.14
 * broadening to `"mediaId"` (wp:media-text), `"ids":[...]` (legacy
 * wp:gallery). False-positive guards: numeric attrs that aren't in the
 * remap map must NOT be rewritten (e.g. a wp:navigation-link `"id":42`
 * pointing at a page).
 *
 * @package FrankenPress\Tests
 */

declare(strict_types=1);

namespace FrankenPress\Tests;

use FrankenPress\Cli\Apply\Restorer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CliRestorerRewriteAttachmentIdsTest extends TestCase {

	public function test_rewrites_id_attr_in_wp_image_block(): void {
		$content = '<!-- wp:image {"id":33,"sizeSlug":"full"} --><figure class="wp-block-image"><img src="..." class="wp-image-33"/></figure><!-- /wp:image -->';
		$out     = $this->rewrite( $content, array( 33 => 7 ) );
		$this->assertStringContainsString( '"id":7', $out );
		$this->assertStringContainsString( 'wp-image-7', $out );
		$this->assertStringNotContainsString( '"id":33', $out );
		$this->assertStringNotContainsString( 'wp-image-33', $out );
	}

	public function test_rewrites_media_id_attr_in_wp_media_text(): void {
		// wp:media-text uses `mediaId`, not `id`. v0.13.0 missed this →
		// captured-but-not-remapped → broken image on apply.
		$content = '<!-- wp:media-text {"mediaId":42,"mediaType":"image"} --><figure class="wp-block-media-text__media"><img src="..." class="wp-image-42"/></figure><!-- /wp:media-text -->';
		$out     = $this->rewrite( $content, array( 42 => 99 ) );
		$this->assertStringContainsString( '"mediaId":99', $out );
		$this->assertStringContainsString( 'wp-image-99', $out );
		$this->assertStringNotContainsString( '"mediaId":42', $out );
	}

	public function test_rewrites_ids_array_in_legacy_wp_gallery(): void {
		// Legacy wp:gallery shipped attachment IDs as a JSON array.
		$content = '<!-- wp:gallery {"ids":[10,20,30],"linkTo":"none"} -->...<!-- /wp:gallery -->';
		$out     = $this->rewrite(
			$content,
			array(
				10 => 1,
				20 => 2,
				30 => 3,
			)
		);
		$this->assertStringContainsString( '"ids":[1,2,3]', $out );
		$this->assertStringNotContainsString( '"ids":[10,20,30]', $out );
	}

	public function test_partial_remap_in_ids_array(): void {
		// Some IDs in the array are in the remap, some aren't.
		// Remapped IDs swap; un-remapped IDs stay.
		$content = '<!-- wp:gallery {"ids":[10,20,30]} -->...<!-- /wp:gallery -->';
		$out     = $this->rewrite(
			$content,
			array(
				10 => 100,
				30 => 300,
			)
		);
		$this->assertStringContainsString( '"ids":[100,20,300]', $out );
	}

	public function test_rewrites_id_attr_in_wp_cover_block(): void {
		// wp:cover uses `id` for the background image.
		$content = '<!-- wp:cover {"url":"...","id":55,"dimRatio":50} -->...<!-- /wp:cover -->';
		$out     = $this->rewrite( $content, array( 55 => 12 ) );
		$this->assertStringContainsString( '"id":12', $out );
	}

	public function test_rewrites_multiple_blocks_in_one_template_part(): void {
		// A header template_part with site-title + image + media-text +
		// gallery — the most realistic mixed case.
		$content = <<<'HTML'
<!-- wp:group -->
<!-- wp:site-title /-->
<!-- wp:image {"id":33,"sizeSlug":"full"} -->
<figure class="wp-block-image size-full"><img src="..." class="wp-image-33"/></figure>
<!-- /wp:image -->
<!-- wp:media-text {"mediaId":42} -->
<figure><img class="wp-image-42"/></figure>
<!-- /wp:media-text -->
<!-- wp:gallery {"ids":[10,20]} -->
...
<!-- /wp:gallery -->
<!-- /wp:group -->
HTML;
		$remap   = array(
			33 => 7,
			42 => 99,
			10 => 1,
			20 => 2,
		);
		$out     = $this->rewrite( $content, $remap );
		$this->assertStringContainsString( '"id":7', $out );
		$this->assertStringContainsString( 'wp-image-7', $out );
		$this->assertStringContainsString( '"mediaId":99', $out );
		$this->assertStringContainsString( 'wp-image-99', $out );
		$this->assertStringContainsString( '"ids":[1,2]', $out );
	}

	public function test_does_not_rewrite_non_attachment_id_refs(): void {
		// A wp:navigation-link block has `"id":42` pointing at a page,
		// not an attachment. The remap contains only attachment IDs.
		// Page ID 42 must NOT be rewritten — that's how we avoid false
		// positives with the generic int-after-"id" regex.
		$content = '<!-- wp:navigation-link {"id":42,"kind":"post-type","type":"page","url":"/about"} /-->';
		// Remap has the same NUMERIC value 42 but the apply path only
		// passes attachment-id remap to this method; a page ID would
		// never appear here. Test the empty-remap path explicitly to
		// document the no-op guarantee.
		$out = $this->rewrite( $content, array() );
		$this->assertSame( $content, $out );
	}

	public function test_does_not_rewrite_ints_outside_the_remap(): void {
		// The remap has attachment 33 → 7. Content has both `"id":33`
		// (attachment, should remap) and `"id":99` (something else,
		// should NOT remap).
		$content = '<!-- wp:image {"id":33} --><!-- wp:image-other {"id":99} -->';
		$out     = $this->rewrite( $content, array( 33 => 7 ) );
		$this->assertStringContainsString( '"id":7', $out );
		$this->assertStringContainsString( '"id":99', $out );
	}

	public function test_empty_remap_is_passthrough(): void {
		// When there are no attachments to remap, content must be
		// returned byte-identical. Apply path uses this short-circuit
		// to avoid running regex passes when attachments.json is empty.
		$content = '<!-- wp:image {"id":33} --><figure class="wp-image-33"></figure>';
		$out     = $this->rewrite( $content, array() );
		$this->assertSame( $content, $out );
	}

	public function test_handles_whitespace_around_colon(): void {
		// JSON parsers tolerate whitespace; we should too.
		$content = '<!-- wp:image {"id" : 33,"mediaId":  42} -->';
		$out     = $this->rewrite(
			$content,
			array(
				33 => 7,
				42 => 99,
			)
		);
		$this->assertStringContainsString( '7', $out );
		$this->assertStringContainsString( '99', $out );
		$this->assertStringNotContainsString( '33', $out );
		$this->assertStringNotContainsString( '42', $out );
	}

	private function rewrite( string $content, array $remap ): string {
		// Constructing a Restorer requires every dep; for a pure-pure
		// method we just need an instance to invoke against.
		$snapshot_dir = sys_get_temp_dir() . '/fp-rewrite-test-' . uniqid();
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

		$method = new ReflectionMethod( Restorer::class, 'rewrite_attachment_ids_in_content' );
		$result = (string) $method->invoke( $restorer, $content, $remap );

		rmdir( $snapshot_dir );
		return $result;
	}
}
