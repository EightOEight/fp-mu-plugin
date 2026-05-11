<?php
/**
 * Plugin inspector for `wp fp snapshot`.
 *
 * Diffs the site's *currently active* plugins against the set composer
 * baked into the running image, and emits a `composer-patch.json` that
 * the engineer reviewing the promote PR uses to drive `composer
 * require` against the site repo.
 *
 * v1 scope (Phase 0 of `.aidocs/fp-cli-design.md`):
 *
 *   - Active plugin slugs that exist on disk under WP_PLUGIN_DIR but
 *     aren't declared in the site's `composer.json` → emit as
 *     `pending_requires[]`.
 *   - Active plugin slugs that are *not* on disk under WP_PLUGIN_DIR
 *     (designer activated something from a non-standard location, or
 *     a plugin disappeared mid-snapshot) → emit as `unresolved[]`.
 *
 * Deliberately out of scope for v1: classification against the
 * wpackagist HTTP API ("is this on wpackagist?"), version-range
 * suggestions ("require ^X.Y based on what's installed"), theme-
 * bundled-plugin detection (TGMPA fingerprinting). All three land in
 * Phase 4 with the adapter registry — see plan §"Phase 4".
 *
 * @package FrankenPress\Cli\Snapshot
 */

declare(strict_types=1);

namespace FrankenPress\Cli\Snapshot;

final class PluginInspector {

	/**
	 * @param string $plugin_dir   Absolute path to WP_PLUGIN_DIR (the
	 *                             host of composer-installed plugins).
	 * @param string $composer_json_path Absolute path to the site
	 *                             composer.json (Bedrock layout: `/app/composer.json`).
	 * @param array<int, string> $active_plugin_files
	 *                             Value of `active_plugins` option —
	 *                             list of `<slug>/<entry>.php` strings.
	 */
	public function __construct(
		private string $plugin_dir,
		private string $composer_json_path,
		private array $active_plugin_files,
	) {}

	/**
	 * Build the composer-patch.json contents.
	 *
	 * @return array{pending_requires: array<int, string>, unresolved: array<int, string>, rationale: string}
	 */
	public function build_patch(): array {
		$active_slugs = array_unique(
			array_map(
				static fn ( string $file ): string => dirname( $file ),
				$this->active_plugin_files
			)
		);

		$declared = $this->declared_wpackagist_slugs();

		$pending    = array();
		$unresolved = array();

		foreach ( $active_slugs as $slug ) {
			if ( '.' === $slug || '' === $slug ) {
				// `active_plugins` stores top-level single-file plugins
				// as bare `<file>.php` with no directory. They have no
				// composer slug — skip silently.
				continue;
			}

			if ( in_array( $slug, $declared, true ) ) {
				continue;
			}

			if ( ! is_dir( $this->plugin_dir . '/' . $slug ) ) {
				$unresolved[] = $slug;
				continue;
			}

			$pending[] = $slug;
		}

		sort( $pending );
		sort( $unresolved );

		return array(
			'pending_requires' => $pending,
			'unresolved'       => $unresolved,
			'rationale'        => 'Plugins activated locally that are not yet declared in composer.json. Run `composer require wpackagist-plugin/<slug>` for each entry under pending_requires; investigate unresolved entries manually (they may be theme-bundled premium plugins requiring an alternate install path).',
		);
	}

	/**
	 * Read the site's composer.json and return the list of
	 * `wpackagist-plugin/<slug>` slugs already declared.
	 *
	 * @return array<int, string>
	 */
	private function declared_wpackagist_slugs(): array {
		if ( ! is_file( $this->composer_json_path ) ) {
			return array();
		}

		$raw = file_get_contents( $this->composer_json_path );
		if ( false === $raw || '' === $raw ) {
			return array();
		}

		$json = json_decode( $raw, true );
		if ( ! is_array( $json ) || ! isset( $json['require'] ) || ! is_array( $json['require'] ) ) {
			return array();
		}

		$slugs = array();
		foreach ( array_keys( $json['require'] ) as $package ) {
			if ( ! is_string( $package ) ) {
				continue;
			}
			if ( str_starts_with( $package, 'wpackagist-plugin/' ) ) {
				$slugs[] = substr( $package, strlen( 'wpackagist-plugin/' ) );
			}
		}

		return $slugs;
	}
}
