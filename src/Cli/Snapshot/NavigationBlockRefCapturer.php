<?php
/**
 * Navigation block-ref capturer — for each `wp_navigation` post,
 * walks its block content and records the local page IDs referenced
 * by `wp:navigation-link` / `wp:navigation-submenu` blocks alongside
 * each page's slug + post_type.
 *
 * The captured `block_refs` map is emitted under each wp_navigation
 * entry in templates.json:
 *
 *   "wp_navigation": {
 *     "navigation": {
 *       "post_title":   "Main Nav",
 *       "post_content": "<!-- wp:navigation-link {\"id\":42,...} /-->...",
 *       "post_status":  "publish",
 *       "post_excerpt": "",
 *       "block_refs":   {
 *         "42": { "slug": "about",    "type": "page" },
 *         "50": { "slug": "services", "type": "page" }
 *       }
 *     }
 *   }
 *
 * Why: `wp:navigation-link` / `wp:navigation-submenu` blocks carry an
 * `"id":N` attr where N is a local post ID. Captured naïvely, that ID
 * lands on the target unchanged — but page IDs aren't portable across
 * environments, so the rendered menu link points at the wrong post
 * (or nothing) on stg/prd. Recording the slug + post_type lets the
 * apply path look up the target's local ID by slug (via the existing
 * page_finder seam from Phase 2.1) and rewrite the block markup.
 *
 * Capture is lossy-safe: if the captured ID doesn't resolve to a post
 * locally (the source itself has a broken nav link), the ref is
 * omitted and apply leaves the captured ID untouched + emits a
 * warning rather than swallowing the issue.
 *
 * @package FrankenPress\Cli\Snapshot
 */

declare(strict_types=1);

namespace FrankenPress\Cli\Snapshot;

final class NavigationBlockRefCapturer {

	/**
	 * Block names whose `id` attribute is a local post reference that
	 * needs slug-based remap on apply. Catalogued explicitly so an
	 * unknown block attr (e.g. a wp:cover with `"id":<attachment>`
	 * inside a nav post) doesn't get confused with a page ref.
	 */
	private const PAGE_REF_BLOCK_NAMES = array( 'core/navigation-link', 'core/navigation-submenu' );

	/**
	 * @param callable $blocks_parser Function (string $content): array —
	 *     wraps WP core's `parse_blocks()` so tests can inject a fake
	 *     block tree without loading WP.
	 * @param callable $page_resolver Function (int $post_id): ?array —
	 *     given a post ID, returns ['slug' => ..., 'type' => ...] or
	 *     null when the post doesn't exist locally. Production caller
	 *     wraps `get_post()->post_name + post_type`.
	 */
	public function __construct(
		private $blocks_parser,
		private $page_resolver,
	) {}

	/**
	 * Walk the block tree extracted from a wp_navigation post's
	 * `post_content` and return a map of `captured_id => {slug, type}`
	 * for every nav-link / nav-submenu block whose `id` resolves to a
	 * local post. Captured IDs that don't resolve are omitted.
	 *
	 * @return array<int, array{slug: string, type: string}>
	 */
	public function capture_refs( string $post_content ): array {
		if ( '' === $post_content ) {
			return array();
		}
		$blocks = ( $this->blocks_parser )( $post_content );
		if ( ! is_array( $blocks ) ) {
			return array();
		}
		$out = array();
		$this->walk_blocks( $blocks, $out );
		ksort( $out );
		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>>                          $blocks
	 * @param array<int, array{slug: string, type: string}>             &$out
	 */
	private function walk_blocks( array $blocks, array &$out ): void {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
			if ( in_array( $name, self::PAGE_REF_BLOCK_NAMES, true ) ) {
				$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
				$id    = isset( $attrs['id'] ) ? (int) $attrs['id'] : 0;
				if ( $id > 0 && ! isset( $out[ $id ] ) ) {
					$ref = ( $this->page_resolver )( $id );
					if ( is_array( $ref ) ) {
						$slug = (string) ( $ref['slug'] ?? '' );
						$type = (string) ( $ref['type'] ?? '' );
						if ( '' !== $slug && '' !== $type ) {
							$out[ $id ] = array(
								'slug' => $slug,
								'type' => $type,
							);
						}
					}
				}
			}
			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$this->walk_blocks( $block['innerBlocks'], $out );
			}
		}
	}
}
