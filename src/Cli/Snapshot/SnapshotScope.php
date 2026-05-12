<?php
/**
 * Snapshot scope — what an adapter declares is its blast radius for
 * `wp fp snapshot`.
 *
 * The scope is the answer to "what does this snapshot touch?" The
 * capture path consults the active adapter's scope to decide which
 * posts go into the WXR, which `wp_options` rows go into the JSON
 * sidecar, and which theme_mods slugs to include. The snapshot is
 * exactly the scope's footprint — nothing wider, nothing narrower.
 *
 * Critically: anything OUTSIDE the scope is *never* captured. That's
 * the safety property that makes snapshots safe to apply against a
 * live site. A WooCommerce store's `wc_orders` table is never in any
 * adapter's scope, so no snapshot ever carries orders — there is no
 * codepath by which a `fp apply` operation can delete or overwrite
 * them.
 *
 * v3 simplification (vs v2):
 *
 *   - `post_types_with_marker` (premium-theme marker-meta filter) and
 *     `post_types_full_capture` collapsed into a single flat
 *     `post_types` list. The Fse adapter owns the full row set of every
 *     CPT it declares — no marker needed.
 *   - `option_patterns` (MySQL LIKE globs) replaced by `option_keys`
 *     (explicit list). FSE doesn't need wildcard matching; every option
 *     it touches is known.
 *   - `documented_exclusions` dropped (the manifest schema + docs cover
 *     the safety boundary).
 *
 * @package FrankenPress\Cli\Snapshot
 */

declare(strict_types=1);

namespace FrankenPress\Cli\Snapshot;

final class SnapshotScope {

	/**
	 * @param array<int, string>    $post_types     Post types where every
	 *     row is captured. Example for the Fse adapter:
	 *     `['wp_template', 'wp_template_part', 'wp_global_styles',
	 *     'wp_navigation', 'attachment', 'page', 'post']`.
	 *
	 * @param array<int, string>    $option_keys    Explicit list of
	 *     `wp_options.option_name` values to capture. No wildcard
	 *     patterns — every key is named. Example:
	 *     `['blogname', 'show_on_front', 'page_on_front']`.
	 *
	 * @param array<int, string>    $theme_mods_for Stylesheet slugs whose
	 *     `theme_mods_<slug>` option should be captured. Listed
	 *     separately because theme_mods are special-cased in WP core.
	 */
	public function __construct(
		public readonly array $post_types = array(),
		public readonly array $option_keys = array(),
		public readonly array $theme_mods_for = array(),
	) {}

	/**
	 * Merge this scope with another, returning a new combined scope.
	 * Retained for future multi-adapter composition; v3 ships with a
	 * single registered adapter so in practice this is the identity.
	 */
	public function merged_with( self $other ): self {
		return new self(
			post_types:     array_values( array_unique( array_merge( $this->post_types, $other->post_types ) ) ),
			option_keys:    array_values( array_unique( array_merge( $this->option_keys, $other->option_keys ) ) ),
			theme_mods_for: array_values( array_unique( array_merge( $this->theme_mods_for, $other->theme_mods_for ) ) ),
		);
	}

	/**
	 * Returns true when every field is empty. Used by the capturer to
	 * short-circuit when no adapter fired — emits an explicit error
	 * rather than a silent empty snapshot.
	 */
	public function is_empty(): bool {
		return empty( $this->post_types )
			&& empty( $this->option_keys )
			&& empty( $this->theme_mods_for );
	}
}
