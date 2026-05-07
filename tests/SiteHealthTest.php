<?php
/**
 * Unit tests for SiteHealth.
 *
 * @package FrankenPress\Tests
 */

declare(strict_types=1);

namespace FrankenPress\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FrankenPress\SiteHealth;
use PHPUnit\Framework\TestCase;

final class SiteHealthTest extends TestCase {

	private const SMTP_ENV_KEYS = array( 'FP_SMTP_HOST', 'FP_SMTP_PORT' );

	/** @var array<string, string> */
	private array $env_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubs(
			array(
				'__'         => static fn ( string $s ) => $s,
				'esc_html__' => static fn ( string $s ) => $s,
				'esc_html'   => static fn ( string $s ) => $s,
			)
		);
		foreach ( self::SMTP_ENV_KEYS as $key ) {
			$value = getenv( $key );
			if ( false !== $value ) {
				$this->env_backup[ $key ] = $value;
			}
			putenv( $key );
		}
	}

	protected function tearDown(): void {
		foreach ( self::SMTP_ENV_KEYS as $key ) {
			if ( isset( $this->env_backup[ $key ] ) ) {
				putenv( $key . '=' . $this->env_backup[ $key ] );
			} else {
				putenv( $key );
			}
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Realistic shape of the array WordPress passes through `site_status_tests`.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private function default_tests(): array {
		return array(
			'direct' => array(
				'wordpress_version'            => array( 'label' => 'WP version' ),
				'php_version'                  => array( 'label' => 'PHP version' ),
				'sql_server'                   => array( 'label' => 'SQL server' ),
				'update_temp_backup_writable'  => array( 'label' => 'Backup writable' ),
				'available_updates_disk_space' => array( 'label' => 'Disk space' ),
				'plugin_theme_auto_updates'    => array( 'label' => 'Auto-updates' ),
			),
			'async'  => array(
				'background_updates'   => array( 'label' => 'Background updates' ),
				'dotorg_communication' => array( 'label' => '.org communication' ),
				'loopback_requests'    => array( 'label' => 'Loopback requests' ),
				'https_status'         => array( 'label' => 'HTTPS status' ),
			),
		);
	}

	public function test_filter_removes_lockdown_related_tests(): void {
		$site_health = new SiteHealth();
		$result      = $site_health->tweak_tests( $this->default_tests() );

		$this->assertArrayNotHasKey( 'background_updates', $result['async'] );
		$this->assertArrayNotHasKey( 'update_temp_backup_writable', $result['direct'] );
		$this->assertArrayNotHasKey( 'available_updates_disk_space', $result['direct'] );
		$this->assertArrayNotHasKey( 'plugin_theme_auto_updates', $result['direct'] );
	}

	public function test_filter_preserves_unrelated_tests(): void {
		$site_health = new SiteHealth();
		$result      = $site_health->tweak_tests( $this->default_tests() );

		$this->assertArrayHasKey( 'wordpress_version', $result['direct'] );
		$this->assertArrayHasKey( 'php_version', $result['direct'] );
		$this->assertArrayHasKey( 'sql_server', $result['direct'] );
		$this->assertArrayHasKey( 'dotorg_communication', $result['async'] );
		$this->assertArrayHasKey( 'loopback_requests', $result['async'] );
		$this->assertArrayHasKey( 'https_status', $result['async'] );
	}

	public function test_filter_adds_frankenpress_lockdown_test(): void {
		$site_health = new SiteHealth();
		$result      = $site_health->tweak_tests( $this->default_tests() );

		$this->assertArrayHasKey( 'frankenpress_lockdown', $result['direct'] );
		$this->assertSame(
			'FrankenPress immutable-image lockdown',
			$result['direct']['frankenpress_lockdown']['label']
		);
		$this->assertIsCallable( $result['direct']['frankenpress_lockdown']['test'] );
	}

	public function test_filter_is_idempotent_on_already_tweaked_input(): void {
		// If for any reason the filter runs twice (e.g. another plugin
		// re-applies it), removing tests that are already gone must
		// not raise, and the FrankenPress test must still be present
		// exactly once.
		$site_health = new SiteHealth();
		$once        = $site_health->tweak_tests( $this->default_tests() );
		$twice       = $site_health->tweak_tests( $once );

		$this->assertArrayHasKey( 'frankenpress_lockdown', $twice['direct'] );
		$this->assertArrayNotHasKey( 'background_updates', $twice['async'] );
	}

	public function test_lockdown_callback_returns_passing_result(): void {
		$site_health = new SiteHealth();
		$result      = $site_health->test_lockdown();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( 'frankenpress_lockdown', $result['test'] );
		$this->assertNotEmpty( $result['label'] );
		$this->assertNotEmpty( $result['description'] );
		$this->assertSame( 'green', $result['badge']['color'] );
	}

	public function test_lockdown_callback_description_contains_explanation(): void {
		$site_health = new SiteHealth();
		$result      = $site_health->test_lockdown();

		// Description should explain WHY the lockdown is in place,
		// not just say "lockdown is on" — that's the whole reason
		// we replace the suppressed tests with this one.
		$this->assertStringContainsString( 'immutable', $result['description'] );
		$this->assertStringContainsString( 'read-only', $result['description'] );
	}

	public function test_smtp_test_omitted_when_host_unset(): void {
		// FP_SMTP_HOST cleared in setUp.
		$site_health = new SiteHealth();
		$result      = $site_health->tweak_tests( $this->default_tests() );

		$this->assertArrayNotHasKey( 'frankenpress_smtp_reachability', $result['direct'] );
	}

	public function test_smtp_test_added_when_host_set(): void {
		putenv( 'FP_SMTP_HOST=smtp.example.com' );

		$site_health = new SiteHealth();
		$result      = $site_health->tweak_tests( $this->default_tests() );

		$this->assertArrayHasKey( 'frankenpress_smtp_reachability', $result['direct'] );
		$this->assertSame(
			'FrankenPress SMTP server reachable',
			$result['direct']['frankenpress_smtp_reachability']['label']
		);
		$this->assertIsCallable( $result['direct']['frankenpress_smtp_reachability']['test'] );
	}

	public function test_smtp_reachability_returns_critical_for_unreachable_host(): void {
		// Port 1 on loopback isn't bound; connect refuses fast (~ms).
		// Using loopback specifically because a real-world unreachable
		// address would 2-second-timeout every test run.
		putenv( 'FP_SMTP_HOST=127.0.0.1' );
		putenv( 'FP_SMTP_PORT=1' );

		$result = ( new SiteHealth() )->test_smtp_reachability();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertSame( 'frankenpress_smtp_reachability', $result['test'] );
		$this->assertSame( 'red', $result['badge']['color'] );
		$this->assertStringContainsString( '127.0.0.1', $result['label'] );
	}

	public function test_smtp_reachability_returns_good_when_host_unset_after_registration(): void {
		// Defensive: the test guard inside test_smtp_reachability for
		// the case where the env was cleared between page-load and
		// test invocation (shouldn't happen in production, but the
		// callback should not crash).
		// FP_SMTP_HOST cleared in setUp.

		$result = ( new SiteHealth() )->test_smtp_reachability();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( 'frankenpress_smtp_reachability', $result['test'] );
	}
}
