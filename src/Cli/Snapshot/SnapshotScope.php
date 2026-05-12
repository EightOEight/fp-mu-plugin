<?php
/**
 * Snapshot scope — what an adapter declares is its blast radius for
 * `wp fp snapshot`.
 *
 * The scope is the answer to "what does this snapshot touch?" The
 * capture path consults the active adapter's scope to decide which
 * posts go into the WXR vs. the templates.json sidecar, which
 * `wp_options` rows go into the JSON sidecar, which `theme_mods`
 * slugs to include, and which option values are attachment-ID
 * references whose underlying attachment posts + binary files
 * should be shipped.
 *
 * Critically: anything OUTSIDE the scope is *never* captured. That's
 * the safety property that makes snapshots safe to apply against a
 * live site. A WooCommerce store's `wc_orders` table is never in any
 * adapter's scope, so no snapshot ever carries orders.
 *
 * v4 split + v0.12.0 design/content separation:
 *
 *   - `post_types_additive` — WXR-shipped, INSERT-only. Reserved for
 *     content the editor owns (pages, posts). Default empty for the
 *     Fse adapter post v0.12.0 (see
 *     `feedback_snapshot_design_not_content.md` for rationale).
 *
 *   - `post_types_owned` — captured into `templates.json` with
 *     UPSERT semantics. Reserved for design state the adapter owns
 *     end-to-end (wp_template, wp_template_part, wp_global_styles,
 *     wp_navigation).
 *
 *   - `option_keys` — explicit list of `wp_options.option_name`
 *     values to capture (always upsert).
 *
 *   - `option_keys_attachment_refs` — subset of `option_keys` whose
 *     values are **attachment IDs**. The capturer additionally
 *     captures those attachment posts (post fields + key postmeta)
 *     and their underlying binary files; the apply path upserts the
 *     attachment posts and remaps option values to local IDs.
 *
 *   - `theme_mods_for` — stylesheet slugs whose `theme_mods_<slug>`
 *     option should be captured.
 *
 * @package FrankenPress\Cli\Snapshot
 */

declare(strict_types=1);

namespace FrankenPress\Cli\Snapshot;

final class SnapshotScope {

	/**
	 * @param array<int, string> $post_types_additive  Post types captured via WXR with INSERT-only semantics. Reserved for editor-owned content (page, post). Default empty.
	 * @param array<int, string> $post_types_owned     Post types captured into templates.json with UPSERT semantics. Reserved for adapter-owned design state.
	 * @param array<int, string> $option_keys          Explicit list of `wp_options.option_name` values to capture.
	 * @param array<int, string> $option_keys_attachment_refs  Subset of `option_keys` whose values are attachment IDs. Capture also ships those attachments' posts + binaries; apply remaps the IDs.
	 * @param array<int, string> $theme_mods_for       Stylesheet slugs whose `theme_mods_<slug>` option should be captured.
	 */
	public function __construct(
		public readonly array $post_types_additive = array(),
		public readonly array $post_types_owned = array(),
		public readonly array $option_keys = array(),
		public readonly array $option_keys_attachment_refs = array(),
		public readonly array $theme_mods_for = array(),
	) {}

	/**
	 * Merge this scope with another, returning a new combined scope.
	 * Retained for future multi-adapter composition.
	 */
	public function merged_with( self $other ): self {
		return new self(
			post_types_additive:         array_values( array_unique( array_merge( $this->post_types_additive, $other->post_types_additive ) ) ),
			post_types_owned:             array_values( array_unique( array_merge( $this->post_types_owned, $other->post_types_owned ) ) ),
			option_keys:                  array_values( array_unique( array_merge( $this->option_keys, $other->option_keys ) ) ),
			option_keys_attachment_refs:  array_values( array_unique( array_merge( $this->option_keys_attachment_refs, $other->option_keys_attachment_refs ) ) ),
			theme_mods_for:               array_values( array_unique( array_merge( $this->theme_mods_for, $other->theme_mods_for ) ) ),
		);
	}

	public function is_empty(): bool {
		return empty( $this->post_types_additive )
			&& empty( $this->post_types_owned )
			&& empty( $this->option_keys )
			&& empty( $this->theme_mods_for );
	}
}
