<?php
/**
 * Unit tests for FrankenPress\Cli\Apply\Restorer::reap_orphan_owned_posts —
 * the deletion-propagation reaper.
 *
 * Reached via reflection.
 *
 * @package FrankenPress\Tests
 */

declare(strict_types=1);

namespace FrankenPress\Tests;

use FrankenPress\Cli\Apply\Restorer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CliRestorerReapOrphansTest extends TestCase {

	public function test_trashes_owned_post_slug_not_in_captured_set(): void {
		// Target has wp_template_part rows: header (captured), footer
		// (captured), legacy-cta (orphan — not in the snapshot's
		// captured slugs anymore because designer deleted it locally).
		// Reaper trashes legacy-cta, leaves the other two.
		$existing = array(
			array(
				10 => 'header',
				11 => 'footer',
				12 => 'legacy-cta',
			),
		);
		$manifest = $this->manifest(
			array(
				'wp_template_part' => array( 'header', 'footer' ),
			),
			'twentytwentyfive'
		);

		$trashed = $this->reap_and_collect( $existing, $manifest );

		$this->assertSame( array( 12 ), $trashed );
	}

	public function test_no_op_when_templates_slugs_missing(): void {
		// Backward-compat: a manifest from before the reaper field
		// existed. Reaper must NOT trash anything — we don't know
		// which rows are captured vs orphan.
		$existing = array(
			array(
				10 => 'header',
				11 => 'orphan',
			),
		);
		$manifest = array( 'source' => array( 'source_theme' => 'twentytwentyfive' ) );
		// no contents.templates_slugs

		$trashed = $this->reap_and_collect( $existing, $manifest );

		$this->assertSame( array(), $trashed );
	}

	public function test_trashes_all_rows_when_captured_set_is_empty(): void {
		// Phase 3 critical path: designer saves design state to theme
		// files via Create Block Theme; the wp_template_part DB rows
		// go to zero on the source. The new snapshot's
		// `templates_slugs[wp_template_part]` is an EMPTY array
		// (present, but no captured entries). Reaper must trash all
		// existing wp_template_part rows on the target so the orphans
		// from prior Phase-2-shaped applies disappear.
		$existing = array(
			array(
				10 => 'header',
				11 => 'footer',
				12 => 'footer-columns',
			),
		);
		$manifest = $this->manifest(
			array(
				'wp_template_part' => array(), // present but empty — Phase 3 signal
			),
			'twentytwentyfive'
		);

		$trashed = $this->reap_and_collect( $existing, $manifest );

		$this->assertSame( array( 10, 11, 12 ), $trashed );
	}

	public function test_skips_post_type_absent_from_captured_set(): void {
		// Manifest captured wp_template_part slugs but NOT wp_template
		// (e.g. the adapter didn't include wp_template at this snapshot).
		// Reaper must NOT touch wp_template rows. Absence of a captured
		// set is not the same as "captured set is empty".
		$existing = array(
			array( 20 => 'home' ), // wp_template rows lister returns
		);

		$lister_calls = array();
		$lister       = static function ( string $pt, ?string $theme ) use ( &$lister_calls, $existing ): array {
			$lister_calls[] = $pt;
			if ( 'wp_template' === $pt ) {
				return $existing[0];
			}
			return array();
		};

		$manifest = $this->manifest(
			array(
				// wp_template_part captured, but wp_template absent
				'wp_template_part' => array( 'header' ),
			),
			'twentytwentyfive'
		);

		$trashed = array();
		$this->invoke_with(
			$manifest,
			$lister,
			static function ( int $id ) use ( &$trashed ): void {
				$trashed[] = $id;
			}
		);

		$this->assertNotContains( 'wp_template', $lister_calls, 'wp_template should not be queried' );
		$this->assertSame( array(), $trashed );
	}

	public function test_does_not_reap_wp_navigation_or_wp_block(): void {
		// wp_navigation and wp_block are NOT in REAPABLE_POST_TYPES —
		// editors may legitimately add menus or synced patterns on prod
		// that aren't in the snapshot. Reaper skips them.
		$lister_calls = array();
		$lister       = static function ( string $pt, ?string $theme ) use ( &$lister_calls ): array {
			$lister_calls[] = $pt;
			if ( 'wp_navigation' === $pt ) {
				return array( 30 => 'editor-added-nav' );
			}
			if ( 'wp_block' === $pt ) {
				return array( 31 => 'editor-added-pattern' );
			}
			return array();
		};

		// Captured set claims to know about navigation + wp_block
		// (irrelevant — reaper still skips these types entirely).
		$manifest = $this->manifest(
			array(
				'wp_navigation'    => array( 'main-nav' ),
				'wp_block'         => array( 'hero-pattern' ),
				'wp_template_part' => array( 'header' ),
			),
			'twentytwentyfive'
		);

		$trashed = array();
		$this->invoke_with(
			$manifest,
			$lister,
			static function ( int $id ) use ( &$trashed ): void {
				$trashed[] = $id;
			}
		);

		$this->assertNotContains( 'wp_navigation', $lister_calls, 'wp_navigation must not be listed (reaper skips)' );
		$this->assertNotContains( 'wp_block', $lister_calls, 'wp_block must not be listed (reaper skips)' );
		$this->assertSame( array(), $trashed );
	}

	public function test_filters_theme_bound_types_by_active_theme(): void {
		// The lister closure is given the source_theme for theme-bound
		// post types — so the apply-side query only sees rows from
		// the active theme. Verify the theme_slug arg is passed.
		$received_themes = array();
		$lister          = static function ( string $pt, ?string $theme ) use ( &$received_themes ): array {
			$received_themes[ $pt ] = $theme;
			return array();
		};

		$manifest = $this->manifest(
			array(
				'wp_template'      => array(),
				'wp_template_part' => array(),
				'wp_global_styles' => array(),
				'custom_css'       => array(),
			),
			'twentytwentyfive'
		);

		$this->invoke_with( $manifest, $lister, static function (): void {} );

		$this->assertSame( 'twentytwentyfive', $received_themes['wp_template'] );
		$this->assertSame( 'twentytwentyfive', $received_themes['wp_template_part'] );
		$this->assertSame( 'twentytwentyfive', $received_themes['wp_global_styles'] );
		// custom_css is post_name-bound, not taxonomy-bound — no theme
		// filter passed.
		$this->assertNull( $received_themes['custom_css'] );
	}

	public function test_handles_missing_source_theme_gracefully(): void {
		// A manifest with no source.source_theme — reaper should still
		// run for non-theme-bound types but skip theme-bound ones
		// (theme_filter = null prevents tax_query from filtering, but
		// we still pass null so the lister knows).
		$received_themes = array();
		$lister          = static function ( string $pt, ?string $theme ) use ( &$received_themes ): array {
			$received_themes[ $pt ] = $theme;
			return array();
		};

		$manifest = array(
			'contents' => array(
				'templates_slugs' => array(
					'wp_template_part' => array( 'header' ),
				),
			),
			// no source.source_theme
		);

		$this->invoke_with( $manifest, $lister, static function (): void {} );

		// theme is null because source_theme was missing
		$this->assertNull( $received_themes['wp_template_part'] );
	}

	/**
	 * @param array<int, array<int, string>> $existing_rows One array per call to lister; values are post_id => slug.
	 * @param array<string, mixed>           $manifest
	 * @return array<int, int> trashed post IDs in invocation order
	 */
	private function reap_and_collect( array $existing_rows, array $manifest ): array {
		$call_idx = 0;
		$lister   = static function ( string $pt, ?string $theme ) use ( $existing_rows, &$call_idx ): array {
			$out = $existing_rows[ $call_idx ] ?? array();
			++$call_idx;
			return $out;
		};
		$trashed  = array();
		$trasher  = static function ( int $id ) use ( &$trashed ): void {
			$trashed[] = $id;
		};
		$this->invoke_with( $manifest, $lister, $trasher );
		return $trashed;
	}

	private function invoke_with( array $manifest, callable $lister, callable $trasher ): void {
		$snapshot_dir = sys_get_temp_dir() . '/fp-reaper-test-' . uniqid();
		mkdir( $snapshot_dir, 0755, true );

		$restorer = new Restorer(
			$snapshot_dir,
			'http://target.example',
			array(),
			static fn ( string $cmd, array $assoc ): mixed => null,
			static fn ( string $key ): mixed => null,
			static fn ( string $key, mixed $value, bool $autoload ): bool => true,
			static function (): void {},
			static fn ( string $pt, string $slug, array $terms = array() ): ?int => null,
			static function (): void {},
			static fn (): int => 0,
			static fn ( string $rel ): ?int => null,
			static function (): void {},
			static fn (): int => 0,
			static fn ( string $slug, string $type ): ?int => null,
			$lister,
			$trasher,
			'/tmp/uploads',
			'http://target.example/app/uploads',
		);

		$method = new ReflectionMethod( Restorer::class, 'reap_orphan_owned_posts' );
		$method->invoke( $restorer, $manifest );

		rmdir( $snapshot_dir );
	}

	/**
	 * @param array<string, array<int, string>> $slugs_by_post_type
	 */
	private function manifest( array $slugs_by_post_type, string $source_theme ): array {
		return array(
			'source'   => array( 'source_theme' => $source_theme ),
			'contents' => array( 'templates_slugs' => $slugs_by_post_type ),
		);
	}
}
