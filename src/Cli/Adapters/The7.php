<?php
/**
 * The7 premium-theme adapter for `wp fp snapshot` / `wp fp apply`.
 *
 * The7 (dt-the7) ships a "Pre-Made Website Templates" importer that
 * downloads + activates plugins, imports XML content, and stamps a
 * `the7_demo_history` option to flag which demos have been imported.
 * Without that flag set on the apply side, The7's UI re-prompts the
 * designer to import even though the content is already in place. With
 * the flag set on a fresh DB *without* the imported content, the UI
 * goes silent and the designer can't tell what state they're in.
 *
 * So the adapter does two things:
 *
 *   - Capture: read `the7_demo_history` at snapshot time, embed it
 *     into the manifest under `adapter_state.the7.demo_history`.
 *   - Apply: write the captured `the7_demo_history` back into wp_options
 *     after the SQL import, then trigger The7's hash-gated dynamic-CSS
 *     regeneration (the7_maybe_regenerate_dynamic_css) so the
 *     environment-specific CSS gets rebuilt for the apply env's URL.
 *
 * Phase 0 is a concrete class (no interface yet). Phase 4 extracts a
 * stable AdapterInterface with detect / sanitise / post_restore methods
 * and adds Avada / Divi adapters alongside.
 *
 * @package FrankenPress\Cli\Adapters
 */

declare(strict_types=1);

namespace FrankenPress\Cli\Adapters;

final class The7 {

	public const NAME       = 'the7';
	public const THEME_SLUG = 'dt-the7';

	/**
	 * Returns true when The7 is the active theme — gated detect() so the
	 * snapshot / apply pipelines can skip this adapter cheaply on sites
	 * that don't use The7.
	 */
	public function detect(): bool {
		if ( ! function_exists( 'wp_get_theme' ) ) {
			return false;
		}
		$theme = wp_get_theme();
		// `get_template()` returns the parent theme slug — important for
		// designers using a The7 child theme.
		return self::THEME_SLUG === $theme->get_template();
	}

	/**
	 * Read The7-specific state from wp_options. Embedded under
	 * `adapter_state.the7` in the manifest.
	 *
	 * @return array<string, mixed>
	 */
	public function capture(): array {
		$state = array();

		if ( function_exists( 'get_option' ) ) {
			$demo_history = get_option( 'the7_demo_history' );
			if ( ! empty( $demo_history ) ) {
				$state['demo_history'] = $demo_history;
			}

			// `the7_dashboard_settings` carries designer-tweaked theme
			// options (font choices, accent colours). Already in the SQL
			// dump, but echoing the option here gives the engineer a
			// human-readable summary in manifest review.
			$dashboard = get_option( 'the7_dashboard_settings' );
			if ( ! empty( $dashboard ) && is_array( $dashboard ) ) {
				$state['dashboard_settings_keys'] = array_keys( $dashboard );
			}
		}

		return $state;
	}

	/**
	 * Apply-side hook: re-set `the7_demo_history` from manifest state
	 * (the SQL import will have done this already, but if a future
	 * change to the sanitiser stripped it, this is the safety net) and
	 * trigger dynamic-CSS regen so the apply environment's URL is baked
	 * into the cached CSS.
	 *
	 * @param array<string, mixed> $state The captured adapter_state.the7 sub-tree.
	 */
	public function post_restore( array $state ): void {
		if ( ! function_exists( 'update_option' ) ) {
			return;
		}

		if ( isset( $state['demo_history'] ) && ! empty( $state['demo_history'] ) ) {
			update_option( 'the7_demo_history', $state['demo_history'], false );
		}

		// Force CSS regen even if The7's hash-gate thinks it's already
		// done — the captured snapshot's hash may match the source URL,
		// not the target URL.
		if ( function_exists( 'delete_option' ) ) {
			delete_option( 'the7_last_dynamic_stylesheets_hash' );
		}
		if ( function_exists( 'the7_maybe_regenerate_dynamic_css' ) ) {
			the7_maybe_regenerate_dynamic_css();
		}
	}
}
