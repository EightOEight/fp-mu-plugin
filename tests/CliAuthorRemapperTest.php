<?php
/**
 * Unit tests for AuthorRemapper.
 *
 * @package FrankenPress\Tests
 */

declare(strict_types=1);

namespace FrankenPress\Tests;

use Brain\Monkey;
use Brain\Monkey\Filters;
use FrankenPress\Cli\Apply\AuthorRemapper;
use PHPUnit\Framework\TestCase;

final class CliAuthorRemapperTest extends TestCase {

	private const ENV_KEY = 'FP_APPLY_REMAP_AUTHORS';

	private ?string $env_backup = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$value = getenv( self::ENV_KEY );
		if ( false !== $value ) {
			$this->env_backup = $value;
		}
		putenv( self::ENV_KEY );
	}

	protected function tearDown(): void {
		if ( null !== $this->env_backup ) {
			putenv( self::ENV_KEY . '=' . $this->env_backup );
		} else {
			putenv( self::ENV_KEY );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_dormant_when_env_unset(): void {
		Filters\expectAdded( 'wp_import_post_data_processed' )->never();
		( new AuthorRemapper() )->register();
		$this->assertSame( 0, ( new AuthorRemapper() )->target_user_id() );
	}

	public function test_registers_filter_when_env_set_truthy(): void {
		putenv( self::ENV_KEY . '=1' );
		Filters\expectAdded( 'wp_import_post_data_processed' )->once();
		( new AuthorRemapper() )->register();
		$this->assertSame( 1, ( new AuthorRemapper() )->target_user_id() );
	}

	public function test_explicit_user_id_is_honoured(): void {
		putenv( self::ENV_KEY . '=42' );
		Filters\expectAdded( 'wp_import_post_data_processed' )->once();
		( new AuthorRemapper() )->register();
		$this->assertSame( 42, ( new AuthorRemapper() )->target_user_id() );
	}

	public function test_truthy_strings_default_to_user_1(): void {
		$remapper = new AuthorRemapper();
		foreach ( array( 'true', 'TRUE', 'yes', 'on' ) as $truthy ) {
			putenv( self::ENV_KEY . '=' . $truthy );
			$this->assertSame( 1, $remapper->target_user_id(), "Expected '$truthy' to map to user 1" );
		}
	}

	public function test_falsy_values_are_dormant(): void {
		$remapper = new AuthorRemapper();
		foreach ( array( '', '0', 'false', 'no', 'off' ) as $falsy ) {
			putenv( self::ENV_KEY . '=' . $falsy );
			$this->assertSame( 0, $remapper->target_user_id(), "Expected '$falsy' to be dormant" );
		}
	}

	public function test_negative_or_zero_user_id_is_dormant(): void {
		// 0 is handled by falsy_values above; negative strings aren't
		// caught by ctype_digit (the leading '-' fails the test), so
		// they fall through to the truthy-non-numeric branch — which
		// then defaults to 1 only for explicitly known truthy tokens.
		// "-5" matches none of the truthy tokens, so it returns 0.
		putenv( self::ENV_KEY . '=-5' );
		$this->assertSame( 0, ( new AuthorRemapper() )->target_user_id() );
	}

	public function test_filter_rewrites_post_author(): void {
		$remapper = new AuthorRemapper();
		$captured = null;
		Filters\expectAdded( 'wp_import_post_data_processed' )
			->once()
			->whenHappen(
				static function ( callable $callback ) use ( &$captured ): void {
					$captured = $callback;
				}
			);
		putenv( self::ENV_KEY . '=7' );
		$remapper->register();

		$this->assertNotNull( $captured );
		$postdata = array(
			'post_title'  => 'Hello',
			'post_author' => 99,
		);
		/** @var array<string, mixed> $rewritten */
		$rewritten = $captured( $postdata );
		$this->assertSame( 7, $rewritten['post_author'] );
		$this->assertSame( 'Hello', $rewritten['post_title'] );
	}
}
