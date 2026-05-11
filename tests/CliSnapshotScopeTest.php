<?php
/**
 * Unit tests for FrankenPress\Cli\Snapshot\SnapshotScope.
 *
 * @package FrankenPress\Tests
 */

declare(strict_types=1);

namespace FrankenPress\Tests;

use FrankenPress\Cli\Snapshot\SnapshotScope;
use PHPUnit\Framework\TestCase;

final class CliSnapshotScopeTest extends TestCase {

	public function test_empty_scope_reports_empty(): void {
		$this->assertTrue( ( new SnapshotScope() )->is_empty() );
	}

	public function test_scope_with_marker_post_type_is_not_empty(): void {
		$s = new SnapshotScope( post_types_with_marker: array( 'page' => '_meta' ) );
		$this->assertFalse( $s->is_empty() );
	}

	public function test_scope_with_option_pattern_is_not_empty(): void {
		$s = new SnapshotScope( option_patterns: array( 'the7_%' ) );
		$this->assertFalse( $s->is_empty() );
	}

	public function test_merged_with_combines_marker_maps(): void {
		$a = new SnapshotScope( post_types_with_marker: array( 'page' => '_a' ) );
		$b = new SnapshotScope( post_types_with_marker: array( 'post' => '_b' ) );

		$merged = $a->merged_with( $b );

		$this->assertSame(
			array(
				'page' => '_a',
				'post' => '_b',
			),
			$merged->post_types_with_marker
		);
	}

	public function test_merged_with_dedups_full_capture_post_types(): void {
		$a = new SnapshotScope( post_types_full_capture: array( 'nav_menu_item', 'wp_template' ) );
		$b = new SnapshotScope( post_types_full_capture: array( 'wp_template', 'wp_template_part' ) );

		$merged = $a->merged_with( $b );

		$this->assertSame(
			array( 'nav_menu_item', 'wp_template', 'wp_template_part' ),
			$merged->post_types_full_capture
		);
	}

	public function test_merged_with_dedups_option_patterns(): void {
		$a = new SnapshotScope( option_patterns: array( 'the7_%', 'sidebars_widgets' ) );
		$b = new SnapshotScope( option_patterns: array( 'sidebars_widgets', 'elementor_%' ) );

		$merged = $a->merged_with( $b );

		$this->assertSame(
			array( 'the7_%', 'sidebars_widgets', 'elementor_%' ),
			$merged->option_patterns
		);
	}

	public function test_merged_with_dedups_theme_mods_for(): void {
		$a = new SnapshotScope( theme_mods_for: array( 'dt-the7' ) );
		$b = new SnapshotScope( theme_mods_for: array( 'dt-the7' ) );

		$merged = $a->merged_with( $b );

		$this->assertSame( array( 'dt-the7' ), $merged->theme_mods_for );
	}

	public function test_merged_with_dedups_documented_exclusions(): void {
		$a = new SnapshotScope( documented_exclusions: array( 'wc_orders', 'wp_users' ) );
		$b = new SnapshotScope( documented_exclusions: array( 'wp_users', 'wp_comments' ) );

		$merged = $a->merged_with( $b );

		$this->assertSame(
			array( 'wc_orders', 'wp_users', 'wp_comments' ),
			$merged->documented_exclusions
		);
	}

	public function test_merged_with_first_marker_wins_on_key_conflict(): void {
		// If two adapters claim the same post_type with different meta
		// keys, the first adapter's marker is kept (deterministic +
		// the first-registered adapter has priority; in practice this
		// conflict shouldn't occur and would surface as a doc issue).
		$a = new SnapshotScope( post_types_with_marker: array( 'page' => '_a_marker' ) );
		$b = new SnapshotScope( post_types_with_marker: array( 'page' => '_b_marker' ) );

		$merged = $a->merged_with( $b );

		$this->assertSame( '_a_marker', $merged->post_types_with_marker['page'] );
	}
}
