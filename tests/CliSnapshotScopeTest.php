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

	public function test_scope_with_additive_post_type_is_not_empty(): void {
		$s = new SnapshotScope( post_types_additive: array( 'page' ) );
		$this->assertFalse( $s->is_empty() );
	}

	public function test_scope_with_owned_post_type_is_not_empty(): void {
		$s = new SnapshotScope( post_types_owned: array( 'wp_template' ) );
		$this->assertFalse( $s->is_empty() );
	}

	public function test_scope_with_option_key_is_not_empty(): void {
		$s = new SnapshotScope( option_keys: array( 'blogname' ) );
		$this->assertFalse( $s->is_empty() );
	}

	public function test_scope_with_theme_mods_is_not_empty(): void {
		$s = new SnapshotScope( theme_mods_for: array( 'twentytwentyfive' ) );
		$this->assertFalse( $s->is_empty() );
	}

	public function test_merged_with_dedups_additive_post_types(): void {
		$a = new SnapshotScope( post_types_additive: array( 'page', 'post' ) );
		$b = new SnapshotScope( post_types_additive: array( 'post', 'attachment' ) );

		$merged = $a->merged_with( $b );

		$this->assertSame(
			array( 'page', 'post', 'attachment' ),
			$merged->post_types_additive
		);
	}

	public function test_merged_with_dedups_owned_post_types(): void {
		$a = new SnapshotScope( post_types_owned: array( 'wp_template', 'wp_template_part' ) );
		$b = new SnapshotScope( post_types_owned: array( 'wp_template_part', 'wp_global_styles' ) );

		$merged = $a->merged_with( $b );

		$this->assertSame(
			array( 'wp_template', 'wp_template_part', 'wp_global_styles' ),
			$merged->post_types_owned
		);
	}

	public function test_merged_with_dedups_option_keys(): void {
		$a = new SnapshotScope( option_keys: array( 'blogname', 'page_on_front' ) );
		$b = new SnapshotScope( option_keys: array( 'page_on_front', 'show_on_front' ) );

		$merged = $a->merged_with( $b );

		$this->assertSame(
			array( 'blogname', 'page_on_front', 'show_on_front' ),
			$merged->option_keys
		);
	}

	public function test_merged_with_dedups_attachment_ref_keys(): void {
		$a = new SnapshotScope( option_keys_attachment_refs: array( 'site_logo', 'site_icon' ) );
		$b = new SnapshotScope( option_keys_attachment_refs: array( 'site_icon', 'custom_logo' ) );

		$merged = $a->merged_with( $b );

		$this->assertSame(
			array( 'site_logo', 'site_icon', 'custom_logo' ),
			$merged->option_keys_attachment_refs
		);
	}

	public function test_merged_with_dedups_page_ref_keys(): void {
		$a = new SnapshotScope( option_keys_page_refs: array( 'page_on_front' ) );
		$b = new SnapshotScope( option_keys_page_refs: array( 'page_on_front', 'page_for_posts' ) );

		$merged = $a->merged_with( $b );

		$this->assertSame(
			array( 'page_on_front', 'page_for_posts' ),
			$merged->option_keys_page_refs
		);
	}

	public function test_merged_with_dedups_theme_mods_for(): void {
		$a = new SnapshotScope( theme_mods_for: array( 'twentytwentyfive' ) );
		$b = new SnapshotScope( theme_mods_for: array( 'twentytwentyfive' ) );

		$merged = $a->merged_with( $b );

		$this->assertSame( array( 'twentytwentyfive' ), $merged->theme_mods_for );
	}
}
