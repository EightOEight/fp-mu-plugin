<?php
/**
 * Unit tests for FrankenPress\Cli\Snapshot\OptionsCapturer.
 *
 * @package FrankenPress\Tests
 */

declare(strict_types=1);

namespace FrankenPress\Tests;

use FrankenPress\Cli\Snapshot\OptionsCapturer;
use FrankenPress\Cli\Snapshot\SnapshotScope;
use PHPUnit\Framework\TestCase;

final class CliOptionsCapturerTest extends TestCase {

	public function test_capture_collects_scoped_options_only(): void {
		$opts          = array(
			'blogname'        => 'Sole Trader Support',
			'blogdescription' => 'Helping you start your business',
			'show_on_front'   => 'page',
			'random_other'    => 'should be skipped',
		);
		$page_resolver = static fn ( int $id ): ?array => null;
		$capturer      = new OptionsCapturer(
			static fn ( string $key ): mixed => $opts[ $key ] ?? null,
			$page_resolver,
		);

		$out = $capturer->capture(
			new SnapshotScope(
				option_keys: array( 'blogname', 'blogdescription', 'show_on_front' )
			)
		);

		$this->assertSame(
			array(
				'blogdescription' => 'Helping you start your business',
				'blogname'        => 'Sole Trader Support',
				'show_on_front'   => 'page',
			),
			$out['options']
		);
		$this->assertArrayNotHasKey( 'random_other', $out['options'] );
	}

	public function test_capture_records_option_page_refs_with_slug_and_type(): void {
		// page_on_front: 12 → slug "home"; page_for_posts: 15 → slug "blog".
		// Apply uses slug + type to look up by path on the target.
		$opts          = array(
			'page_on_front'  => 12,
			'page_for_posts' => 15,
		);
		$pages         = array(
			12 => array(
				'slug' => 'home',
				'type' => 'page',
			),
			15 => array(
				'slug' => 'blog',
				'type' => 'page',
			),
		);
		$page_resolver = static fn ( int $id ): ?array => $pages[ $id ] ?? null;

		$capturer = new OptionsCapturer(
			static fn ( string $key ): mixed => $opts[ $key ] ?? null,
			$page_resolver,
		);

		$out = $capturer->capture(
			new SnapshotScope(
				option_keys:           array( 'page_on_front', 'page_for_posts' ),
				option_keys_page_refs: array( 'page_on_front', 'page_for_posts' ),
			)
		);

		$this->assertSame(
			array(
				'page_for_posts' => array(
					'slug' => 'blog',
					'type' => 'page',
				),
				'page_on_front'  => array(
					'slug' => 'home',
					'type' => 'page',
				),
			),
			$out['option_page_refs']
		);
	}

	public function test_capture_skips_page_ref_when_post_no_longer_exists(): void {
		// page_on_front points at a post that's been trashed/deleted.
		// page_resolver returns null. The page_ref is omitted so apply
		// leaves the target's existing value alone.
		$opts          = array( 'page_on_front' => 99 );
		$page_resolver = static fn ( int $id ): ?array => null; // not found

		$capturer = new OptionsCapturer(
			static fn ( string $key ): mixed => $opts[ $key ] ?? null,
			$page_resolver,
		);

		$out = $capturer->capture(
			new SnapshotScope(
				option_keys:           array( 'page_on_front' ),
				option_keys_page_refs: array( 'page_on_front' ),
			)
		);

		$this->assertArrayNotHasKey( 'page_on_front', $out['option_page_refs'] );
		// The literal option value still ships — apply will pass it
		// through unchanged when no page_ref entry is present.
		$this->assertSame( 99, $out['options']['page_on_front'] );
	}

	public function test_capture_skips_page_ref_when_option_is_zero(): void {
		// page_on_front=0 means "no static homepage selected" (show
		// blog index). Nothing to resolve.
		$opts          = array( 'page_on_front' => 0 );
		$page_resolver = static fn ( int $id ): ?array => array(
			'slug' => 'wrong',
			'type' => 'wrong',
		);

		$capturer = new OptionsCapturer(
			static fn ( string $key ): mixed => $opts[ $key ] ?? null,
			$page_resolver,
		);

		$out = $capturer->capture(
			new SnapshotScope(
				option_keys:           array( 'page_on_front' ),
				option_keys_page_refs: array( 'page_on_front' ),
			)
		);

		$this->assertArrayNotHasKey( 'page_on_front', $out['option_page_refs'] );
	}

	public function test_capture_skips_unset_options(): void {
		// `get_option` returns false for unset options; we filter those
		// out so the sidecar stays tight and apply doesn't null-out
		// values the target may legitimately have.
		$page_resolver = static fn ( int $id ): ?array => null;
		$capturer      = new OptionsCapturer(
			static fn ( string $key ): mixed => false,
			$page_resolver,
		);

		$out = $capturer->capture(
			new SnapshotScope( option_keys: array( 'blogname', 'site_logo' ) )
		);

		$this->assertSame( array(), $out['options'] );
	}

	public function test_capture_collects_theme_mods_for_active_stylesheet(): void {
		$opts          = array(
			'theme_mods_twentytwentyfive' => array(
				'custom_css_post_id' => -1,
				'header_textcolor'   => '#fff',
			),
		);
		$page_resolver = static fn ( int $id ): ?array => null;
		$capturer      = new OptionsCapturer(
			static fn ( string $key ): mixed => $opts[ $key ] ?? null,
			$page_resolver,
		);

		$out = $capturer->capture(
			new SnapshotScope( theme_mods_for: array( 'twentytwentyfive' ) )
		);

		$this->assertArrayHasKey( 'twentytwentyfive', $out['theme_mods'] );
		$this->assertSame( '#fff', $out['theme_mods']['twentytwentyfive']['header_textcolor'] );
	}

	public function test_capture_returns_empty_page_refs_when_scope_is_empty(): void {
		// option_keys_page_refs unset/empty → option_page_refs is
		// always present (as []) so apply can iterate without a
		// presence check.
		$page_resolver = static fn ( int $id ): ?array => null;
		$capturer      = new OptionsCapturer(
			static fn ( string $key ): mixed => 'whatever',
			$page_resolver,
		);

		$out = $capturer->capture( new SnapshotScope( option_keys: array( 'blogname' ) ) );

		$this->assertArrayHasKey( 'option_page_refs', $out );
		$this->assertSame( array(), $out['option_page_refs'] );
	}
}
