<?php
/**
 * The7 premium-theme adapter.
 *
 * Declares the scope The7's "Pre-Made Website Templates" importer
 * touches:
 *
 *   - Pages, posts, and CPTs The7 created (identified by the
 *     `_the7_imported_item` postmeta marker the importer stamps).
 *   - Theme/builder option blobs (`the7_*`, `elementor_*`, widget
 *     configs, custom logo, etc.).
 *   - The `theme_mods_dt-the7` option.
 *   - Nav menu structure (always captured fully, since menus are
 *     small and integral to a design import).
 *
 * Explicitly NOT in scope (and thus untouchable by any The7-driven
 * snapshot):
 *
 *   - WooCommerce orders, customer accounts, products NOT marked by
 *     the importer.
 *   - User accounts (wp_users / wp_usermeta).
 *   - Comments.
 *   - Activity log / Action Scheduler.
 *   - Any custom CPT not declared in scope.
 *
 * @package FrankenPress\Cli\Adapters
 */

declare(strict_types=1);

namespace FrankenPress\Cli\Adapters;

use FrankenPress\Cli\Snapshot\SnapshotScope;

final class The7 implements AdapterInterface {

	public const NAME       = 'the7';
	public const THEME_SLUG = 'dt-the7';

	/**
	 * The postmeta marker The7's importer stamps on every post it
	 * creates. The capture path uses this to distinguish "designer-
	 * imported pages" from "editor-authored pages" — only the former
	 * are snapshotted.
	 */
	public const IMPORTED_ITEM_META = '_the7_imported_item';

	public function name(): string {
		return self::NAME;
	}

	/**
	 * Returns true when The7 is the active theme. `get_template()`
	 * returns the parent theme slug, which is what we want — child
	 * themes of The7 should still register the adapter.
	 */
	public function detect(): bool {
		if ( ! function_exists( 'wp_get_theme' ) ) {
			return false;
		}
		return self::THEME_SLUG === wp_get_theme()->get_template();
	}

	public function scope(): SnapshotScope {
		return new SnapshotScope(
			post_types_with_marker:  array(
				'page'              => self::IMPORTED_ITEM_META,
				'post'              => self::IMPORTED_ITEM_META,
				'dt_portfolio'      => self::IMPORTED_ITEM_META,
				'dt_testimonials'   => self::IMPORTED_ITEM_META,
				'dt_gallery'        => self::IMPORTED_ITEM_META,
				'dt_team'           => self::IMPORTED_ITEM_META,
				'dt_slideshow'      => self::IMPORTED_ITEM_META,
				'elementor_library' => self::IMPORTED_ITEM_META,
			),
			post_types_full_capture: array(
				// Nav menus live as a CPT (nav_menu_item) in WP. Always
				// captured wholesale — menus are small (tens of rows)
				// and replacing the entire menu structure is what
				// designers expect when they "redesigned the nav."
				'nav_menu_item',
				// FSE block templates / template parts that The7 emits
				// for full-site-editing demos.
				'wp_template',
				'wp_template_part',
				'wp_global_styles',
				'wp_navigation',
			),
			option_patterns:         array(
				// The7's own settings + dashboard state.
				'the7_%',
				'optionsframework',
				// Elementor (The7 ships with Elementor integration; many
				// demos are Elementor-built).
				'elementor_%',
				// Widget configs + sidebars.
				'sidebars_widgets',
				'widget_%',
				// Site identity.
				'site_icon',
				'site_logo',
				'custom_logo',
				// blog defaults The7 sometimes flips.
				'show_on_front',
				'page_on_front',
				'page_for_posts',
				// Permalinks (The7 demos often expect /%postname%/).
				'permalink_structure',
				'category_base',
				'tag_base',
			),
			theme_mods_for:          array( self::THEME_SLUG ),
			documented_exclusions:   array(
				'wp_users',
				'wp_usermeta',
				'wp_comments',
				'wp_commentmeta',
				'wc_orders',
				'wc_order_*',
				'wc_customer_*',
				'wp_actionscheduler_*',
				'wp_wc_*',
				'wp_options where option_name does not match the patterns above',
				'wp_posts where post_type does not match the scope above',
				'wp_postmeta for posts outside the scope',
			),
		);
	}

	public function capture_state(): array {
		$state = array();
		if ( ! function_exists( 'get_option' ) ) {
			return $state;
		}

		$demo_history = get_option( 'the7_demo_history' );
		if ( ! empty( $demo_history ) ) {
			$state['demo_history'] = $demo_history;
		}

		$dashboard = get_option( 'the7_dashboard_settings' );
		if ( ! empty( $dashboard ) && is_array( $dashboard ) ) {
			$state['dashboard_settings_keys'] = array_keys( $dashboard );
		}

		return $state;
	}

	/**
	 * Apply-side hook. Runs after the WP-Importer has restored content
	 * + the scoped options have been applied. The7's specific needs:
	 *
	 *   - Re-stamp `the7_demo_history` from the manifest (the options
	 *     sidecar may have applied it already; this is the safety net
	 *     in case the sidecar gets corrupted).
	 *   - Clear the dynamic-CSS hash so the target environment's URL
	 *     gets baked into the cached CSS on next render.
	 *   - Trigger the regen via `the7_maybe_regenerate_dynamic_css()`
	 *     (which is also what the chart's existing `postDeployCommands`
	 *     uses — keep both safe by being idempotent).
	 *
	 * @param array<string, mixed> $state
	 */
	public function post_apply( array $state ): void {
		if ( ! function_exists( 'update_option' ) ) {
			return;
		}

		if ( isset( $state['demo_history'] ) && ! empty( $state['demo_history'] ) ) {
			update_option( 'the7_demo_history', $state['demo_history'], false );
		}

		if ( function_exists( 'delete_option' ) ) {
			delete_option( 'the7_last_dynamic_stylesheets_hash' );
		}
		if ( function_exists( 'the7_maybe_regenerate_dynamic_css' ) ) {
			the7_maybe_regenerate_dynamic_css();
		}
	}
}
