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
 *           "theme":  "twentytwentyfive",
 *           "origin": "user"
 *         }
 *       },
 *       ...
 *     },
 *     "wp_template_part": { ... },
 *     "wp_global_styles": { ... },
 *     "wp_navigation":    { ... }
 *   }
 *
 * Keyed by `post_name` (slug). Where multiple posts share a slug for
 * the same post_type (rare for FSE CPTs — typically only happens
 * across active themes), `post_status` ordering picks the most-
 * recently-published. Theme-postmeta filtering at capture time
 * ensures we only capture rows belonging to the source theme.
 *
 * @package FrankenPress\Cli\Snapshot
 */

declare(strict_types=1);

namespace FrankenPress\Cli\Snapshot;

final class OwnedPostsCapturer {

	private const META_KEYS = array(
		'wp_template'      => array( 'theme', 'origin', 'description', 'is_wp_suggestion' ),
		'wp_template_part' => array( 'theme', 'origin', 'description', 'area' ),
		'wp_global_styles' => array(),
		'wp_navigation'    => array(),
	);

	/**
	 * @param callable $sql_runner Function (string $sql): array<int, array<string, mixed>>
	 *     — runs a SELECT against the live DB and returns rows as
	 *     assoc arrays. Production caller injects a closure over
	 *     `$wpdb->get_results($sql, ARRAY_A)`.
	 * @param callable $meta_reader Function (int $post_id, string $key): mixed
	 *     — wraps `get_post_meta($id, $key, true)` for testability.
	 * @param string   $source_stylesheet The active stylesheet at
	 *     capture time. Used to filter wp_template / wp_template_part
	 *     rows belonging to other themes (their `theme` postmeta
	 *     wouldn't match the source).
	 */
	public function __construct(
		private $sql_runner,
		private $meta_reader,
		private string $source_stylesheet,
	) {}

	/**
	 * Capture the owned post types into a structured array suitable
	 * for json_encode-ing into the templates.json sidecar.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
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

				$id   = (int) ( $row['ID'] ?? 0 );
				$meta = $this->collect_meta( $id, $post_type );

				// Filter wp_template / wp_template_part rows whose `theme`
				// postmeta doesn't match the source stylesheet. WP can
				// hold multiple rows of the same slug across themes; we
				// only want this theme's set.
				if ( '' !== $this->source_stylesheet
					&& in_array( $post_type, array( 'wp_template', 'wp_template_part' ), true )
					&& isset( $meta['theme'] )
					&& (string) $meta['theme'] !== $this->source_stylesheet
				) {
					continue;
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
