<?php
/**
 * Unit tests for SMTPMailer.
 *
 * The PHPMailer-mirroring property names (`Host`, `Port`, `SMTPSecure`,
 * `SMTPAuth`, `Username`, `Password`) are upstream-defined PascalCase and
 * can't be renamed — disable the WPCS snake_case rule for this file.
 *
 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
 * phpcs:disable WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase
 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
 *
 * @package FrankenPress\Tests
 */

declare(strict_types=1);

namespace FrankenPress\Tests;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use FrankenPress\SMTPMailer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use stdClass;

final class SMTPMailerTest extends TestCase {

	private const SMTP_ENV_KEYS = array(
		'FP_SMTP_HOST',
		'FP_SMTP_PORT',
		'FP_SMTP_ENCRYPTION',
		'FP_SMTP_USERNAME',
		'FP_SMTP_PASSWORD',
		'FP_SMTP_FROM_EMAIL',
		'FP_SMTP_FROM_NAME',
		'FP_SMTP_DISABLED',
	);

	/** @var array<string, string> */
	private array $env_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
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
	 * Anonymous-class spy that mirrors the bits of PHPMailer's API
	 * SMTPMailer::configure() touches.
	 */
	private function fake_phpmailer(): object {
		return new class() extends stdClass {
			public bool $is_smtp_called = false;
			public string $Host         = '';
			public int $Port            = 0;
			public string $SMTPSecure   = '';
			public bool $SMTPAuth       = false;
			public string $Username     = '';
			public string $Password     = '';

			public function isSMTP(): void {
				$this->is_smtp_called = true;
			}
		};
	}

	public function test_no_op_when_host_unset(): void {
		Actions\expectAdded( 'phpmailer_init' )->never();
		Filters\expectAdded( 'wp_mail_from' )->never();
		Filters\expectAdded( 'wp_mail_from_name' )->never();

		$mailer = new SMTPMailer();
		$mailer->bootstrap();

		$this->assertFalse( $mailer->hooks_registered() );
	}

	public function test_disabled_env_short_circuits_even_with_host_set(): void {
		putenv( 'FP_SMTP_HOST=smtp.example.com' );
		putenv( 'FP_SMTP_DISABLED=1' );

		Actions\expectAdded( 'phpmailer_init' )->never();
		Filters\expectAdded( 'wp_mail_from' )->never();
		Filters\expectAdded( 'wp_mail_from_name' )->never();

		$mailer = new SMTPMailer();
		$mailer->bootstrap();

		$this->assertFalse( $mailer->hooks_registered() );
	}

	public function test_host_set_registers_phpmailer_init(): void {
		putenv( 'FP_SMTP_HOST=smtp.example.com' );

		Actions\expectAdded( 'phpmailer_init' )->once();
		Filters\expectAdded( 'wp_mail_from' )->never();
		Filters\expectAdded( 'wp_mail_from_name' )->never();

		$mailer = new SMTPMailer();
		$mailer->bootstrap();

		$this->assertTrue( $mailer->hooks_registered() );
	}

	public function test_from_email_registers_wp_mail_from_filter(): void {
		putenv( 'FP_SMTP_HOST=smtp.example.com' );
		putenv( 'FP_SMTP_FROM_EMAIL=hello@example.com' );

		Actions\expectAdded( 'phpmailer_init' )->once();
		Filters\expectAdded( 'wp_mail_from' )->once();
		Filters\expectAdded( 'wp_mail_from_name' )->never();

		$mailer = new SMTPMailer();
		$mailer->bootstrap();

		$this->assertTrue( $mailer->hooks_registered() );
	}

	public function test_from_name_registers_wp_mail_from_name_filter(): void {
		putenv( 'FP_SMTP_HOST=smtp.example.com' );
		putenv( 'FP_SMTP_FROM_NAME=My Site' );

		Actions\expectAdded( 'phpmailer_init' )->once();
		Filters\expectAdded( 'wp_mail_from' )->never();
		Filters\expectAdded( 'wp_mail_from_name' )->once();

		$mailer = new SMTPMailer();
		$mailer->bootstrap();

		$this->assertTrue( $mailer->hooks_registered() );
	}

	public function test_configure_applies_smtp_settings_to_phpmailer(): void {
		putenv( 'FP_SMTP_HOST=smtp.postmarkapp.com' );
		putenv( 'FP_SMTP_PORT=2525' );
		putenv( 'FP_SMTP_ENCRYPTION=tls' );
		putenv( 'FP_SMTP_USERNAME=user-token' );
		putenv( 'FP_SMTP_PASSWORD=pass-token' );

		$phpmailer = $this->fake_phpmailer();
		( new SMTPMailer() )->configure( $phpmailer );

		$this->assertTrue( $phpmailer->is_smtp_called );
		$this->assertSame( 'smtp.postmarkapp.com', $phpmailer->Host );
		$this->assertSame( 2525, $phpmailer->Port );
		$this->assertSame( 'tls', $phpmailer->SMTPSecure );
		$this->assertTrue( $phpmailer->SMTPAuth );
		$this->assertSame( 'user-token', $phpmailer->Username );
		$this->assertSame( 'pass-token', $phpmailer->Password );
	}

	public function test_configure_default_port_is_587_default_encryption_is_tls(): void {
		putenv( 'FP_SMTP_HOST=smtp.example.com' );

		$phpmailer = $this->fake_phpmailer();
		( new SMTPMailer() )->configure( $phpmailer );

		$this->assertSame( 587, $phpmailer->Port );
		$this->assertSame( 'tls', $phpmailer->SMTPSecure );
		$this->assertFalse( $phpmailer->SMTPAuth, 'SMTPAuth should be false when no FP_SMTP_USERNAME is set' );
	}

	public function test_configure_encryption_none_clears_smtpsecure(): void {
		putenv( 'FP_SMTP_HOST=smtp.example.com' );
		putenv( 'FP_SMTP_ENCRYPTION=none' );

		$phpmailer = $this->fake_phpmailer();
		( new SMTPMailer() )->configure( $phpmailer );

		$this->assertSame( '', $phpmailer->SMTPSecure );
	}

	public function test_configure_falls_back_to_tls_for_unknown_encryption(): void {
		putenv( 'FP_SMTP_HOST=smtp.example.com' );
		putenv( 'FP_SMTP_ENCRYPTION=quantum' );

		$phpmailer = $this->fake_phpmailer();
		( new SMTPMailer() )->configure( $phpmailer );

		$this->assertSame( 'tls', $phpmailer->SMTPSecure );
	}

	public function test_configure_short_circuits_when_host_unset(): void {
		// No FP_SMTP_HOST. The `phpmailer_init` filter shouldn't have
		// been registered in the first place, but configure() is also
		// publicly callable — make sure it bails cleanly instead of
		// blowing up the global PHPMailer.
		$phpmailer       = $this->fake_phpmailer();
		$phpmailer->Host = 'untouched';

		( new SMTPMailer() )->configure( $phpmailer );

		$this->assertSame( 'untouched', $phpmailer->Host );
		$this->assertFalse( $phpmailer->is_smtp_called );
	}

	public function test_disabled_truthy_and_falsy_values(): void {
		$mailer = new SMTPMailer();
		$method = new ReflectionMethod( $mailer, 'is_disabled' );

		foreach ( array( '1', 'true', 'TRUE', 'yes', 'on' ) as $truthy ) {
			putenv( 'FP_SMTP_DISABLED=' . $truthy );
			$this->assertTrue( $method->invoke( $mailer ), "Expected '$truthy' to be disabled" );
		}

		foreach ( array( '', '0', 'false', 'no', 'off' ) as $falsy ) {
			putenv( 'FP_SMTP_DISABLED=' . $falsy );
			$this->assertFalse( $method->invoke( $mailer ), "Expected '$falsy' to be enabled" );
		}
	}
}
