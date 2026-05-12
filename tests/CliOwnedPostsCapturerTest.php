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

final class CliOwnedPostsCapturerTest extends TestCase {

	public function test_capture_empty_scope_returns_empty(): void {
		$capturer = new OwnedPostsCapturer(
			static fn ( string $sql ): array => array(),
			static fn ( int $id, string $key ): mixed => '',
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
		$meta_reader = static fn ( int $id, string $key ): mixed => 'theme' === $key ? 'twentytwentyfive' : '';

		$capturer = new OwnedPostsCapturer( $sql_runner, $meta_reader, 'twentytwentyfive' );
		$out      = $capturer->capture( new SnapshotScope( post_types_owned: array( 'wp_template', 'wp_template_part' ) ) );

		$this->assertArrayHasKey( 'wp_template', $out );
		$this->assertArrayHasKey( 'wp_template_part', $out );
		$this->assertArrayHasKey( 'home', $out['wp_template'] );
		$this->assertSame( 'Blog Home', $out['wp_template']['home']['post_title'] );
		$this->assertSame( '<!-- wp:home -->', $out['wp_template']['home']['post_content'] );
	}

	public function test_capture_filters_wp_template_rows_for_other_themes(): void {
		// Two wp_template rows with slug 'home', one for twentytwentyfive
		// and one for twentytwentyfour. Only the source theme's row
		// should be captured.
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
		$meta_reader = static fn ( int $id, string $key ): mixed => 'theme' === $key ? ( $themes[ $id ] ?? '' ) : '';

		$capturer = new OwnedPostsCapturer( $sql_runner, $meta_reader, 'twentytwentyfive' );
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
			'theme'  => 'twentytwentyfive',
			'origin' => 'user',
		);
		$sql_runner  = static fn ( string $sql ): array => $rows;
		$meta_reader = static fn ( int $id, string $key ): mixed => $meta[ $key ] ?? '';

		$capturer = new OwnedPostsCapturer( $sql_runner, $meta_reader, 'twentytwentyfive' );
		$out      = $capturer->capture( new SnapshotScope( post_types_owned: array( 'wp_template' ) ) );

		$this->assertSame( $meta, $out['wp_template']['home']['meta'] );
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

		$capturer = new OwnedPostsCapturer( $sql_runner, $meta_reader, 'twentytwentyfive' );
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
