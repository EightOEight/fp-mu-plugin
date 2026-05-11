<?php
/**
 * Unit tests for FrankenPress\Cli\Adapters\The7.
 *
 * @package FrankenPress\Tests
 */

declare(strict_types=1);

namespace FrankenPress\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FrankenPress\Cli\Adapters\The7;
use PHPUnit\Framework\TestCase;

final class CliAdapterThe7Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_name_is_the7(): void {
		$this->assertSame( 'the7', ( new The7() )->name() );
	}

	public function test_detect_returns_true_when_the7_is_active(): void {
		$theme = new class() {
			public function get_template(): string {
				return The7::THEME_SLUG;
			}
		};
		Functions\when( 'wp_get_theme' )->justReturn( $theme );

		$this->assertTrue( ( new The7() )->detect() );
	}

	public function test_detect_returns_false_for_other_themes(): void {
		$theme = new class() {
			public function get_template(): string {
				return 'twentytwentyfive';
			}
		};
		Functions\when( 'wp_get_theme' )->justReturn( $theme );

		$this->assertFalse( ( new The7() )->detect() );
	}

	public function test_scope_declares_marker_for_pages_and_posts(): void {
		$scope = ( new The7() )->scope();
		$this->assertArrayHasKey( 'page', $scope->post_types_with_marker );
		$this->assertArrayHasKey( 'post', $scope->post_types_with_marker );
		$this->assertSame( The7::IMPORTED_ITEM_META, $scope->post_types_with_marker['page'] );
	}

	public function test_scope_includes_nav_menu_items_in_full_capture(): void {
		$scope = ( new The7() )->scope();
		$this->assertContains( 'nav_menu_item', $scope->post_types_full_capture );
	}

	public function test_scope_option_patterns_cover_the7_and_elementor(): void {
		$scope = ( new The7() )->scope();
		$this->assertContains( 'the7_%', $scope->option_patterns );
		$this->assertContains( 'elementor_%', $scope->option_patterns );
		$this->assertContains( 'sidebars_widgets', $scope->option_patterns );
	}

	public function test_scope_includes_the_theme_mods_slug(): void {
		$scope = ( new The7() )->scope();
		$this->assertContains( The7::THEME_SLUG, $scope->theme_mods_for );
	}

	public function test_scope_documents_critical_exclusions(): void {
		$scope = ( new The7() )->scope();
		// The whole point of the scope is the safety boundary — these
		// must always be in documented_exclusions, never in any other
		// field.
		$this->assertContains( 'wc_orders', $scope->documented_exclusions );
		$this->assertContains( 'wp_users', $scope->documented_exclusions );
		$this->assertContains( 'wp_comments', $scope->documented_exclusions );
	}

	public function test_capture_state_returns_demo_history_when_present(): void {
		$history = array(
			'fse-architect' => array(
				'post_types'    => true,
				'attachments'   => 'original',
				'theme_options' => true,
			),
		);
		Functions\when( 'get_option' )->alias(
			function ( string $key ) use ( $history ) {
				if ( 'the7_demo_history' === $key ) {
						return $history;
				}
				return false;
			}
		);

		$state = ( new The7() )->capture_state();

		$this->assertSame( $history, $state['demo_history'] );
	}

	public function test_capture_state_summarises_dashboard_settings_keys(): void {
		Functions\when( 'get_option' )->alias(
			function ( string $key ) {
				if ( 'the7_dashboard_settings' === $key ) {
						return array(
							'accent_color' => '#fff',
							'body_font'    => 'Inter',
						);
				}
				return false;
			}
		);

		$state = ( new The7() )->capture_state();

		$this->assertSame( array( 'accent_color', 'body_font' ), $state['dashboard_settings_keys'] );
	}

	public function test_post_apply_writes_demo_history(): void {
		$captured = array();
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$captured ) {
				$captured[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->justReturn( true );

		$history = array( 'fse-architect' => array( 'post_types' => true ) );
		( new The7() )->post_apply( array( 'demo_history' => $history ) );

		$this->assertSame( $history, $captured['the7_demo_history'] );
	}

	public function test_post_apply_clears_dynamic_css_hash(): void {
		$deleted = array();
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'delete_option' )->alias(
			function ( $key ) use ( &$deleted ) {
				$deleted[] = $key;
				return true;
			}
		);

		( new The7() )->post_apply( array() );

		$this->assertContains( 'the7_last_dynamic_stylesheets_hash', $deleted );
	}
}
