<?php
/**
 * Snapshot scope — what an adapter declares is its blast radius for
 * `wp fp snapshot`.
 *
 * The scope is the answer to "what does this snapshot touch?" The
 * capture path consults the active adapter's scope to decide which
 * posts go into the WXR vs. the templates.json sidecar, which
 * `wp_options` rows go into the JSON sidecar, and which theme_mods
 * slugs to include.
 *
 * Critically: anything OUTSIDE the scope is *never* captured. That's
 * the safety property that makes snapshots safe to apply against a
 * live site. A WooCommerce store's `wc_orders` table is never in any
 * adapter's scope, so no snapshot ever carries orders.
 *
 * v4 split (vs v3):
 *
 *   - `post_types` (flat list) splits into:
 *
 *     - `post_types_additive`  — page, post, attachment. Captured via
 *       WXR; WP-Importer's INSERT-only semantics apply (existing
 *       rows are matched by GUID and SKIPPED, never overwritten).
 *       Right for user-editable content.
 *
 *     - `post_types_owned`     — wp_template, wp_template_part,
 *       wp_global_styles, wp_navigation. Captured into the
 *       `templates.json` sidecar; the apply path UPSERTs by
 *       `post_name + post_type` so designer iteration on existing
 *       rows propagates. Right for design state the adapter owns.
 *
 * Empirical motivation for the split: reproduced 2026-05-12 on
 * docker-compose. Second-apply with v3 silent-skipped FSE-CPT edits
 * (designer iteration didn't propagate). See `iteration-ux.md`.
 *
 * @package FrankenPress\Cli\Snapshot
 */

declare(strict_types=1);

namespace FrankenPress\Cli\Snapshot;

final class SnapshotScope {

	/**
	 * @param array<int, string> $post_types_additive  Post types
	 *     captured via WXR with INSERT-only semantics. Existing rows
	 *     are not touched on apply. Example: `['page', 'post',
	 *     'attachment']`. Reserved for user-editable content.
	 *
	 * @param array<int, string> $post_types_owned     Post types
	 *     captured into `templates.json` with UPSERT semantics keyed
	 *     by `post_name + post_type`. Existing rows are updated;
	 *     missing rows are inserted. Example: `['wp_template',
	 *     'wp_template_part', 'wp_global_styles', 'wp_navigation']`.
	 *     Reserved for design state the adapter owns end-to-end.
	 *
	 * @param array<int, string> $option_keys    Explicit list of
	 *     `wp_options.option_name` values to capture. No wildcard
	 *     patterns — every key is named.
	 *
	 * @param array<int, string> $theme_mods_for Stylesheet slugs whose
	 *     `theme_mods_<slug>` option should be captured.
	 */
	public function __construct(
		public readonly array $post_types_additive = array(),
		public readonly array $post_types_owned = array(),
		public readonly array $option_keys = array(),
		public readonly array $theme_mods_for = array(),
	) {}

	/**
	 * Merge this scope with another, returning a new combined scope.
	 * Retained for future multi-adapter composition; v4 ships with a
	 * single registered adapter so in practice this is the identity.
	 */
	public function merged_with( self $other ): self {
		return new self(
			post_types_additive: array_values( array_unique( array_merge( $this->post_types_additive, $other->post_types_additive ) ) ),
			post_types_owned:    array_values( array_unique( array_merge( $this->post_types_owned, $other->post_types_owned ) ) ),
			option_keys:         array_values( array_unique( array_merge( $this->option_keys, $other->option_keys ) ) ),
			theme_mods_for:      array_values( array_unique( array_merge( $this->theme_mods_for, $other->theme_mods_for ) ) ),
		);
	}

	/**
	 * Returns true when every field is empty. Used by the capturer to
	 * short-circuit when no adapter fired — emits an explicit error
	 * rather than a silent empty snapshot.
	 */
	public function is_empty(): bool {
		return empty( $this->post_types_additive )
			&& empty( $this->post_types_owned )
			&& empty( $this->option_keys )
			&& empty( $this->theme_mods_for );
	}
}
