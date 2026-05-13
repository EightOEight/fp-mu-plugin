<?php
/**
 * Pre-capture drift linter — refuses to snapshot when the active
 * site has plugins or a theme that aren't declared in
 * `vendor/composer/installed.json`.
 *
 * Why: an immutable-image site can only render block content whose
 * declaring plugin/theme is baked into the image. If a designer
 * activates a plugin via WP admin to evaluate (lockdown is relaxed
 * out-of-cluster) and uses one of its blocks in a template, the
 * snapshot ships block markup that references a block name not
 * registered on prod — broken render with no obvious cause.
 *
 * The linter catches the drift at capture time: the designer either
 * `composer require`s the plugin and rebuilds, or deactivates it
 * before snapshotting. Either way the snapshot only references
 * blocks that exist on prod.
 *
 * Scope: regular plugins (`active_plugins` option) and the active
 * theme (`stylesheet` option). Mu-plugins are intentionally NOT
 * checked — they're tightly controlled by composer install +
 * bedrock-autoloader and rarely get manually installed at runtime.
 *
 * @package FrankenPress\Cli\Snapshot
 */

declare(strict_types=1);

namespace FrankenPress\Cli\Snapshot;

use RuntimeException;

final class DriftLinter {

	/**
	 * @param callable $composer_packages_reader Function (): array{plugins: array<int, string>, themes: array<int, string>}
	 *     — returns sets of composer-installed wordpress-plugin /
	 *     wordpress-theme slugs (the directory name, NOT the
	 *     fully-qualified package name). Production caller reads
	 *     `<project-root>/vendor/composer/installed.json` and filters
	 *     by `type`.
	 * @param callable $active_state_reader      Function (): array{plugins: array<int, string>, theme: string}
	 *     — returns active plugin slugs + active theme slug. Production
	 *     caller wraps `get_option('active_plugins')` (mapping each
	 *     `<slug>/<slug>.php` entry to its leading dir) + `get_option('stylesheet')`.
	 */
	public function __construct(
		private $composer_packages_reader,
		private $active_state_reader,
	) {}

	/**
	 * Run the check. Throws RuntimeException with a specific drift
	 * listing when any active plugin/theme isn't composer-installed.
	 * No-op on a clean site.
	 *
	 * @throws RuntimeException
	 */
	public function check(): void {
		$composer = ( $this->composer_packages_reader )();
		$active   = ( $this->active_state_reader )();

		$composer_plugins = $this->normalize_slug_set( $composer['plugins'] ?? array() );
		$composer_themes  = $this->normalize_slug_set( $composer['themes'] ?? array() );
		$active_plugins   = $this->normalize_slug_set( $active['plugins'] ?? array() );
		$active_theme     = (string) ( $active['theme'] ?? '' );

		// $active_plugins / $composer_plugins are slug=>true maps;
		// array_diff_key compares keys; array_keys() lifts the slugs
		// back out (vs array_values, which would return the bool true).
		$drifted_plugins = array_keys( array_diff_key( $active_plugins, $composer_plugins ) );

		$theme_drift = ( '' !== $active_theme && ! isset( $composer_themes[ $active_theme ] ) )
			? $active_theme
			: '';

		if ( empty( $drifted_plugins ) && '' === $theme_drift ) {
			return;
		}

		$lines = array( 'snapshot: drift detected — active plugins/themes not declared in composer.json:' );
		foreach ( $drifted_plugins as $slug ) {
			$lines[] = sprintf(
				"  - plugin '%s' is active but not composer-installed. Either `composer require wpackagist-plugin/%s` and rebuild, or `wp plugin deactivate %s` before snapshotting.",
				$slug,
				$slug,
				$slug
			);
		}
		if ( '' !== $theme_drift ) {
			$lines[] = sprintf(
				"  - theme '%s' is active but not composer-installed. Either `composer require wpackagist-theme/%s` and rebuild, or `wp theme activate <some-composer-installed-theme>` before snapshotting.",
				$theme_drift,
				$theme_drift
			);
		}

		throw new RuntimeException( implode( "\n", $lines ) );
	}

	/**
	 * Convert a list of slugs to a set (slug => true) for O(1)
	 * lookups + dedup. Filters empties.
	 *
	 * @param array<int, string> $slugs
	 * @return array<string, true>
	 */
	private function normalize_slug_set( array $slugs ): array {
		$out = array();
		foreach ( $slugs as $slug ) {
			$slug = (string) $slug;
			if ( '' !== $slug ) {
				$out[ $slug ] = true;
			}
		}
		return $out;
	}
}
