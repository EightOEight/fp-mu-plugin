<?php
/**
 * Site Health tweaks for the FrankenPress immutable-image lockdown.
 *
 * WordPress's Site Health screen runs a set of tests that check whether
 * the site can do things like auto-update WP core, write to its own
 * filesystem for plugin / theme installs, and back up files before
 * applying updates. On a stock self-hosted WP install, all of those
 * tests passing is a good signal.
 *
 * On a FrankenPress site they all *fail* — and that's correct. The
 * platform's whole point is that the site image is immutable: code is
 * baked at build time, the runtime filesystem is read-only, and
 * auto-updates are hard-disabled because anything they wrote would
 * land on ephemeral container disk and disappear on the next pod
 * restart. Releases happen via image rebuild + helm upgrade, never
 * via admin-side installs.
 *
 * That makes the failing Site Health tests pure noise — they flag
 * misconfigurations of the WordPress assumption, not of FrankenPress.
 * This component:
 *
 *  - Removes the four lockdown-related tests so they no longer report
 *    as errors:
 *      * `background_updates`        (auto-update probe)
 *      * `update_temp_backup_writable` (FS write probe for backups)
 *      * `available_updates_disk_space` (disk-headroom probe)
 *      * `plugin_theme_auto_updates`   (auto-update probe for plugins)
 *  - Adds one passing FrankenPress-branded test that explains *why*
 *    the lockdown is in place, so the dashboard says something useful
 *    instead of just being quiet.
 *
 * Side-effect-free constructor; hook registration happens in
 * bootstrap(). Mirrors the pattern used by S3UploadsBootstrap and
 * SouinInvalidator so the component is unit-testable in isolation.
 *
 * @package FrankenPress
 */

declare(strict_types=1);

namespace FrankenPress;

final class SiteHealth {

	/**
	 * Test IDs whose failure is intentional under the FrankenPress
	 * lockdown. Removed from the Site Health test list so they don't
	 * report as errors.
	 *
	 * @var array<string, list<string>>
	 */
	private const SUPPRESS = array(
		'async'  => array(
			'background_updates',
		),
		'direct' => array(
			'update_temp_backup_writable',
			'available_updates_disk_space',
			'plugin_theme_auto_updates',
		),
	);

	public function bootstrap(): void {
		add_filter( 'site_status_tests', array( $this, 'tweak_tests' ), 10, 1 );
	}

	/**
	 * Filter callback: drop the lockdown-related tests, add the
	 * FrankenPress passing test in their place.
	 *
	 * @param array<string, array<string, mixed>> $tests
	 * @return array<string, array<string, mixed>>
	 */
	public function tweak_tests( array $tests ): array {
		foreach ( self::SUPPRESS as $bucket => $ids ) {
			foreach ( $ids as $id ) {
				if ( isset( $tests[ $bucket ][ $id ] ) ) {
					unset( $tests[ $bucket ][ $id ] );
				}
			}
		}

		$tests['direct']['frankenpress_lockdown'] = array(
			'label' => __( 'FrankenPress immutable-image lockdown', 'fp-mu-plugin' ),
			'test'  => array( $this, 'test_lockdown' ),
		);

		return $tests;
	}

	/**
	 * Test callback: always returns a passing result that explains the
	 * lockdown for site administrators reading the Site Health page.
	 *
	 * @return array<string, mixed>
	 */
	public function test_lockdown(): array {
		return array(
			'label'       => __( 'FrankenPress lockdown is active by design', 'fp-mu-plugin' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Security', 'fp-mu-plugin' ),
				'color' => 'green',
			),
			'description' => sprintf(
				'<p>%s</p><p>%s</p>',
				esc_html__(
					'This site runs on the FrankenPress platform. The container image is immutable and is the source of truth for site code: WordPress core, plugins, themes, and custom code are all baked at build time. The runtime filesystem is read-only.',
					'fp-mu-plugin'
				),
				esc_html__(
					'As a result, auto-updates, in-admin plugin/theme installation, and admin-side file writes are intentionally disabled — they would land on ephemeral container disk and disappear on the next pod restart, or replicate inconsistently across replicas. Releases happen by rebuilding the site image and rolling out via Helm. The Site Health tests that check for those capabilities have been suppressed so this dashboard reports signal, not noise.',
					'fp-mu-plugin'
				)
			),
			'actions'     => '',
			'test'        => 'frankenpress_lockdown',
		);
	}
}
