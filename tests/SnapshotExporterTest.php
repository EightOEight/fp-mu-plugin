<?php
/**
 * Unit tests for SnapshotExporter.
 *
 * Tests cover the boot-time gating (dormant when FP_SNAPSHOT_BUCKET
 * unset, hooks registered when set), schedule helpers (next site-local
 * midnight, slug format), and idempotent re-bootstrap behaviour. The
 * `export_now()` end-to-end path (Factory→capture→S3 PutObject) is
 * exercised via the chart-side kind-cluster smoke test, not here —
 * mocking the Capturer + S3Client through their dependency chain would
 * obscure more than it verifies.
 *
 * @package FrankenPress\Tests
 */

declare(strict_types=1);

namespace FrankenPress\Tests;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use FrankenPress\SnapshotExporter;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SnapshotExporterTest extends TestCase {

	private const SNAPSHOT_ENV_KEYS = array(
		'FP_SNAPSHOT_BUCKET',
		'FP_SNAPSHOT_KEY',
		'FP_SNAPSHOT_SECRET',
		'FP_SNAPSHOT_REGION',
	);

	/** @var array<string, string> */
	private array $env_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		foreach ( self::SNAPSHOT_ENV_KEYS as $key ) {
			$value = getenv( $key );
			if ( false !== $value ) {
				$this->env_backup[ $key ] = $value;
			}
			putenv( $key );
		}
	}

	protected function tearDown(): void {
		foreach ( self::SNAPSHOT_ENV_KEYS as $key ) {
			if ( isset( $this->env_backup[ $key ] ) ) {
				putenv( $key . '=' . $this->env_backup[ $key ] );
			} else {
				putenv( $key );
			}
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_dormant_when_bucket_unset(): void {
		Actions\expectAdded( 'frankenpress_snapshot_export' )->never();
		Actions\expectAdded( 'admin_menu' )->never();
		Actions\expectAdded( 'admin_post_frankenpress_snapshot_export_now' )->never();

		$exporter = new SnapshotExporter();
		$exporter->bootstrap();

		$this->assertFalse( $exporter->hooks_registered() );
	}

	public function test_enabled_when_bucket_set_registers_hooks(): void {
		putenv( 'FP_SNAPSHOT_BUCKET=sts-production-snapshots-eu-west-2-533158516642' );

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->justReturn( true );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );

		Actions\expectAdded( 'frankenpress_snapshot_export' )->once();
		Actions\expectAdded( 'admin_menu' )->once();
		Actions\expectAdded( 'admin_post_frankenpress_snapshot_export_now' )->once();

		$exporter = new SnapshotExporter();
		$exporter->bootstrap();

		$this->assertTrue( $exporter->hooks_registered() );
	}

	public function test_schedules_cron_when_not_already_scheduled(): void {
		putenv( 'FP_SNAPSHOT_BUCKET=sts-production-snapshots-eu-west-2-533158516642' );

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\expect( 'wp_schedule_event' )
			->once()
			->with( \Mockery::type( 'int' ), 'daily', 'frankenpress_snapshot_export' );

		$exporter = new SnapshotExporter();
		$exporter->bootstrap();

		$this->assertTrue( $exporter->hooks_registered() );
	}

	public function test_does_not_redundantly_schedule_when_already_scheduled(): void {
		putenv( 'FP_SNAPSHOT_BUCKET=sts-production-snapshots-eu-west-2-533158516642' );

		// wp_next_scheduled returning a timestamp means the event is
		// already in the queue from a prior bootstrap; do NOT re-schedule.
		Functions\when( 'wp_next_scheduled' )->justReturn( strtotime( '+1 day' ) );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\expect( 'wp_schedule_event' )->never();

		$exporter = new SnapshotExporter();
		$exporter->bootstrap();

		$this->assertTrue( $exporter->hooks_registered() );
	}

	public function test_next_local_midnight_is_within_24h_future(): void {
		$exporter = new SnapshotExporter();
		$method   = new ReflectionMethod( $exporter, 'next_local_midnight_utc' );

		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'Europe/London' ) );

		$timestamp = (int) $method->invoke( $exporter );
		$now       = time();
		$delta     = $timestamp - $now;

		$this->assertGreaterThan( 0, $delta, 'next midnight must be in the future' );
		$this->assertLessThanOrEqual( 24 * 3600 + 60, $delta, 'next midnight must be within 24h' );
	}

	public function test_slug_format_is_prod_prefixed_iso_utc(): void {
		$exporter = new SnapshotExporter();
		$method   = new ReflectionMethod( $exporter, 'slug_for_now' );

		$slug = (string) $method->invoke( $exporter );

		$this->assertMatchesRegularExpression(
			'/^prod-\d{4}-\d{2}-\d{2}T\d{2}-\d{2}-\d{2}Z$/',
			$slug,
			'slug must follow prod-YYYY-MM-DDTHH-mm-ssZ format'
		);
	}
}
