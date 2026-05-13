<?php
/**
 * Owned-posts capturer — captures `post_types_owned` CPTs into a JSON
 * sidecar with upsert semantics on apply.
 *
 * Separates design-state CPTs (wp_template, wp_template_part,
 * wp_global_styles, wp_navigation) from the WXR-shipped additive
 * content CPTs (page, post, attachment). The split exists because
 * WP-Importer silent-skips existing posts on second-apply, breaking
 * designer iteration on owned CPTs. The owned sidecar carries the
 * fields as-is; the apply path looks up by post_name+post_type and
 * UPSERTs via wp_update_post / wp_insert_post.
 *
 * Output format (the `templates.json` sidecar):
 *
 *   {
 *     "wp_template": {
 *       "home": {
 *         "post_title":   "Blog Home",
 *         "post_content": "<!-- wp:... -->",
 *         "post_status":  "publish",
 *         "post_excerpt": "",
 *         "meta": {
 *           "origin": "user"
 *         },
 *         "terms": {
 *           "wp_theme": ["twentytwentyfive"]
 *         }
 *       },
 *       ...
 *     },
 *     "wp_template_part": {
 *       "header": {
 *         "post_title":   "Header",
 *         ...
 *         "terms": {
 *           "wp_theme":              ["twentytwentyfive"],
 *           "wp_template_part_area": ["header"]
 *         }
 *       }
 *     }
 *   }
 *
 * Keyed by `post_name` (slug).
 *
 * v5 taxonomy capture (2026-05): WP doesn't bind block-template posts
 * to a theme via postmeta — it uses the `wp_theme` taxonomy term, and
 * `wp_template_part_area` for the area facet. Capturing these is
 * load-bearing: a `wp_template_part` row without the active theme's
 * `wp_theme` term is invisible to `get_block_templates()` and the FSE
 * renderer falls back to the theme file. The pre-v5 capture path
 * silently shipped rows with no taxonomy info, producing the
 * "header doesn't update after apply" failure on sts-stg 2026-05-13.
 *
 * @package FrankenPress\Cli\Snapshot
 */

declare(strict_types=1);

namespace FrankenPress\Cli\Snapshot;

use RuntimeException;

final class OwnedPostsCapturer {

	private const META_KEYS = array(
		'wp_template'      => array( 'origin', 'description', 'is_wp_suggestion' ),
		'wp_template_part' => array( 'origin', 'description', 'area' ),
		'wp_global_styles' => array(),
		'wp_navigation'    => array(),
	);

	/**
	 * Taxonomies WP attaches to each owned post type. Capture is
	 * authoritative: apply replaces (not appends) terms on these
	 * taxonomies so a re-apply corrects any drift.
	 *
	 * `wp_theme` is required for `wp_template`, `wp_template_part`,
	 * and `wp_global_styles` — without it the FSE renderer can't
	 * find the row. `wp_template_part_area` is required for
	 * `wp_template_part` (header/footer/uncategorized).
	 *
	 * `wp_navigation` is not theme-bound, hence no taxonomies.
	 * `custom_css` is theme-bound but via post_name (matches the
	 * stylesheet slug) rather than a taxonomy term — see
	 * `THEME_BOUND_VIA_POST_NAME` below.
	 */
	private const TAXONOMIES = array(
		'wp_template'      => array( 'wp_theme' ),
		'wp_template_part' => array( 'wp_theme', 'wp_template_part_area' ),
		'wp_global_styles' => array( 'wp_theme' ),
		'wp_navigation'    => array(),
		'custom_css'       => array(),
	);

	/**
	 * Post types whose theme binding lives in `post_name` (= the
	 * stylesheet slug) rather than the `wp_theme` taxonomy. Captured
	 * rows whose post_name doesn't match the source stylesheet are
	 * skipped — we only want the active theme's custom_css, even if
	 * the source DB carries rows from a previous theme.
	 */
	private const THEME_BOUND_VIA_POST_NAME = array( 'custom_css' );

	/**
	 * Post types for which a missing `wp_theme` term is a fatal capture
	 * error. Shipping such a row produces a silent renderer-invisible
	 * apply, so we'd rather fail loud at capture time and force the
	 * designer to look at their local state.
	 */
	private const REQUIRES_THEME_TERM = array( 'wp_template', 'wp_template_part', 'wp_global_styles' );

	/**
	 * @param callable $sql_runner Function (string $sql): array<int, array<string, mixed>>
	 *     — runs a SELECT against the live DB and returns rows as
	 *     assoc arrays. Production caller injects a closure over
	 *     `$wpdb->get_results($sql, ARRAY_A)`.
	 * @param callable $meta_reader Function (int $post_id, string $key): mixed
	 *     — wraps `get_post_meta($id, $key, true)` for testability.
	 * @param callable $term_reader Function (int $post_id, string $taxonomy): array<int, string>
	 *     — returns an array of term slugs for the given post + taxonomy
	 *     (empty when the post has no terms on that taxonomy). Production
	 *     caller injects a closure over `wp_get_object_terms($id, $tax, ['fields' => 'slugs'])`.
	 * @param string   $source_stylesheet The active stylesheet at
	 *     capture time. Used to filter wp_template / wp_template_part /
	 *     wp_global_styles rows belonging to other themes (their
	 *     `wp_theme` term wouldn't match the source).
	 */
	public function __construct(
		private $sql_runner,
		private $meta_reader,
		private $term_reader,
		private string $source_stylesheet,
	) {}

	/**
	 * Capture the owned post types into a structured array suitable
	 * for json_encode-ing into the templates.json sidecar.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 * @throws RuntimeException When a captured row of a theme-bound
	 *     post type (wp_template, wp_template_part, wp_global_styles)
	 *     has no `wp_theme` term. Better to fail capture loud than
	 *     ship a renderer-invisible row that silently breaks the
	 *     target site.
	 */
	public function capture( SnapshotScope $scope ): array {
		$out = array();
		foreach ( $scope->post_types_owned as $post_type ) {
			$rows = ( $this->sql_runner )(
				sprintf(
					"SELECT ID, post_name, post_title, post_content, post_status, post_excerpt FROM %s WHERE post_type = '%s' ORDER BY post_name",
					$this->posts_table(),
					$this->escape_string( $post_type )
				)
			);

			$by_slug = array();
			foreach ( $rows as $row ) {
				$slug = (string) ( $row['post_name'] ?? '' );
				if ( '' === $slug ) {
					continue;
				}

				// Filter post-name-bound types (custom_css) to the active
				// stylesheet. WP keeps a row per theme; we only want the
				// active theme's custom_css, since that's the design
				// state the snapshot represents.
				if ( in_array( $post_type, self::THEME_BOUND_VIA_POST_NAME, true )
					&& '' !== $this->source_stylesheet
					&& $slug !== $this->source_stylesheet
				) {
					continue;
				}

				$id    = (int) ( $row['ID'] ?? 0 );
				$meta  = $this->collect_meta( $id, $post_type );
				$terms = $this->collect_terms( $id, $post_type );

				// Filter rows whose `wp_theme` term doesn't match the
				// source stylesheet. WP can hold rows for the same slug
				// across multiple themes (each tied to a different
				// `wp_theme` term); we only want this theme's set.
				if ( '' !== $this->source_stylesheet
					&& isset( $terms['wp_theme'] )
					&& ! in_array( $this->source_stylesheet, $terms['wp_theme'], true )
				) {
					continue;
				}

				// Theme-bound post types must have a `wp_theme` term. If
				// not, the source row would be invisible to the renderer
				// on the target — fail loud.
				if ( in_array( $post_type, self::REQUIRES_THEME_TERM, true )
					&& empty( $terms['wp_theme'] )
				) {
					throw new RuntimeException(
						sprintf(
							'snapshot: %s row "%s" (ID %d) has no `wp_theme` taxonomy term — capture refuses to ship a renderer-invisible row. Investigate the source DB state.',
							$post_type,
							$slug,
							$id
						)
					);
				}

				$entry = array(
					'post_title'   => (string) ( $row['post_title'] ?? '' ),
					'post_content' => (string) ( $row['post_content'] ?? '' ),
					'post_status'  => (string) ( $row['post_status'] ?? 'publish' ),
					'post_excerpt' => (string) ( $row['post_excerpt'] ?? '' ),
				);
				if ( ! empty( $meta ) ) {
					$entry['meta'] = $meta;
				}
				if ( ! empty( $terms ) ) {
					$entry['terms'] = $terms;
				}
				$by_slug[ $slug ] = $entry;
			}

			if ( ! empty( $by_slug ) ) {
				ksort( $by_slug );
				$out[ $post_type ] = $by_slug;
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function collect_meta( int $post_id, string $post_type ): array {
		$keys = self::META_KEYS[ $post_type ] ?? array();
		$out  = array();
		foreach ( $keys as $key ) {
			$val = ( $this->meta_reader )( $post_id, $key );
			if ( null === $val || '' === $val || false === $val ) {
				continue;
			}
			$out[ $key ] = $val;
		}
		return $out;
	}

	/**
	 * Collect taxonomy terms for a captured row. Returns a map of
	 * `taxonomy => [term_slugs]`. Empty taxonomies are omitted so the
	 * resulting JSON stays tight.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function collect_terms( int $post_id, string $post_type ): array {
		$taxonomies = self::TAXONOMIES[ $post_type ] ?? array();
		$out        = array();
		foreach ( $taxonomies as $taxonomy ) {
			$slugs = ( $this->term_reader )( $post_id, $taxonomy );
			if ( ! is_array( $slugs ) || empty( $slugs ) ) {
				continue;
			}
			// Normalise to plain string list. Defensive: caller's
			// closure SHOULD return slugs already, but a malformed
			// upstream could send WP_Term objects.
			$clean = array();
			foreach ( $slugs as $slug ) {
				if ( is_string( $slug ) && '' !== $slug ) {
					$clean[] = $slug;
				}
			}
			if ( ! empty( $clean ) ) {
				$out[ $taxonomy ] = $clean;
			}
		}
		return $out;
	}

	private function posts_table(): string {
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->posts ) ) {
			return (string) $wpdb->posts;
		}
		return 'wp_posts';
	}

	private function escape_string( string $in ): string {
		return strtr(
			$in,
			array(
				'\\' => '\\\\',
				"'"  => "\\'",
			)
		);
	}
}
