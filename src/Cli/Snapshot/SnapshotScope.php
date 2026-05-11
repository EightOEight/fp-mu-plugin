<?php
/**
 * Snapshot scope — what a premium-theme adapter declares is its blast
 * radius for `wp fp snapshot`.
 *
 * The scope is the answer to "what did this theme's importer touch?"
 * The capture path consults each scope to decide which posts go into
 * the WXR, which `wp_options` rows go into the JSON sidecar, and which
 * theme_mods slug to include. The snapshot is the UNION of all fired
 * adapters' scopes — there's no concept of a global scope, every byte
 * captured is claimed by an adapter.
 *
 * Critically: anything OUTSIDE an adapter's scope is *never* captured.
 * That's the safety property that makes snapshots safe to apply
 * against a live site. A WooCommerce store's `wc_orders` table is
 * never in any theme adapter's scope, so no snapshot ever carries
 * orders — there is no codepath by which a `fp promote` operation
 * can delete or overwrite them.
 *
 * Phase 4 of the fp design extends this with adapter-declared
 * `sensitive_options` allowlists for redaction within scope; v0.8.0
 * keeps the field set tight.
 *
 * @package FrankenPress\Cli\Snapshot
 */

declare(strict_types=1);

namespace FrankenPress\Cli\Snapshot;

final class SnapshotScope {

	/**
	 * @param array<string, string>  $post_types_with_marker  Map of
	 *     post_type => required postmeta key. Only posts whose
	 *     `postmeta.meta_key` matches the value AND whose post_type
	 *     matches the key are captured. Example: `['page' =>
	 *     '_the7_imported_item']` captures pages that The7's importer
	 *     stamped, leaves pages written by editors alone.
	 *
	 * @param array<int, string>  $post_types_full_capture  Post types
	 *     where every row is captured (no marker filter). Use sparingly
	 *     — only for CPTs whose entire row set is part of the design
	 *     state (nav_menu_item, wp_template, wp_template_part,
	 *     wp_global_styles).
	 *
	 * @param array<int, string>  $option_patterns  MySQL LIKE patterns
	 *     for `wp_options.option_name`. Example: `['the7_%',
	 *     'elementor_%']` captures every option whose name starts
	 *     with those prefixes. Matched options end up in the JSON
	 *     sidecar of the snapshot.
	 *
	 * @param array<int, string>  $theme_mods_for  Stylesheet slugs
	 *     whose `theme_mods_<slug>` option should be captured. Listed
	 *     separately because theme_mods are special-cased throughout
	 *     WP and merit their own field.
	 *
	 * @param array<int, string>  $documented_exclusions  Tables this
	 *     adapter explicitly never touches. Documentation-only —
	 *     emitted into the manifest so the engineer reviewing a
	 *     promote PR sees a clear "these are NOT touched by this
	 *     snapshot" footer. Example: `['wc_orders',
	 *     'wc_order_*', 'wp_comments', 'wp_users*']`.
	 */
	public function __construct(
		public readonly array $post_types_with_marker = array(),
		public readonly array $post_types_full_capture = array(),
		public readonly array $option_patterns = array(),
		public readonly array $theme_mods_for = array(),
		public readonly array $documented_exclusions = array(),
	) {}

	/**
	 * Merge this scope with another, returning a new combined scope.
	 * Used when multiple adapters fire on the same site (e.g. The7 +
	 * Elementor).
	 */
	public function merged_with( self $other ): self {
		return new self(
			post_types_with_marker:  $this->post_types_with_marker + $other->post_types_with_marker,
			post_types_full_capture: array_values( array_unique( array_merge( $this->post_types_full_capture, $other->post_types_full_capture ) ) ),
			option_patterns:         array_values( array_unique( array_merge( $this->option_patterns, $other->option_patterns ) ) ),
			theme_mods_for:          array_values( array_unique( array_merge( $this->theme_mods_for, $other->theme_mods_for ) ) ),
			documented_exclusions:   array_values( array_unique( array_merge( $this->documented_exclusions, $other->documented_exclusions ) ) ),
		);
	}

	/**
	 * Returns true when every field is empty. Used by the capturer to
	 * short-circuit when no adapters fired — emits an explicit error
	 * rather than a silent empty snapshot.
	 */
	public function is_empty(): bool {
		return empty( $this->post_types_with_marker )
			&& empty( $this->post_types_full_capture )
			&& empty( $this->option_patterns )
			&& empty( $this->theme_mods_for );
	}
}
