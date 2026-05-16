<?php
/**
 * Unit tests for FrankenPress\Cli\Apply\Restorer::apply_options —
 * specifically the v0.14 option_page_refs slug-resolution path.
 *
 * Reached via reflection.
 *
 * @package FrankenPress\Tests
 */

declare(strict_types=1);

namespace FrankenPress\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FrankenPress\Cli\Apply\Restorer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CliRestorerApplyOptionsTest extends TestCase {

	private string $snapshot_dir;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->snapshot_dir = sys_get_temp_dir() . '/fp-apply-options-test-' . uniqid();
		mkdir( $this->snapshot_dir, 0755, true );
	}

	protected function tearDown(): void {
		$this->rmrf( $this->snapshot_dir );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_applies_literal_option_value_when_no_page_ref(): void {
		$this->write_options(
			array(
				'options'    => array( 'blogname' => 'Sole Trader Support' ),
				'theme_mods' => array(),
			)
		);

		$writes      = array();
		$writer      = static function ( string $key, mixed $value, bool $autoload ) use ( &$writes ): bool {
			$writes[ $key ] = $value;
			return true;
		};
		$page_finder = static function (): ?int {
			throw new \RuntimeException( 'page_finder should not fire when no page_refs are present' );
		};

		$this->invoke( $writer, $page_finder );

		$this->assertSame( 'Sole Trader Support', $writes['blogname'] );
	}

	public function test_applies_page_ref_by_slug_when_found_on_target(): void {
		// Source captured page_on_front=12 (slug "home"). Target has
		// a page with slug "home" at id 5. Apply should write 5.
		$this->write_options(
			array(
				'options'          => array( 'page_on_front' => 12 ),
				'theme_mods'       => array(),
				'option_page_refs' => array(
					'page_on_front' => array(
						'slug' => 'home',
						'type' => 'page',
					),
				),
			)
		);

		$writes      = array();
		$writer      = static function ( string $key, mixed $value, bool $autoload ) use ( &$writes ): bool {
			$writes[ $key ] = $value;
			return true;
		};
		$page_finder = static function ( string $slug, string $type ): ?int {
			return ( 'home' === $slug && 'page' === $type ) ? 5 : null;
		};

		$this->invoke( $writer, $page_finder );

		$this->assertSame( '5', $writes['page_on_front'] );
	}

	public function test_skips_page_ref_write_when_slug_not_found_on_target(): void {
		// Source captured page_on_front=12 (slug "home"). Target has
		// NO page with that slug — apply must skip the option write
		// rather than stamping the captured ID 12 (which points at
		// nothing on the target).
		$this->write_options(
			array(
				'options'          => array( 'page_on_front' => 12 ),
				'theme_mods'       => array(),
				'option_page_refs' => array(
					'page_on_front' => array(
						'slug' => 'home',
						'type' => 'page',
					),
				),
			)
		);

		$writes      = array();
		$writer      = static function ( string $key, mixed $value, bool $autoload ) use ( &$writes ): bool {
			$writes[ $key ] = $value;
			return true;
		};
		$page_finder = static fn ( string $slug, string $type ): ?int => null;

		$this->invoke( $writer, $page_finder );

		$this->assertArrayNotHasKey( 'page_on_front', $writes );
	}

	public function test_does_not_apply_page_finder_to_non_page_ref_options(): void {
		// blogname is in options but not in option_page_refs. The
		// page_finder must not be invoked; the literal value writes.
		$this->write_options(
			array(
				'options'          => array(
					'blogname'      => 'STS',
					'page_on_front' => 12,
				),
				'theme_mods'       => array(),
				'option_page_refs' => array(
					'page_on_front' => array(
						'slug' => 'home',
						'type' => 'page',
					),
				),
			)
		);

		$writes      = array();
		$writer      = static function ( string $key, mixed $value, bool $autoload ) use ( &$writes ): bool {
			$writes[ $key ] = $value;
			return true;
		};
		$lookups     = array();
		$page_finder = static function ( string $slug, string $type ) use ( &$lookups ): ?int {
			$lookups[] = "$slug:$type";
			return 5;
		};

		$this->invoke( $writer, $page_finder );

		$this->assertSame( 'STS', $writes['blogname'] );
		$this->assertSame( '5', $writes['page_on_front'] );
		// page_finder fired exactly once — for page_on_front, not for blogname.
		$this->assertSame( array( 'home:page' ), $lookups );
	}

	public function test_falls_back_to_literal_when_page_ref_entry_is_malformed(): void {
		// A page_ref entry missing slug or type is malformed. Apply
		// should not invoke page_finder; it falls through to the
		// literal value write so behavior degrades to the pre-v0.14
		// shape rather than swallowing the option entirely.
		$this->write_options(
			array(
				'options'          => array( 'page_on_front' => 12 ),
				'theme_mods'       => array(),
				'option_page_refs' => array(
					'page_on_front' => array( 'slug' => '' ), // missing type, blank slug
				),
			)
		);

		$writes      = array();
		$writer      = static function ( string $key, mixed $value, bool $autoload ) use ( &$writes ): bool {
			$writes[ $key ] = $value;
			return true;
		};
		$page_finder = static function (): ?int {
			throw new \RuntimeException( 'page_finder should not fire when page_ref is malformed' );
		};

		$this->invoke( $writer, $page_finder );

		// Literal captured value passed through.
		$this->assertSame( 12, $writes['page_on_front'] );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function test_flushes_rewrite_rules_after_options_applied(): void {
		// permalink_structure landing without a rewrite-rules flush is
		// the bug that 404'd every post permalink on freshly-installed
		// sts/eoe pods. Confirm the flush call fires unconditionally
		// at the end of apply_options — covers permalink-structure
		// changes AND any future option that should invalidate
		// route resolution.
		$this->write_options(
			array(
				'options'    => array(
					'permalink_structure' => '/%year%/%monthnum%/%day%/%postname%/',
				),
				'theme_mods' => array(),
			)
		);

		$flush_calls = array();
		Functions\when( 'flush_rewrite_rules' )->alias(
			static function ( bool $hard ) use ( &$flush_calls ): void {
				$flush_calls[] = $hard;
			}
		);

		$writer      = static fn ( string $key, mixed $value, bool $autoload ): bool => true;
		$page_finder = static fn (): ?int => null;

		$this->invoke( $writer, $page_finder );

		$this->assertSame( array( false ), $flush_calls, 'flush_rewrite_rules must fire exactly once with hard=false at the end of apply_options' );
	}

	private function write_options( array $payload ): void {
		file_put_contents( $this->snapshot_dir . '/options.json', json_encode( $payload ) );
	}

	private function invoke( callable $writer, callable $page_finder ): void {
		$restorer = $this->build_restorer( $writer, $page_finder );
		$method   = new ReflectionMethod( Restorer::class, 'apply_options' );
		$method->invoke( $restorer, array() );
	}

	private function build_restorer( callable $writer, callable $page_finder ): Restorer {
		return new Restorer(
			$this->snapshot_dir,
			'http://target.example',
			array(),
			static fn ( string $cmd, array $assoc ): mixed => null,
			static fn ( string $key ): mixed => null,
			$writer,
			static function (): void {},
			static fn ( string $pt, string $slug, array $terms = array() ): ?int => null,
			static function (): void {},
			static fn (): int => 0,
			static fn ( string $rel ): ?int => null,
			static function (): void {},
			static fn (): int => 0,
			$page_finder,
			static fn ( string $pt, ?string $theme ): array => array(),
			static function (): void {},
			'/tmp/uploads',
			'http://target.example/app/uploads',
		);
	}

	private function rmrf( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$entries = glob( $dir . '/*' );
		if ( false !== $entries ) {
			foreach ( $entries as $f ) {
				is_dir( $f ) ? $this->rmrf( $f ) : unlink( $f );
			}
		}
		rmdir( $dir );
	}
}
