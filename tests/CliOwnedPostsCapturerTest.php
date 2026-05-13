<?php
/**
 * Unit tests for FrankenPress\Cli\Snapshot\OwnedPostsCapturer.
 *
 * @package FrankenPress\Tests
 */

declare(strict_types=1);

namespace FrankenPress\Tests;

use FrankenPress\Cli\Snapshot\OwnedPostsCapturer;
use FrankenPress\Cli\Snapshot\SnapshotScope;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CliOwnedPostsCapturerTest extends TestCase {

	public function test_capture_empty_scope_returns_empty(): void {
		$capturer = new OwnedPostsCapturer(
			static fn ( string $sql ): array => array(),
			static fn ( int $id, string $key ): mixed => '',
			static fn ( int $id, string $tax ): array => array(),
			'twentytwentyfive'
		);
		$this->assertSame( array(), $capturer->capture( new SnapshotScope() ) );
	}

	public function test_capture_groups_rows_by_post_type_and_slug(): void {
		$rows        = array(
			'wp_template'      => array(
				array(
					'ID'           => 10,
					'post_name'    => 'home',
					'post_title'   => 'Blog Home',
					'post_content' => '<!-- wp:home -->',
					'post_status'  => 'publish',
					'post_excerpt' => '',
				),
			),
			'wp_template_part' => array(
				array(
					'ID'           => 20,
					'post_name'    => 'footer',
					'post_title'   => 'Footer',
					'post_content' => '<!-- wp:footer -->',
					'post_status'  => 'publish',
					'post_excerpt' => '',
				),
			),
		);
		$sql_runner  = static function ( string $sql ) use ( $rows ): array {
			foreach ( array_keys( $rows ) as $pt ) {
				if ( false !== strpos( $sql, "post_type = '{$pt}'" ) ) {
					return $rows[ $pt ];
				}
			}
			return array();
		};
		$meta_reader = static fn ( int $id, string $key ): mixed => '';
		$term_reader = static function ( int $id, string $tax ): array {
			if ( 'wp_theme' === $tax ) {
				return array( 'twentytwentyfive' );
			}
			if ( 'wp_template_part_area' === $tax && 20 === $id ) {
				return array( 'footer' );
			}
			return array();
		};

		$capturer = new OwnedPostsCapturer( $sql_runner, $meta_reader, $term_reader, 'twentytwentyfive' );
		$out      = $capturer->capture( new SnapshotScope( post_types_owned: array( 'wp_template', 'wp_template_part' ) ) );

		$this->assertArrayHasKey( 'wp_template', $out );
		$this->assertArrayHasKey( 'wp_template_part', $out );
		$this->assertArrayHasKey( 'home', $out['wp_template'] );
		$this->assertSame( 'Blog Home', $out['wp_template']['home']['post_title'] );
		$this->assertSame( '<!-- wp:home -->', $out['wp_template']['home']['post_content'] );
	}

	public function test_capture_records_wp_theme_term_on_wp_template(): void {
		$rows        = array(
			array(
				'ID'           => 10,
				'post_name'    => 'home',
				'post_title'   => 'Home',
				'post_content' => '',
				'post_status'  => 'publish',
				'post_excerpt' => '',
			),
		);
		$sql_runner  = static fn ( string $sql ): array => $rows;
		$meta_reader = static fn ( int $id, string $key ): mixed => '';
		$term_reader = static fn ( int $id, string $tax ): array =>
			'wp_theme' === $tax ? array( 'twentytwentyfive' ) : array();

		$capturer = new OwnedPostsCapturer( $sql_runner, $meta_reader, $term_reader, 'twentytwentyfive' );
		$out      = $capturer->capture( new SnapshotScope( post_types_owned: array( 'wp_template' ) ) );

		$this->assertArrayHasKey( 'terms', $out['wp_template']['home'] );
		$this->assertSame(
			array( 'wp_theme' => array( 'twentytwentyfive' ) ),
			$out['wp_template']['home']['terms']
		);
	}

	public function test_capture_records_both_wp_theme_and_area_on_wp_template_part(): void {
		$rows        = array(
			array(
				'ID'           => 20,
				'post_name'    => 'header',
				'post_title'   => 'Header',
				'post_content' => '<!-- wp:site-title /-->',
				'post_status'  => 'publish',
				'post_excerpt' => '',
			),
		);
		$sql_runner  = static fn ( string $sql ): array => $rows;
		$meta_reader = static fn ( int $id, string $key ): mixed => '';
		$term_reader = static function ( int $id, string $tax ): array {
			if ( 'wp_theme' === $tax ) {
				return array( 'twentytwentyfive' );
			}
			if ( 'wp_template_part_area' === $tax ) {
				return array( 'header' );
			}
			return array();
		};

		$capturer = new OwnedPostsCapturer( $sql_runner, $meta_reader, $term_reader, 'twentytwentyfive' );
		$out      = $capturer->capture( new SnapshotScope( post_types_owned: array( 'wp_template_part' ) ) );

		$this->assertSame(
			array(
				'wp_theme'              => array( 'twentytwentyfive' ),
				'wp_template_part_area' => array( 'header' ),
			),
			$out['wp_template_part']['header']['terms']
		);
	}

	public function test_capture_throws_when_wp_template_has_no_wp_theme_term(): void {
		// A wp_template row with no `wp_theme` term would be invisible
		// to the FSE renderer on the target. Shipping it would silently
		// produce a no-op apply — fail loud at capture instead.
		$rows        = array(
			array(
				'ID'           => 10,
				'post_name'    => 'home',
				'post_title'   => 'Home',
				'post_content' => '',
				'post_status'  => 'publish',
				'post_excerpt' => '',
			),
		);
		$sql_runner  = static fn ( string $sql ): array => $rows;
		$meta_reader = static fn ( int $id, string $key ): mixed => '';
		$term_reader = static fn ( int $id, string $tax ): array => array();

		$capturer = new OwnedPostsCapturer( $sql_runner, $meta_reader, $term_reader, 'twentytwentyfive' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/wp_theme.*taxonomy term/' );
		$capturer->capture( new SnapshotScope( post_types_owned: array( 'wp_template' ) ) );
	}

	public function test_capture_does_not_throw_for_wp_navigation_without_terms(): void {
		// wp_navigation is not theme-bound, so missing taxonomy info
		// is fine.
		$rows        = array(
			array(
				'ID'           => 30,
				'post_name'    => 'navigation',
				'post_title'   => 'Main Nav',
				'post_content' => '<!-- wp:navigation -->',
				'post_status'  => 'publish',
				'post_excerpt' => '',
			),
		);
		$sql_runner  = static fn ( string $sql ): array => $rows;
		$meta_reader = static fn ( int $id, string $key ): mixed => '';
		$term_reader = static fn ( int $id, string $tax ): array => array();

		$capturer = new OwnedPostsCapturer( $sql_runner, $meta_reader, $term_reader, 'twentytwentyfive' );
		$out      = $capturer->capture( new SnapshotScope( post_types_owned: array( 'wp_navigation' ) ) );

		$this->assertArrayHasKey( 'navigation', $out['wp_navigation'] );
		$this->assertArrayNotHasKey( 'terms', $out['wp_navigation']['navigation'] );
	}

	public function test_capture_filters_wp_template_rows_for_other_themes(): void {
		// Two wp_template rows with slug 'home', one for twentytwentyfive
		// and one for twentytwentyfour. Only the source theme's row
		// should be captured. The filter runs off the `wp_theme` term
		// (not postmeta), since WP keys design-state lookup off the
		// taxonomy.
		$rows        = array(
			array(
				'ID'           => 10,
				'post_name'    => 'home',
				'post_title'   => 'TT5 Home',
				'post_content' => 'tt5',
				'post_status'  => 'publish',
				'post_excerpt' => '',
			),
			array(
				'ID'           => 11,
				'post_name'    => 'home',
				'post_title'   => 'TT4 Home',
				'post_content' => 'tt4',
				'post_status'  => 'publish',
				'post_excerpt' => '',
			),
		);
		$sql_runner  = static fn ( string $sql ): array => $rows;
		$themes      = array(
			10 => 'twentytwentyfive',
			11 => 'twentytwentyfour',
		);
		$meta_reader = static fn ( int $id, string $key ): mixed => '';
		$term_reader = static function ( int $id, string $tax ) use ( $themes ): array {
			if ( 'wp_theme' === $tax && isset( $themes[ $id ] ) ) {
				return array( $themes[ $id ] );
			}
			return array();
		};

		$capturer = new OwnedPostsCapturer( $sql_runner, $meta_reader, $term_reader, 'twentytwentyfive' );
		$out      = $capturer->capture( new SnapshotScope( post_types_owned: array( 'wp_template' ) ) );

		$this->assertArrayHasKey( 'home', $out['wp_template'] );
		// The TT5 row wins; TT4 row is filtered out.
		$this->assertSame( 'TT5 Home', $out['wp_template']['home']['post_title'] );
		$this->assertSame( 'tt5', $out['wp_template']['home']['post_content'] );
	}

	public function test_capture_records_postmeta_for_wp_template(): void {
		$rows        = array(
			array(
				'ID'           => 10,
				'post_name'    => 'home',
				'post_title'   => 'Home',
				'post_content' => '',
				'post_status'  => 'publish',
				'post_excerpt' => '',
			),
		);
		$meta        = array(
			'origin'      => 'user',
			'description' => 'Homepage template',
		);
		$sql_runner  = static fn ( string $sql ): array => $rows;
		$meta_reader = static fn ( int $id, string $key ): mixed => $meta[ $key ] ?? '';
		$term_reader = static fn ( int $id, string $tax ): array =>
			'wp_theme' === $tax ? array( 'twentytwentyfive' ) : array();

		$capturer = new OwnedPostsCapturer( $sql_runner, $meta_reader, $term_reader, 'twentytwentyfive' );
		$out      = $capturer->capture( new SnapshotScope( post_types_owned: array( 'wp_template' ) ) );

		$this->assertSame( $meta, $out['wp_template']['home']['meta'] );
	}

	public function test_capture_drops_theme_postmeta_even_when_set(): void {
		// `theme` postmeta is NOT the source of truth for theme binding —
		// the wp_theme taxonomy is. Capture must not ship it: that would
		// be actively misleading, suggesting it has meaning at apply time
		// when WP ignores it entirely.
		$rows        = array(
			array(
				'ID'           => 10,
				'post_name'    => 'home',
				'post_title'   => 'Home',
				'post_content' => '',
				'post_status'  => 'publish',
				'post_excerpt' => '',
			),
		);
		$meta        = array(
			'theme'  => 'twentytwentyfive',
			'origin' => 'user',
		);
		$sql_runner  = static fn ( string $sql ): array => $rows;
		$meta_reader = static fn ( int $id, string $key ): mixed => $meta[ $key ] ?? '';
		$term_reader = static fn ( int $id, string $tax ): array =>
			'wp_theme' === $tax ? array( 'twentytwentyfive' ) : array();

		$capturer = new OwnedPostsCapturer( $sql_runner, $meta_reader, $term_reader, 'twentytwentyfive' );
		$out      = $capturer->capture( new SnapshotScope( post_types_owned: array( 'wp_template' ) ) );

		$this->assertArrayNotHasKey( 'theme', $out['wp_template']['home']['meta'] );
		$this->assertSame( array( 'origin' => 'user' ), $out['wp_template']['home']['meta'] );
	}

	public function test_capture_custom_css_matching_active_stylesheet(): void {
		// custom_css uses post_name = stylesheet slug. The active
		// theme's row should be captured.
		$rows        = array(
			array(
				'ID'           => 50,
				'post_name'    => 'twentytwentyfive',
				'post_title'   => '',
				'post_content' => 'body { background: red; }',
				'post_status'  => 'publish',
				'post_excerpt' => '',
			),
		);
		$sql_runner  = static fn ( string $sql ): array => $rows;
		$meta_reader = static fn ( int $id, string $key ): mixed => '';
		$term_reader = static fn ( int $id, string $tax ): array => array();

		$capturer = new OwnedPostsCapturer( $sql_runner, $meta_reader, $term_reader, 'twentytwentyfive' );
		$out      = $capturer->capture( new SnapshotScope( post_types_owned: array( 'custom_css' ) ) );

		$this->assertArrayHasKey( 'twentytwentyfive', $out['custom_css'] );
		$this->assertSame( 'body { background: red; }', $out['custom_css']['twentytwentyfive']['post_content'] );
		// No taxonomy data emitted — custom_css isn't theme-bound via taxonomy.
		$this->assertArrayNotHasKey( 'terms', $out['custom_css']['twentytwentyfive'] );
	}

	public function test_capture_skips_custom_css_for_other_themes(): void {
		// Two custom_css rows: active theme + prior theme. Only the
		// active theme's row should land in the snapshot — the prior
		// theme's CSS would land on the target as inert dead-weight.
		$rows        = array(
			array(
				'ID'           => 50,
				'post_name'    => 'twentytwentyfive',
				'post_title'   => '',
				'post_content' => 'tt5 css',
				'post_status'  => 'publish',
				'post_excerpt' => '',
			),
			array(
				'ID'           => 51,
				'post_name'    => 'twentytwentyfour',
				'post_title'   => '',
				'post_content' => 'tt4 css (should not ship)',
				'post_status'  => 'publish',
				'post_excerpt' => '',
			),
		);
		$sql_runner  = static fn ( string $sql ): array => $rows;
		$meta_reader = static fn ( int $id, string $key ): mixed => '';
		$term_reader = static fn ( int $id, string $tax ): array => array();

		$capturer = new OwnedPostsCapturer( $sql_runner, $meta_reader, $term_reader, 'twentytwentyfive' );
		$out      = $capturer->capture( new SnapshotScope( post_types_owned: array( 'custom_css' ) ) );

		$this->assertArrayHasKey( 'twentytwentyfive', $out['custom_css'] );
		$this->assertArrayNotHasKey( 'twentytwentyfour', $out['custom_css'] );
	}

	public function test_capture_skips_empty_post_types_in_output(): void {
		// post_types_owned declares 3 types but only one returns rows;
		// the other two should not appear in the output map (no empty
		// keys cluttering the sidecar).
		$sql_runner  = static function ( string $sql ): array {
			if ( false !== strpos( $sql, "'wp_template'" ) ) {
				return array(
					array(
						'ID'           => 10,
						'post_name'    => 'home',
						'post_title'   => 'Home',
						'post_content' => '',
						'post_status'  => 'publish',
						'post_excerpt' => '',
					),
				);
			}
			return array();
		};
		$meta_reader = static fn ( int $id, string $key ): mixed => '';
		$term_reader = static fn ( int $id, string $tax ): array =>
			'wp_theme' === $tax ? array( 'twentytwentyfive' ) : array();

		$capturer = new OwnedPostsCapturer( $sql_runner, $meta_reader, $term_reader, 'twentytwentyfive' );
		$out      = $capturer->capture(
			new SnapshotScope(
				post_types_owned: array( 'wp_template', 'wp_template_part', 'wp_global_styles' )
			)
		);

		$this->assertArrayHasKey( 'wp_template', $out );
		$this->assertArrayNotHasKey( 'wp_template_part', $out );
		$this->assertArrayNotHasKey( 'wp_global_styles', $out );
	}
}
