<?php
/**
 * Unit tests for S3UploadsBootstrap.
 *
 * @package FrankenPress\Tests
 */

declare(strict_types=1);

namespace FrankenPress\Tests;

use Brain\Monkey;
use Brain\Monkey\Filters;
use FrankenPress\S3UploadsBootstrap;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class S3UploadsBootstrapTest extends TestCase {

	/** @var array<string, string> */
	private array $env_backup = array();

	private const TRACKED_ENV = array(
		'FP_S3_BUCKET',
		'FP_S3_KEY',
		'FP_S3_SECRET',
		'FP_S3_REGION',
		'FP_S3_BUCKET_URL',
		'FP_S3_ENDPOINT',
		'FP_S3_OBJECT_ACL',
		'FP_S3_DISABLED',
		'KUBERNETES_SERVICE_HOST',
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Snapshot then clear tracked env vars for test isolation. Tests
		// that need an in-cluster default re-set KUBERNETES_SERVICE_HOST.
		foreach ( self::TRACKED_ENV as $key ) {
			$value = getenv( $key );
			if ( false !== $value ) {
				$this->env_backup[ $key ] = $value;
			}
			putenv( $key );
		}
	}

	protected function tearDown(): void {
		foreach ( self::TRACKED_ENV as $key ) {
			if ( isset( $this->env_backup[ $key ] ) ) {
				putenv( $key . '=' . $this->env_backup[ $key ] );
			} else {
				putenv( $key );
			}
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_disabled_env_short_circuits(): void {
		putenv( 'FP_S3_DISABLED=1' );
		Filters\expectAdded( 'wp_handle_upload_prefilter' )->never();

		$bootstrap = new S3UploadsBootstrap();
		$bootstrap->bootstrap();

		$this->assertFalse( $bootstrap->upload_refused() );
		$this->assertFalse( $bootstrap->s3_uploads_loaded() );
	}

	public function test_missing_required_env_refuses_uploads(): void {
		// Simulate in-cluster so the K8s-aware default leaves the
		// bootstrap enabled; without FP_S3_* env vars it should refuse
		// uploads rather than silently fall back to local disk.
		putenv( 'KUBERNETES_SERVICE_HOST=10.0.0.1' );

		Filters\expectAdded( 'wp_handle_upload_prefilter' )->once();
		Filters\expectAdded( 'wp_handle_sideload_prefilter' )->once();

		$bootstrap = new S3UploadsBootstrap();

		$prev = set_error_handler( static fn () => true );
		$bootstrap->bootstrap();
		restore_error_handler();
		if ( null !== $prev ) {
			set_error_handler( $prev );
		}

		$this->assertTrue( $bootstrap->upload_refused() );
		$this->assertFalse( $bootstrap->s3_uploads_loaded() );
	}

	public function test_explicit_truthy_and_falsy_values(): void {
		$bootstrap = new S3UploadsBootstrap();
		$method    = new ReflectionMethod( $bootstrap, 'is_disabled' );

		foreach ( array( '1', 'true', 'TRUE', 'yes', 'on' ) as $truthy ) {
			putenv( 'FP_S3_DISABLED=' . $truthy );
			$this->assertTrue( $method->invoke( $bootstrap ), "Expected '$truthy' to be disabled" );
		}

		// Explicit falsy values force-enable even out-of-cluster (no
		// KUBERNETES_SERVICE_HOST set in this test).
		foreach ( array( '0', 'false', 'no', 'off' ) as $falsy ) {
			putenv( 'FP_S3_DISABLED=' . $falsy );
			$this->assertFalse( $method->invoke( $bootstrap ), "Expected '$falsy' to force-enable" );
		}
	}

	public function test_unset_falls_back_to_kubernetes_gate(): void {
		$bootstrap = new S3UploadsBootstrap();
		$method    = new ReflectionMethod( $bootstrap, 'is_disabled' );

		// FP_S3_DISABLED unset (cleared in setUp).
		putenv( 'KUBERNETES_SERVICE_HOST=10.0.0.1' );
		$this->assertFalse( $method->invoke( $bootstrap ), 'In-cluster default should enable the bootstrap' );

		putenv( 'KUBERNETES_SERVICE_HOST' );
		$this->assertTrue( $method->invoke( $bootstrap ), 'Out-of-cluster default should disable the bootstrap' );
	}

	public function test_empty_string_treated_as_unset(): void {
		$bootstrap = new S3UploadsBootstrap();
		$method    = new ReflectionMethod( $bootstrap, 'is_disabled' );

		putenv( 'FP_S3_DISABLED=' );
		putenv( 'KUBERNETES_SERVICE_HOST=10.0.0.1' );
		$this->assertFalse( $method->invoke( $bootstrap ), 'Empty FP_S3_DISABLED in-cluster → enabled' );

		putenv( 'KUBERNETES_SERVICE_HOST' );
		$this->assertTrue( $method->invoke( $bootstrap ), 'Empty FP_S3_DISABLED out-of-cluster → disabled' );
	}

	public function test_env_vars_map_to_constants(): void {
		// Skip when constants are already defined from a prior test run
		// in the same process — running this in isolation only.
		if ( defined( 'S3_UPLOADS_BUCKET' ) ) {
			$this->markTestSkipped( 'S3_UPLOADS_BUCKET already defined; run this test in isolation' );
		}

		putenv( 'FP_S3_BUCKET=my-bucket' );
		putenv( 'FP_S3_KEY=AKIAFAKE' );
		putenv( 'FP_S3_SECRET=secret' );
		putenv( 'FP_S3_REGION=eu-west-1' );

		$bootstrap = new S3UploadsBootstrap();
		$method    = new ReflectionMethod( $bootstrap, 'define_constants_from_env' );
		$method->invoke( $bootstrap );

		$this->assertSame( 'my-bucket', constant( 'S3_UPLOADS_BUCKET' ) );
		$this->assertSame( 'AKIAFAKE', constant( 'S3_UPLOADS_KEY' ) );
		$this->assertSame( 'secret', constant( 'S3_UPLOADS_SECRET' ) );
		$this->assertSame( 'eu-west-1', constant( 'S3_UPLOADS_REGION' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_object_acl_env_maps_to_constant(): void {
		putenv( 'FP_S3_OBJECT_ACL=public-read' );

		$bootstrap = new S3UploadsBootstrap();
		$method    = new ReflectionMethod( $bootstrap, 'define_constants_from_env' );
		$method->invoke( $bootstrap );

		$this->assertSame( 'public-read', constant( 'S3_UPLOADS_OBJECT_ACL' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_object_acl_defaults_to_null_when_env_unset(): void {
		// FP_S3_OBJECT_ACL is unset (cleared in setUp). The fresh
		// process means S3_UPLOADS_OBJECT_ACL is also undefined yet.
		putenv( 'FP_S3_OBJECT_ACL' );

		$bootstrap = new S3UploadsBootstrap();
		$method    = new ReflectionMethod( $bootstrap, 'apply_object_acl_default' );
		$method->invoke( $bootstrap );

		$this->assertTrue( defined( 'S3_UPLOADS_OBJECT_ACL' ) );
		$this->assertNull( constant( 'S3_UPLOADS_OBJECT_ACL' ) );
	}

	public function test_endpoint_filter_overrides_aws_sdk_params(): void {
		// Test the filter shape directly. We can't easily test that bootstrap
		// registers the filter (that requires a running WP), but we can
		// verify the closure produces the right params.
		if ( ! defined( 'S3_UPLOADS_ENDPOINT' ) ) {
			define( 'S3_UPLOADS_ENDPOINT', 'http://minio:9000' );
		}

		$ref       = new \ReflectionClass( S3UploadsBootstrap::class );
		$bootstrap = $ref->newInstanceWithoutConstructor();
		$method    = new ReflectionMethod( $bootstrap, 'register_endpoint_filter' );

		$captured = null;
		Filters\expectAdded( 's3_uploads_s3_client_params' )
			->once()
			->whenHappen(
				static function ( $callback ) use ( &$captured ): void {
					$captured = $callback;
				}
			);

		$method->invoke( $bootstrap );

		$this->assertIsCallable( $captured );
		$result = $captured( array( 'version' => 'latest' ) );

		$this->assertSame( 'http://minio:9000', $result['endpoint'] );
		$this->assertTrue( $result['use_path_style_endpoint'] );
		$this->assertSame( 'latest', $result['version'] );
	}
}
