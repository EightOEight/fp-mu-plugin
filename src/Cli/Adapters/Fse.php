<?php
/**
 * FSE (Full-Site Editing) snapshot adapter.
 *
 * The default adapter for FrankenPress sites in v0.10.0+. Captures the
 * design surface that Site Editor produces:
 *
 *   - `wp_template`, `wp_template_part` (FSE block templates)
 *   - `wp_global_styles` (per-theme styles)
 *   - `wp_navigation` (block-based navigation)
 *   - `attachment` (uploaded media; solves the FSE-Corp image-404
 *     follow-up from the v2 era where attachments were out-of-scope)
 *   - `page` and `post` (designer-created content)
 *
 * Plus a curated, explicit `option_keys` list — no glob patterns. FSE
 * doesn't need wildcard matching; every site-identity option it touches
 * is enumerated.
 *
 * Theme-coupling is intentionally minimal: the adapter captures
 * `theme_mods_<active>` for whatever the active theme is at snapshot
 * time, and the {@see post_apply()} hook cleans up `wp_global_styles`
 * orphans whose stylesheet metadata doesn't match the current
 * `get_stylesheet()` (handles theme-switch cleanly).
 *
 * Explicitly NOT in scope (and thus untouchable by any FSE-driven
 * snapshot):
 *
 *   - WooCommerce orders, customer accounts.
 *   - User accounts (`wp_users` / `wp_usermeta`).
 *   - Comments (`wp_comments` / `wp_commentmeta`).
 *   - Activity log / Action Scheduler.
 *   - Any custom CPT not declared above.
 *   - `widget_*` / `sidebars_widgets` (block-based sites don't use
 *     classic widgets; legacy sites that do should add a dedicated
 *     adapter).
 *
 * @package FrankenPress\Cli\Adapters
 */

declare(strict_types=1);

namespace FrankenPress\Cli\Adapters;

use FrankenPress\Cli\Snapshot\SnapshotScope;

final class Fse implements AdapterInterface {

	public const NAME = 'fse';

	public function name(): string {
		return self::NAME;
	}

	/**
	 * Returns true when the active theme is a block theme (FSE-mode).
	 * `wp_is_block_theme()` is the canonical core API for this check;
	 * it's true iff the theme ships `templates/` + `theme.json`.
	 */
	public function detect(): bool {
		return function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
	}

	public function scope(): SnapshotScope {
		return new SnapshotScope(
			post_types_additive: array(
				// User-editable content. WXR-shipped, INSERT-only on
				// apply — existing rows by GUID are preserved.
				'page',
				'post',
				'attachment',
			),
			post_types_owned:    array(
				// Design state the adapter owns end-to-end. Captured to
				// templates.json; apply UPSERTs by post_name+post_type
				// so designer iteration propagates. See iteration-ux.md
				// in the workspace .aidocs/ for the empirical motivation.
				'wp_template',
				'wp_template_part',
				'wp_global_styles',
				'wp_navigation',
			),
			option_keys:         array(
				'blogname',
				'blogdescription',
				'show_on_front',
				'page_on_front',
				'page_for_posts',
				'permalink_structure',
				'site_icon',
				'site_logo',
				'custom_logo',
			),
			theme_mods_for:      $this->active_stylesheet_or_empty(),
		);
	}

	/**
	 * Snapshot-time bookkeeping: capture the source theme's stylesheet
	 * slug so {@see post_apply()} can identify `wp_global_styles`
	 * orphans after a theme switch on the target site.
	 *
	 * @return array<string, mixed>
	 */
	public function capture_state(): array {
		if ( ! function_exists( 'get_stylesheet' ) ) {
			return array();
		}
		$stylesheet = (string) get_stylesheet();
		if ( '' === $stylesheet ) {
			return array();
		}
		return array( 'source_theme' => $stylesheet );
	}

	/**
	 * Apply-side cleanup: remove `wp_global_styles` posts whose
	 * stylesheet metadata doesn't match the current active theme.
	 *
	 * Rationale: `wp_global_styles` is a per-theme CPT (each theme has
	 * its own row, keyed by `_wp_global_styles_<stylesheet>` slug). On
	 * a theme switch (e.g. `dt-the7 → twentytwentyfive`), the old
	 * theme's row becomes inert dead-weight. The snapshot's
	 * `wp_global_styles` import will land the new theme's row cleanly,
	 * but the old row sticks around forever unless we sweep.
	 *
	 * Safety: this is snapshot-managed metadata (the Fse adapter owns
	 * `wp_global_styles` as part of its declared scope), so deletion
	 * here is housekeeping within scope, not a violation of the "no
	 * DROPs of UGC" property. UGC is structurally outside scope and
	 * can't be reached from this codepath.
	 *
	 * @param array<string, mixed> $state
	 */
	public function post_apply( array $state ): void {
		if ( ! function_exists( 'get_stylesheet' ) || ! function_exists( 'get_posts' ) || ! function_exists( 'wp_delete_post' ) ) {
			return;
		}
		$current = (string) get_stylesheet();
		if ( '' === $current ) {
			return;
		}
		$expected_slug = 'wp-global-styles-' . $current;

		$posts = get_posts(
			array(
				'post_type'        => 'wp_global_styles',
				'post_status'      => 'any',
				'posts_per_page'   => -1,
				'suppress_filters' => true,
				'fields'           => 'ids',
			)
		);
		if ( ! is_array( $posts ) ) {
			return;
		}
		foreach ( $posts as $post_id ) {
			$post = function_exists( '\get_post' ) ? \get_post( (int) $post_id ) : null;
			if ( ! is_object( $post ) || ! isset( $post->post_name ) ) {
				continue;
			}
			if ( (string) $post->post_name === $expected_slug ) {
				continue;
			}
			wp_delete_post( (int) $post_id, true );
		}
	}

	/**
	 * @return array<int, string>
	 */
	private function active_stylesheet_or_empty(): array {
		if ( ! function_exists( 'get_stylesheet' ) ) {
			return array();
		}
		$slug = (string) get_stylesheet();
		return '' === $slug ? array() : array( $slug );
	}
}
