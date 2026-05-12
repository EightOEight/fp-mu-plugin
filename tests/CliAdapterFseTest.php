<?php
/**
 * Unit tests for FrankenPress\Cli\Adapters\Fse.
 *
 * @package FrankenPress\Tests
 */

declare(strict_types=1);

namespace FrankenPress\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FrankenPress\Cli\Adapters\Fse;
use PHPUnit\Framework\TestCase;

final class CliAdapterFseTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_name_is_fse(): void {
		$this->assertSame( 'fse', ( new Fse() )->name() );
	}

	public function test_detect_returns_true_when_block_theme_active(): void {
		Functions\when( 'wp_is_block_theme' )->justReturn( true );
		$this->assertTrue( ( new Fse() )->detect() );
	}

	public function test_detect_returns_false_for_classic_theme(): void {
		Functions\when( 'wp_is_block_theme' )->justReturn( false );
		$this->assertFalse( ( new Fse() )->detect() );
	}

	public function test_scope_post_types_cover_fse_design_surface(): void {
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfive' );
		$scope = ( new Fse() )->scope();
		$this->assertContains( 'wp_template', $scope->post_types );
		$this->assertContains( 'wp_template_part', $scope->post_types );
		$this->assertContains( 'wp_global_styles', $scope->post_types );
		$this->assertContains( 'wp_navigation', $scope->post_types );
	}

	public function test_scope_post_types_include_attachment_for_uploads(): void {
		// The v2 era left attachment out-of-scope; v3 + Fse adapter
		// includes it so images captured by Site Editor land cleanly on
		// apply (closes the FSE-Corp image-404 follow-up).
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfive' );
		$scope = ( new Fse() )->scope();
		$this->assertContains( 'attachment', $scope->post_types );
	}

	public function test_scope_post_types_include_page_and_post(): void {
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfive' );
		$scope = ( new Fse() )->scope();
		$this->assertContains( 'page', $scope->post_types );
		$this->assertContains( 'post', $scope->post_types );
	}

	public function test_scope_option_keys_cover_site_identity(): void {
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfive' );
		$scope = ( new Fse() )->scope();
		$this->assertContains( 'blogname', $scope->option_keys );
		$this->assertContains( 'show_on_front', $scope->option_keys );
		$this->assertContains( 'page_on_front', $scope->option_keys );
		$this->assertContains( 'permalink_structure', $scope->option_keys );
	}

	public function test_scope_option_keys_exclude_user_and_widget_keys(): void {
		// Belt-and-braces: explicit assertions that UGC-adjacent keys
		// don't accidentally creep into the FSE scope. Widget keys are
		// also out (block-based sites don't use classic widgets).
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfive' );
		$scope = ( new Fse() )->scope();
		$this->assertNotContains( 'wp_user_roles', $scope->option_keys );
		$this->assertNotContains( 'sidebars_widgets', $scope->option_keys );
		foreach ( $scope->option_keys as $k ) {
			$this->assertStringStartsNotWith( 'widget_', $k );
		}
	}

	public function test_scope_theme_mods_target_active_stylesheet(): void {
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfive' );
		$scope = ( new Fse() )->scope();
		$this->assertSame( array( 'twentytwentyfive' ), $scope->theme_mods_for );
	}

	public function test_capture_state_records_source_theme(): void {
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfive' );
		$state = ( new Fse() )->capture_state();
		$this->assertSame( 'twentytwentyfive', $state['source_theme'] );
	}

	public function test_post_apply_deletes_orphan_wp_global_styles(): void {
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfive' );

		$posts = array( 101, 102, 103 );
		Functions\when( 'get_posts' )->justReturn( $posts );
		Functions\when( 'get_post' )->alias(
			static function ( int $id ) {
				$slugs        = array(
					101 => 'wp-global-styles-dt-the7',
					102 => 'wp-global-styles-twentytwentyfive',
					103 => 'wp-global-styles-twentytwentyfour',
				);
				$p            = new \stdClass();
				$p->ID        = $id;
				$p->post_name = $slugs[ $id ] ?? '';
				return $p;
			}
		);
		$deleted = array();
		Functions\when( 'wp_delete_post' )->alias(
			static function ( int $id, bool $force ) use ( &$deleted ): bool {
				$deleted[] = array( $id, $force );
				return true;
			}
		);

		( new Fse() )->post_apply( array( 'source_theme' => 'dt-the7' ) );

		$this->assertSame(
			array(
				array( 101, true ),
				array( 103, true ),
			),
			$deleted
		);
	}

	public function test_post_apply_noop_when_no_orphans(): void {
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfive' );
		Functions\when( 'get_posts' )->justReturn( array( 200 ) );
		Functions\when( 'get_post' )->alias(
			static function ( int $id ) {
				$p            = new \stdClass();
				$p->ID        = $id;
				$p->post_name = 'wp-global-styles-twentytwentyfive';
				return $p;
			}
		);
		$delete_called = false;
		Functions\when( 'wp_delete_post' )->alias(
			static function () use ( &$delete_called ): bool {
				$delete_called = true;
				return true;
			}
		);

		( new Fse() )->post_apply( array() );

		$this->assertFalse( $delete_called );
	}
}
