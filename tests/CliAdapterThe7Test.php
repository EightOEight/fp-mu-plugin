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

	public function test_capture_returns_demo_history_when_present(): void {
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

		$state = ( new The7() )->capture();

		$this->assertSame( $history, $state['demo_history'] );
	}

	public function test_capture_returns_empty_array_when_no_demo_history(): void {
		Functions\when( 'get_option' )->justReturn( false );

		$state = ( new The7() )->capture();

		$this->assertArrayNotHasKey( 'demo_history', $state );
	}

	public function test_capture_summarises_dashboard_settings_keys(): void {
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

		$state = ( new The7() )->capture();

		$this->assertSame(
			array( 'accent_color', 'body_font' ),
			$state['dashboard_settings_keys']
		);
	}

	public function test_post_restore_writes_demo_history(): void {
		$captured = array();
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$captured ) {
				$captured[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->justReturn( true );

		$history = array( 'fse-architect' => array( 'post_types' => true ) );
		( new The7() )->post_restore( array( 'demo_history' => $history ) );

		$this->assertSame( $history, $captured['the7_demo_history'] );
	}

	public function test_post_restore_skips_history_when_state_empty(): void {
		$captured = array();
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$captured ) {
				$captured[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->justReturn( true );

		( new The7() )->post_restore( array() );

		$this->assertArrayNotHasKey( 'the7_demo_history', $captured );
	}

	public function test_post_restore_clears_dynamic_css_hash(): void {
		$deleted = array();
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'delete_option' )->alias(
			function ( $key ) use ( &$deleted ) {
				$deleted[] = $key;
				return true;
			}
		);

		( new The7() )->post_restore( array() );

		$this->assertContains( 'the7_last_dynamic_stylesheets_hash', $deleted );
	}
}
