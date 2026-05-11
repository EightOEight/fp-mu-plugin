<?php
/**
 * Options capturer — captures scoped `wp_options` rows + adapter
 * theme_mods into a JSON sidecar.
 *
 * WXR doesn't carry wp_options (it's the WordPress content export
 * format — posts, terms, menus, attachments). Theme settings, widget
 * configs, customizer state, and theme_mods all live in wp_options
 * and need their own channel. This class fills that gap.
 *
 * Output format (the `options.json` sidecar):
 *
 *   {
 *     "options": {
 *       "the7_settings": <serialised-php-value-as-json>,
 *       "elementor_active_kit": "12",
 *       "sidebars_widgets": { ... },
 *       ...
 *     },
 *     "theme_mods": {
 *       "dt-the7": { ... }
 *     }
 *   }
 *
 * The apply path walks `options` and runs `update_option(key, value)`
 * for each; for `theme_mods`, walks the mods map and runs
 * `set_theme_mod(slug, value)`. WordPress handles serialisation
 * round-tripping cleanly via its native option/theme_mod functions
 * (they're the canonical readers + writers).
 *
 * @package FrankenPress\Cli\Snapshot
 */

declare(strict_types=1);

namespace FrankenPress\Cli\Snapshot;

final class OptionsCapturer {

	/**
	 * @param callable $sql_runner  Function (string $sql): array<int, array<string, mixed>>.
	 * @param callable $option_get  Function (string $key): mixed —
	 *     wraps `get_option` for testability.
	 */
	public function __construct(
		private $sql_runner,
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
		foreach ( $scope->option_patterns as $pattern ) {
			$rows = ( $this->sql_runner )( $this->build_pattern_sql( $pattern ) );
			foreach ( $rows as $row ) {
				$name = (string) ( $row['option_name'] ?? '' );
				if ( '' === $name ) {
					continue;
				}
				// Skip transients defensively — they shouldn't match any
				// designer-scoped pattern in practice but if someone
				// adds a wildcard like `*_transient_*` we don't want
				// to ship them.
				if ( str_starts_with( $name, '_transient_' ) || str_starts_with( $name, '_site_transient_' ) ) {
					continue;
				}
				// Use get_option to let WP deserialise + apply filters
				// the same way it does on read. The JSON encode below
				// then captures the value in its in-memory form.
				$options[ $name ] = ( $this->option_get )( $name );
			}
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

	/**
	 * Build the SQL that lists option_names matching a LIKE pattern.
	 *
	 * We escape `_` and `%` per WP's standard LIKE-escaping convention
	 * only for pattern characters that should be literal. The caller's
	 * patterns are designer-controlled (e.g. `the7_%`); we don't try
	 * to over-protect.
	 */
	private function build_pattern_sql( string $pattern ): string {
		global $wpdb;
		$table   = ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->options ) )
			? (string) $wpdb->options
			: 'wp_options';
		$escaped = strtr(
			$pattern,
			array(
				'\\' => '\\\\',
				"'"  => "\\'",
			)
		);
		return sprintf( "SELECT option_name FROM %s WHERE option_name LIKE '%s'", $table, $escaped );
	}
}
