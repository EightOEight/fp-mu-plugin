<?php
/**
 * Options capturer — captures scoped `wp_options` rows + adapter
 * theme_mods into a JSON sidecar.
 *
 * WXR doesn't carry wp_options (it's the WordPress content export
 * format — posts, terms, menus, attachments). Site-identity settings,
 * customizer state, and theme_mods all live in wp_options and need
 * their own channel. This class fills that gap.
 *
 * Output format (the `options.json` sidecar):
 *
 *   {
 *     "options": {
 *       "blogname": "Sole Trader Support",
 *       "page_on_front": "12",
 *       ...
 *     },
 *     "theme_mods": {
 *       "twentytwentyfive": { ... }
 *     }
 *   }
 *
 * The apply path walks `options` and runs `update_option(key, value)`
 * for each; for `theme_mods`, walks the mods map and writes directly to
 * the `theme_mods_<slug>` option_name. WordPress handles serialisation
 * round-tripping cleanly via its native option/theme_mod functions.
 *
 * v3 simplification: explicit `option_keys` list — no MySQL LIKE
 * patterns. Every key the active adapter wants is enumerated, so we
 * just iterate and `get_option()` each. No SQL.
 *
 * @package FrankenPress\Cli\Snapshot
 */

declare(strict_types=1);

namespace FrankenPress\Cli\Snapshot;

final class OptionsCapturer {

	/**
	 * @param callable $option_get  Function (string $key): mixed —
	 *     wraps `get_option` for testability.
	 */
	public function __construct(
		private $option_get,
	) {}

	/**
	 * Capture the scoped options + theme_mods into a structured array
	 * suitable for json_encode-ing into the snapshot sidecar.
	 *
	 * @return array{options: array<string, mixed>, theme_mods: array<string, mixed>}
	 */
	public function capture( SnapshotScope $scope ): array {
		$options = array();
		foreach ( $scope->option_keys as $key ) {
			if ( '' === $key ) {
				continue;
			}
			$value = ( $this->option_get )( $key );
			if ( null === $value || false === $value ) {
				// Skip unset options — keeps the sidecar tight, and the
				// apply path will leave the target's existing value in
				// place rather than nulling it.
				continue;
			}
			$options[ $key ] = $value;
		}
		ksort( $options );

		$theme_mods = array();
		foreach ( $scope->theme_mods_for as $stylesheet ) {
			$key = 'theme_mods_' . $stylesheet;
			$val = ( $this->option_get )( $key );
			if ( ! empty( $val ) ) {
				$theme_mods[ $stylesheet ] = $val;
			}
		}

		return array(
			'options'    => $options,
			'theme_mods' => $theme_mods,
		);
	}
}
