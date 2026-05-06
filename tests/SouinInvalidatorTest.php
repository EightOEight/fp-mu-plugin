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

	public function test_on_save_post_for_global_post_type_invalidates_all(): void {
		Functions\stubs(
			array(
				'get_post_type' => static fn () => 'wp_navigation',
			)
		);

		$redis = Mockery::mock( '\Redis' );
		// invalidate_all() SCANs the three pattern groups. Stub minimal SCAN
		// returning empty batches so the method exits cleanly.
		$redis->shouldReceive( 'scan' )->andReturn( array() );
		$redis->shouldNotReceive( 'del' );

		$invalidator = new SouinInvalidator( $redis );
		$invalidator->on_save_post( 42 );

		// Side-effect-driven test — we verify scan was called and del was not
		// because the SCAN found no keys to delete. The expectation that
		// invalidate_all() is the path taken (vs the per-URL path that would
		// call get_permalink) is the assertion.
		$this->assertTrue( true );
	}

	public function test_on_term_change_invalidates_all(): void {
		$redis = Mockery::mock( '\Redis' );
		$redis->shouldReceive( 'scan' )
			->atLeast()->once()
			->andReturn( array() );

		$invalidator = new SouinInvalidator( $redis );
		$invalidator->on_term_change();

		$this->assertTrue( true );
	}

	public function test_on_user_change_invalidates_author_archive_and_home(): void {
		Functions\stubs(
			array(
				'get_author_posts_url' => static fn ( int $id ) => "https://example.com/author/u{$id}/",
				'home_url'             => static fn ( string $p = '/' ) => "https://example.com{$p}",
			)
		);

		$redis = Mockery::mock( '\Redis' );
		$redis->shouldReceive( 'del' )
			->once()
			->with(
				array(
					'GET-http-example.com-/author/u7/',
					'IDX_GET-http-example.com-/author/u7/',
					'GET-https-example.com-/author/u7/',
					'IDX_GET-https-example.com-/author/u7/',
				)
			)
			->andReturn( 4 );
		$redis->shouldReceive( 'del' )
			->once()
			->with(
				array(
					'GET-http-example.com-/',
					'IDX_GET-http-example.com-/',
					'GET-https-example.com-/',
					'IDX_GET-https-example.com-/',
				)
			)
			->andReturn( 4 );

		$invalidator = new SouinInvalidator( $redis );
		$invalidator->on_user_change( 7 );

		$this->assertTrue( true );
	}

	public function test_on_updated_option_widget_prefix_invalidates_all(): void {
		$redis = Mockery::mock( '\Redis' );
		$redis->shouldReceive( 'scan' )
			->atLeast()->once()
			->andReturn( array() );

		$invalidator = new SouinInvalidator( $redis );
		$invalidator->on_updated_option( 'widget_search' );

		$this->assertTrue( true );
	}

	public function test_on_updated_option_unknown_option_no_op(): void {
		$redis = Mockery::mock( '\Redis' );
		$redis->shouldNotReceive( 'scan' );
		$redis->shouldNotReceive( 'del' );

		$invalidator = new SouinInvalidator( $redis );
		$invalidator->on_updated_option( 'some_random_option' );

		$this->assertTrue( true );
	}
}
