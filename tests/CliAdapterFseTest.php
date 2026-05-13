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

	public function test_scope_owned_post_types_cover_fse_design_surface(): void {
		// FSE CPTs are upsert-on-apply via templates.json (v4 split).
		// The Fse adapter owns these end-to-end.
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfive' );
		$scope = ( new Fse() )->scope();
		$this->assertContains( 'wp_template', $scope->post_types_owned );
		$this->assertContains( 'wp_template_part', $scope->post_types_owned );
		$this->assertContains( 'wp_global_styles', $scope->post_types_owned );
		$this->assertContains( 'wp_navigation', $scope->post_types_owned );
	}

	public function test_scope_additive_post_types_is_empty(): void {
		// Post v0.12.0: page/post/attachment are content (editor-owned)
		// and OUT of the designer snapshot scope entirely. See
		// `feedback_snapshot_design_not_content.md`. The
		// post_types_additive field stays in the schema for future
		// adapters that might need editor-content sync, but Fse is
		// design-only.
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfive' );
		$scope = ( new Fse() )->scope();
		$this->assertSame( array(), $scope->post_types_additive );
	}

	public function test_scope_declares_logo_options_as_attachment_refs(): void {
		// Designer-asset imagery (logos, favicons) rides via the
		// `option_keys_attachment_refs` field. AttachmentRefCapturer
		// captures the referenced posts + binary files.
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfive' );
		$scope = ( new Fse() )->scope();
		$this->assertContains( 'site_logo', $scope->option_keys_attachment_refs );
		$this->assertContains( 'site_icon', $scope->option_keys_attachment_refs );
		$this->assertContains( 'custom_logo', $scope->option_keys_attachment_refs );
	}

	public function test_attachment_ref_options_are_also_in_option_keys(): void {
		// option_keys_attachment_refs is a subset of option_keys —
		// the values ride through options.json normally; the refs
		// list just flags which ones need ID remapping.
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfive' );
		$scope = ( new Fse() )->scope();
		foreach ( $scope->option_keys_attachment_refs as $key ) {
			$this->assertContains( $key, $scope->option_keys );
		}
	}

	public function test_scope_declares_page_on_front_as_page_ref(): void {
		// Homepage settings carry local page IDs; capture records the
		// slug + post_type via option_keys_page_refs so apply can
		// resolve to the target's local page ID.
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfive' );
		$scope = ( new Fse() )->scope();
		$this->assertContains( 'page_on_front', $scope->option_keys_page_refs );
		$this->assertContains( 'page_for_posts', $scope->option_keys_page_refs );
	}

	public function test_page_ref_options_are_also_in_option_keys(): void {
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfive' );
		$scope = ( new Fse() )->scope();
		foreach ( $scope->option_keys_page_refs as $key ) {
			$this->assertContains( $key, $scope->option_keys );
		}
	}

	public function test_scope_includes_custom_css_post_type(): void {
		// Site Editor / Customizer "Additional CSS" lives in a
		// custom_css post (post_name = stylesheet slug). Captured so
		// designer CSS edits travel through the snapshot.
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfive' );
		$scope = ( new Fse() )->scope();
		$this->assertContains( 'custom_css', $scope->post_types_owned );
	}

	public function test_scope_includes_wp_block_post_type(): void {
		// Synced patterns live in wp_block posts; other owned posts
		// reference them via `wp:block {"ref":N}`. Captured so the
		// pattern + its refs travel through the snapshot.
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfive' );
		$scope = ( new Fse() )->scope();
		$this->assertContains( 'wp_block', $scope->post_types_owned );
	}

	public function test_scope_split_has_no_overlap(): void {
		// A post_type must be in exactly one bucket; can't be both
		// additive and owned.
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfive' );
		$scope   = ( new Fse() )->scope();
		$overlap = array_intersect( $scope->post_types_additive, $scope->post_types_owned );
		$this->assertSame( array(), $overlap );
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
