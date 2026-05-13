<?php
/**
 * Unit tests for FrankenPress\Cli\Apply\Restorer's apply_owned_posts
 * path — focused on the v5 taxonomy threading.
 *
 * The full Restorer::apply() flow spawns subprocesses (the WP-Importer
 * `wp import` invocation) and calls wp_runner / option_writer / etc.
 * end-to-end, so a hermetic unit test against it is impractical.
 * These tests use reflection to invoke `apply_owned_posts` directly
 * with a controlled payload + mock closures, verifying that the
 * captured `terms` block reaches owned_finder / owned_inserter /
 * owned_updater correctly.
 *
 * The closures themselves (constructed in Command.php with
 * wp_set_object_terms + tax_query) are integration-tested on the
 * local kind cluster — see
 * `.aidocs/snapshot-design-promotion-hardening.md` Phase 1.6.
 *
 * @package FrankenPress\Tests
 */

declare(strict_types=1);

namespace FrankenPress\Tests;

use FrankenPress\Cli\Apply\Restorer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CliRestorerOwnedPostsTest extends TestCase {

	private string $snapshot_dir;

	protected function setUp(): void {
		$this->snapshot_dir = sys_get_temp_dir() . '/fp-restorer-test-' . uniqid();
		mkdir( $this->snapshot_dir, 0755, true );
	}

	protected function tearDown(): void {
		if ( is_dir( $this->snapshot_dir ) ) {
			$entries = glob( $this->snapshot_dir . '/*' );
			if ( false !== $entries ) {
				foreach ( $entries as $f ) {
					unlink( $f );
				}
			}
			rmdir( $this->snapshot_dir );
		}
	}

	public function test_apply_owned_posts_passes_terms_to_inserter_for_new_row(): void {
		$payload = array(
			'wp_template_part' => array(
				'header' => array(
					'post_title'   => 'Header',
					'post_content' => '<!-- wp:site-title /-->',
					'post_status'  => 'publish',
					'post_excerpt' => '',
					'meta'         => array( 'origin' => 'user' ),
					'terms'        => array(
						'wp_theme'              => array( 'twentytwentyfive' ),
						'wp_template_part_area' => array( 'header' ),
					),
				),
			),
		);
		file_put_contents( $this->snapshot_dir . '/templates.json', json_encode( $payload ) );

		$inserter_calls = array();
		$finder         = static fn ( string $pt, string $slug, array $terms = array() ): ?int => null;
		$inserter       = static function ( string $pt, string $slug, array $fields, array $meta, array $terms = array() ) use ( &$inserter_calls ): int {
			$inserter_calls[] = compact( 'pt', 'slug', 'fields', 'meta', 'terms' );
			return 42;
		};
		$updater        = static function (): void {
			// Should not be called — finder returns null for this test.
		};

		$restorer = $this->build_restorer( $finder, $updater, $inserter );
		$this->invoke_apply_owned_posts( $restorer );

		$this->assertCount( 1, $inserter_calls );
		$this->assertSame( 'wp_template_part', $inserter_calls[0]['pt'] );
		$this->assertSame( 'header', $inserter_calls[0]['slug'] );
		$this->assertSame(
			array(
				'wp_theme'              => array( 'twentytwentyfive' ),
				'wp_template_part_area' => array( 'header' ),
			),
			$inserter_calls[0]['terms']
		);
	}

	public function test_apply_owned_posts_passes_terms_to_updater_for_existing_row(): void {
		$payload = array(
			'wp_template_part' => array(
				'header' => array(
					'post_title'   => 'Header',
					'post_content' => '<!-- wp:site-title /-->',
					'post_status'  => 'publish',
					'post_excerpt' => '',
					'terms'        => array(
						'wp_theme'              => array( 'twentytwentyfive' ),
						'wp_template_part_area' => array( 'header' ),
					),
				),
			),
		);
		file_put_contents( $this->snapshot_dir . '/templates.json', json_encode( $payload ) );

		$finder_calls  = array();
		$updater_calls = array();
		$finder        = static function ( string $pt, string $slug, array $terms = array() ) use ( &$finder_calls ): ?int {
			$finder_calls[] = compact( 'pt', 'slug', 'terms' );
			return 99;
		};
		$updater       = static function ( int $id, array $fields, array $meta, array $terms = array() ) use ( &$updater_calls ): void {
			$updater_calls[] = compact( 'id', 'fields', 'meta', 'terms' );
		};
		$inserter      = static function (): int {
			throw new \RuntimeException( 'inserter should not fire when finder returns an ID' );
		};

		$restorer = $this->build_restorer( $finder, $updater, $inserter );
		$this->invoke_apply_owned_posts( $restorer );

		$this->assertCount( 1, $finder_calls );
		$this->assertSame(
			array( 'wp_theme' => array( 'twentytwentyfive' ) ),
			array_intersect_key( $finder_calls[0]['terms'], array( 'wp_theme' => 1 ) )
		);

		$this->assertCount( 1, $updater_calls );
		$this->assertSame( 99, $updater_calls[0]['id'] );
		$this->assertSame(
			array(
				'wp_theme'              => array( 'twentytwentyfive' ),
				'wp_template_part_area' => array( 'header' ),
			),
			$updater_calls[0]['terms']
		);
	}

	public function test_apply_owned_posts_handles_entry_without_terms(): void {
		// wp_navigation entries don't carry terms (no theme binding).
		// The closures must still be callable — empty array gets passed.
		$payload = array(
			'wp_navigation' => array(
				'navigation' => array(
					'post_title'   => 'Main Nav',
					'post_content' => '<!-- wp:navigation -->',
					'post_status'  => 'publish',
					'post_excerpt' => '',
				),
			),
		);
		file_put_contents( $this->snapshot_dir . '/templates.json', json_encode( $payload ) );

		$inserter_calls = array();
		$finder         = static fn ( string $pt, string $slug, array $terms = array() ): ?int => null;
		$inserter       = static function ( string $pt, string $slug, array $fields, array $meta, array $terms = array() ) use ( &$inserter_calls ): int {
			$inserter_calls[] = compact( 'pt', 'slug', 'terms' );
			return 5;
		};
		$updater        = static function (): void {};

		$restorer = $this->build_restorer( $finder, $updater, $inserter );
		$this->invoke_apply_owned_posts( $restorer );

		$this->assertCount( 1, $inserter_calls );
		$this->assertSame( array(), $inserter_calls[0]['terms'] );
	}

	public function test_apply_owned_posts_no_op_when_templates_json_missing(): void {
		$finder   = static function (): ?int {
			throw new \RuntimeException( 'should not be called' );
		};
		$updater  = static function (): void {
			throw new \RuntimeException( 'should not be called' );
		};
		$inserter = static function (): int {
			throw new \RuntimeException( 'should not be called' );
		};

		$restorer = $this->build_restorer( $finder, $updater, $inserter );
		// No templates.json in $snapshot_dir. apply_owned_posts must
		// return silently rather than throw — the apply() pipeline
		// includes adapters that may not contribute owned posts.
		$this->invoke_apply_owned_posts( $restorer );

		$this->assertTrue( true, 'apply_owned_posts returned without throwing' );
	}

	private function build_restorer( callable $finder, callable $updater, callable $inserter ): Restorer {
		$noop_wp_runner = static fn ( string $cmd, array $assoc ): mixed => null;
		$option_reader  = static fn ( string $key ): mixed => null;
		$option_writer  = static fn ( string $key, mixed $value, bool $autoload ): bool => true;
		$theme_mod_set  = static function ( string $stylesheet, string $key, mixed $value ): void {};
		$att_finder     = static fn ( string $rel ): ?int => null;
		$att_updater    = static function ( int $id, array $fields, array $meta ): void {};
		$att_inserter   = static fn ( array $fields, array $meta ): int => 0;
		$page_finder    = static fn ( string $slug, string $type ): ?int => null;

		return new Restorer(
			$this->snapshot_dir,
			'http://target.example',
			array(),
			$noop_wp_runner,
			$option_reader,
			$option_writer,
			$theme_mod_set,
			$finder,
			$updater,
			$inserter,
			$att_finder,
			$att_updater,
			$att_inserter,
			$page_finder,
			'/tmp/uploads',
			'http://target.example/app/uploads',
		);
	}

	private function invoke_apply_owned_posts( Restorer $restorer ): void {
		// Private methods are reflection-accessible by default in PHP 8.1+;
		// no setAccessible(true) needed (deprecated in 8.5).
		$method = new ReflectionMethod( Restorer::class, 'apply_owned_posts' );
		$method->invoke( $restorer, array() );
	}
}
