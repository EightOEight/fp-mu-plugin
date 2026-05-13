<?php
/**
 * Pre-capture drift linter — refuses to snapshot when the active
 * site has plugins or a theme that aren't on disk at the production
 * layout's expected location.
 *
 * "On disk" means EITHER:
 *   - composer-installed (declared in `vendor/composer/installed.json`), OR
 *   - site-tracked (committed in `web/app/plugins/<slug>/` or
 *     `web/app/themes/<slug>/` — present at the runtime layout's
 *     wp-content path).
 *
 * The composer + site-tracked union is what's actually available at
 * runtime: composer-installed assets land in the same directories as
 * site-tracked ones via the chart-installer's `installer-paths`
 * config. The Dockerfile baking the site image COPYs the site repo's
 * `web/app/` tree, so anything site-tracked rides into the image
 * alongside composer-managed deps.
 *
 * Why: an immutable-image site can only render block content whose
 * declaring plugin/theme is baked into the image. If a designer
 * activates a plugin via WP admin to evaluate (lockdown is relaxed
 * out-of-cluster) and uses one of its blocks in a template, the
 * snapshot ships block markup that references a block name not
 * registered on prod — broken render with no obvious cause.
 *
 * Phase 3 motivation for the site-tracked acceptance: child themes
 * created via Automattic's Create Block Theme plugin land in
 * `web/app/themes/<site>-design/` and are tracked in the site repo
 * (not in composer). Pre-Phase-3 the linter incorrectly flagged
 * these as drift, blocking the Phase 3 designer flow.
 *
 * The linter catches the drift at capture time: the designer either
 * `composer require`s the dep, commits the plugin/theme into the
 * site repo, or deactivates it before snapshotting.
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
	 * @param callable $site_tracked_reader      Function (): array{plugins: array<int, string>, themes: array<int, string>}
	 *     — returns sets of slugs whose plugin/theme directories
	 *     exist under `WP_CONTENT_DIR/plugins/<slug>/` /
	 *     `WP_CONTENT_DIR/themes/<slug>/` regardless of composer
	 *     provenance. Catches site-tracked entries that ride into
	 *     the image via the Dockerfile's `web/app/` COPY (e.g. a
	 *     Phase 3 child theme committed at
	 *     `web/app/themes/<site>-design/`).
	 */
	public function __construct(
		private $composer_packages_reader,
		private $active_state_reader,
		private $site_tracked_reader,
	) {}

	/**
	 * Run the check. Throws RuntimeException with a specific drift
	 * listing when any active plugin/theme isn't composer-installed.
	 * No-op on a clean site.
	 *
	 * @throws RuntimeException
	 */
	public function check(): void {
		$composer     = ( $this->composer_packages_reader )();
		$active       = ( $this->active_state_reader )();
		$site_tracked = ( $this->site_tracked_reader )();

		$composer_plugins     = $this->normalize_slug_set( $composer['plugins'] ?? array() );
		$composer_themes      = $this->normalize_slug_set( $composer['themes'] ?? array() );
		$site_tracked_plugins = $this->normalize_slug_set( $site_tracked['plugins'] ?? array() );
		$site_tracked_themes  = $this->normalize_slug_set( $site_tracked['themes'] ?? array() );
		$active_plugins       = $this->normalize_slug_set( $active['plugins'] ?? array() );
		$active_theme         = (string) ( $active['theme'] ?? '' );

		// "Available" = composer-installed OR site-tracked. Both ride
		// into the production image: composer puts wp-plugins/themes
		// into web/app/{plugins,themes}/ via installer-paths; the
		// Dockerfile then COPYs web/app/ into the image.
		$available_plugins = $composer_plugins + $site_tracked_plugins;
		$available_themes  = $composer_themes + $site_tracked_themes;

		// $active_plugins / $available_plugins are slug=>true maps;
		// array_diff_key compares keys; array_keys() lifts the slugs
		// back out (vs array_values, which would return the bool true).
		$drifted_plugins = array_keys( array_diff_key( $active_plugins, $available_plugins ) );

		$theme_drift = ( '' !== $active_theme && ! isset( $available_themes[ $active_theme ] ) )
			? $active_theme
			: '';

		if ( empty( $drifted_plugins ) && '' === $theme_drift ) {
			return;
		}

		$lines = array( 'snapshot: drift detected — active plugins/themes not available at runtime:' );
		foreach ( $drifted_plugins as $slug ) {
			$lines[] = sprintf(
				"  - plugin '%s' is active but neither composer-installed nor site-tracked. Either `composer require wpackagist-plugin/%s` and rebuild, commit the plugin into the site repo at `web/app/plugins/%s/`, or `wp plugin deactivate %s` before snapshotting.",
				$slug,
				$slug,
				$slug,
				$slug
			);
		}
		if ( '' !== $theme_drift ) {
			$lines[] = sprintf(
				"  - theme '%s' is active but neither composer-installed nor site-tracked. Either `composer require wpackagist-theme/%s` and rebuild, commit the theme into the site repo at `web/app/themes/%s/`, or `wp theme activate <some-available-theme>` before snapshotting.",
				$theme_drift,
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
