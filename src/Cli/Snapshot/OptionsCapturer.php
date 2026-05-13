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
 *     },
 *     "option_page_refs": {
 *       "page_on_front":  {"slug": "home",  "type": "page"},
 *       "page_for_posts": {"slug": "blog",  "type": "page"}
 *     }
 *   }
 *
 * `option_page_refs` is populated when the adapter declares
 * `option_keys_page_refs` and the option value resolves to a page
 * post. Apply uses it to look up the local page by slug rather than
 * trusting the captured numeric ID (page IDs don't survive a
 * local→stg→prd promotion). If a page-ref option is set but the
 * referenced post no longer exists locally at capture time, the
 * entry is omitted — apply will leave the target's existing value
 * untouched.
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
	 * @param callable $option_get   Function (string $key): mixed —
	 *     wraps `get_option` for testability.
	 * @param callable $page_resolver Function (int $post_id): ?array —
	 *     given a post ID, returns ['slug' => ..., 'type' => ...] or
	 *     null when the post doesn't exist / isn't a page-shaped type.
	 *     Production caller wraps `get_post()->post_name + post_type`.
	 */
	public function __construct(
		private $option_get,
		private $page_resolver,
	) {}

	/**
	 * Capture the scoped options + theme_mods into a structured array
	 * suitable for json_encode-ing into the snapshot sidecar.
	 *
	 * @return array{options: array<string, mixed>, theme_mods: array<string, mixed>, option_page_refs: array<string, array{slug: string, type: string}>}
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

		$option_page_refs = array();
		foreach ( $scope->option_keys_page_refs as $key ) {
			if ( ! isset( $options[ $key ] ) ) {
				continue;
			}
			$post_id = (int) $options[ $key ];
			if ( $post_id <= 0 ) {
				continue;
			}
			$ref = ( $this->page_resolver )( $post_id );
			if ( ! is_array( $ref ) ) {
				continue;
			}
			$slug = (string) ( $ref['slug'] ?? '' );
			$type = (string) ( $ref['type'] ?? '' );
			if ( '' === $slug || '' === $type ) {
				continue;
			}
			$option_page_refs[ $key ] = array(
				'slug' => $slug,
				'type' => $type,
			);
		}
		ksort( $option_page_refs );

		return array(
			'options'          => $options,
			'theme_mods'       => $theme_mods,
			'option_page_refs' => $option_page_refs,
		);
	}
}
