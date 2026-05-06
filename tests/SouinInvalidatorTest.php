<?php
/**
 * Unit tests for SouinInvalidator.
 *
 * Uses Brain Monkey to stub WordPress functions and Mockery to mock the
 * Redis client. We don't connect to a real Redis here; these tests verify
 * the cache key shape and call sequence we send to whichever Redis
 * happens to be there at runtime.
 *
 * @package FrankenPress\Tests
 */

declare(strict_types=1);

namespace FrankenPress\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FrankenPress\SouinInvalidator;
use Mockery;
use PHPUnit\Framework\TestCase;

final class SouinInvalidatorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubs(
			array(
				'wp_parse_url' => static fn ( string $url ) => parse_url( $url ),
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	public function test_invalidate_url_dels_both_http_and_https_variants(): void {
		$redis = Mockery::mock( '\Redis' );
		$redis->shouldReceive( 'del' )
			->once()
			->with(
				array(
					'GET-http-example.com-/post/1',
					'IDX_GET-http-example.com-/post/1',
					'GET-https-example.com-/post/1',
					'IDX_GET-https-example.com-/post/1',
				)
			)
			->andReturn( 2 );

		$invalidator = new SouinInvalidator( $redis );
		// Caller passes https; the invalidator DELs both schemes
		// regardless. Defends against scheme drift between WP's
		// home_url() and Souin's observed request scheme.
		$deleted = $invalidator->invalidate_url( 'https://example.com/post/1' );

		$this->assertSame( 2, $deleted );
	}

	public function test_invalidate_url_includes_port_when_non_standard(): void {
		$redis = Mockery::mock( '\Redis' );
		$redis->shouldReceive( 'del' )
			->once()
			->with(
				array(
					'GET-http-localhost:8080-/post/42',
					'IDX_GET-http-localhost:8080-/post/42',
					'GET-https-localhost:8080-/post/42',
					'IDX_GET-https-localhost:8080-/post/42',
				)
			)
			->andReturn( 7 );

		$invalidator = new SouinInvalidator( $redis );
		$this->assertSame( 7, $invalidator->invalidate_url( 'http://localhost:8080/post/42' ) );
	}

	public function test_invalidate_url_includes_query_string(): void {
		$redis = Mockery::mock( '\Redis' );
		$redis->shouldReceive( 'del' )
			->once()
			->with(
				array(
					'GET-http-example.com-/search?q=foo',
					'IDX_GET-http-example.com-/search?q=foo',
					'GET-https-example.com-/search?q=foo',
					'IDX_GET-https-example.com-/search?q=foo',
				)
			)
			->andReturn( 1 );

		$invalidator = new SouinInvalidator( $redis );
		$this->assertSame( 1, $invalidator->invalidate_url( 'https://example.com/search?q=foo' ) );
	}

	public function test_invalidate_url_returns_zero_for_invalid_url(): void {
		$redis = Mockery::mock( '\Redis' );
		$redis->shouldNotReceive( 'del' );

		$invalidator = new SouinInvalidator( $redis );
		$this->assertSame( 0, $invalidator->invalidate_url( 'not a url' ) );
		$this->assertSame( 0, $invalidator->invalidate_url( '' ) );
	}

	public function test_invalidate_tag_reads_surrogate_set_then_pipelines_del(): void {
		$redis = Mockery::mock( '\Redis' );
		$redis->shouldReceive( 'sMembers' )
			->once()
			->with( 'SURROGATE_post-1' )
			->andReturn(
				array(
					'GET-https-example.com-/post/1',
					'GET-https-example.com-/category/news',
				)
			);
		$redis->shouldReceive( 'del' )
			->once()
			->with(
				array(
					'GET-https-example.com-/post/1',
					'IDX_GET-https-example.com-/post/1',
					'GET-https-example.com-/category/news',
					'IDX_GET-https-example.com-/category/news',
					'SURROGATE_post-1',
				)
			)
			->andReturn( 5 );

		$invalidator = new SouinInvalidator( $redis );
		$this->assertSame( 5, $invalidator->invalidate_tag( 'post-1' ) );
	}

	public function test_invalidate_tag_handles_empty_set(): void {
		$redis = Mockery::mock( '\Redis' );
		$redis->shouldReceive( 'sMembers' )->once()->andReturn( array() );
		$redis->shouldReceive( 'del' )
			->once()
			->with( array( 'SURROGATE_orphan' ) )
			->andReturn( 0 );

		$invalidator = new SouinInvalidator( $redis );
		$this->assertSame( 0, $invalidator->invalidate_tag( 'orphan' ) );
	}

	public function test_redis_failures_are_silently_swallowed(): void {
		$redis = Mockery::mock( '\Redis' );
		$redis->shouldReceive( 'del' )->andThrow( new \RuntimeException( 'connection lost' ) );

		// Mute error_log via a custom error handler so PHPUnit doesn't
		// flag the test as risky for emitting output.
		$prev = set_error_handler( static fn () => true );

		$invalidator = new SouinInvalidator( $redis );
		$result      = $invalidator->invalidate_url( 'https://example.com/' );

		restore_error_handler();
		if ( null !== $prev ) {
			set_error_handler( $prev );
		}

		// Failure must not propagate to WordPress.
		$this->assertSame( 0, $result );
	}

	public function test_constructor_without_redis_is_a_no_op(): void {
		// No Redis injected, ext-redis may or may not be loaded.
		// Either way, invalidate_* must return 0 without raising.
		$invalidator = new SouinInvalidator();
		$this->assertSame( 0, $invalidator->invalidate_url( 'https://example.com/post/1' ) );
		$this->assertSame( 0, $invalidator->invalidate_tag( 'post-1' ) );
	}
}
