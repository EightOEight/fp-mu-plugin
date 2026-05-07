<?php
/**
 * SMTP mailer.
 *
 * Wires the global PHPMailer instance (the engine WordPress uses for
 * `wp_mail()`) to send via an SMTP server, configured from FrankenPress
 * environment variables. Without this — and without a host-side MTA, which
 * the fp-runtime image deliberately doesn't ship — `wp_mail()` falls
 * through to PHP's `mail()`, which calls `/usr/sbin/sendmail` and fails
 * silently in the container. That's the silent-fail mode every
 * FrankenPress site lands in by default; this component is the opt-in
 * fix.
 *
 * Transport-agnostic: any SMTP provider (Postmark, SendGrid, Mailgun,
 * AWS SES, in-cluster relay) works through the same env-var contract.
 *
 * Configuration (all env vars are optional unless flagged required):
 *
 *   FP_SMTP_HOST          (required to opt in)  SMTP hostname (e.g. smtp.postmarkapp.com)
 *   FP_SMTP_PORT          (default 587)         TCP port
 *   FP_SMTP_ENCRYPTION    (default tls)         tls (STARTTLS), ssl (implicit TLS), none
 *   FP_SMTP_USERNAME      (required when host)  SMTP auth username
 *   FP_SMTP_PASSWORD      (required when host)  SMTP auth password
 *   FP_SMTP_FROM_EMAIL    (optional)            wp_mail_from filter target (default: WP admin_email)
 *   FP_SMTP_FROM_NAME     (optional)            wp_mail_from_name filter target (default: WP blogname)
 *   FP_SMTP_DISABLED      (optional)            truthy → bootstrap is a no-op
 *
 * Failure mode: if `FP_SMTP_HOST` is unset, the component is a no-op and
 * `wp_mail()` keeps its default behaviour (i.e. broken-on-this-platform
 * but not made worse). If the SMTP server is set but unreachable / auth
 * fails, `wp_mail()` returns `false` and the failure is logged via
 * `error_log` — we never retry, queue, or fall back to `mail()`. The
 * "is my email actually working" check belongs in Site Health (see
 * SiteHealth::test_smtp_reachability).
 *
 * Plugin-coexistence note: if a site composer-installs a competing
 * SMTP plugin (WP Mail SMTP, FluentSMTP, the Postmark official plugin),
 * those run as regular plugins after must-use plugins and re-hook
 * `phpmailer_init` at the same priority — last-writer-wins, so our
 * config gets overridden by the user's explicit choice. That's the
 * correct behaviour: the user opted into the plugin, we don't fight
 * them.
 *
 * @package FrankenPress
 */

declare(strict_types=1);

namespace FrankenPress;

use Throwable;

final class SMTPMailer {

	/**
	 * Whether bootstrap() registered the phpmailer_init / wp_mail_from
	 * filters. False if the component is disabled, FP_SMTP_HOST is
	 * unset, or the bootstrap raised.
	 */
	private bool $hooks_registered = false;

	public function bootstrap(): void {
		if ( $this->is_disabled() ) {
			return;
		}

		$host = (string) getenv( 'FP_SMTP_HOST' );
		if ( '' === $host ) {
			// Not configured. No-op: keep WP's default mail() handler so
			// non-chart consumers (docker-compose dev, etc.) aren't
			// surprised by a sudden behaviour change.
			return;
		}

		add_action( 'phpmailer_init', array( $this, 'configure' ), 10, 1 );

		$from_email = (string) getenv( 'FP_SMTP_FROM_EMAIL' );
		if ( '' !== $from_email ) {
			add_filter(
				'wp_mail_from',
				static function () use ( $from_email ): string {
					return $from_email;
				},
				10
			);
		}

		$from_name = (string) getenv( 'FP_SMTP_FROM_NAME' );
		if ( '' !== $from_name ) {
			add_filter(
				'wp_mail_from_name',
				static function () use ( $from_name ): string {
					return $from_name;
				},
				10
			);
		}

		$this->hooks_registered = true;
	}

	public function hooks_registered(): bool {
		return $this->hooks_registered;
	}

	/**
	 * `phpmailer_init` callback: configure the global PHPMailer instance
	 * for SMTP. Called every time `wp_mail()` runs.
	 *
	 * Typed as `mixed` because WordPress passes the PHPMailer object by
	 * reference and BrainMonkey's stub passes whatever the test asks
	 * (we accept any object and probe duck-typed properties).
	 *
	 * @param mixed $phpmailer PHPMailer instance.
	 */
	public function configure( $phpmailer ): void {
		if ( ! is_object( $phpmailer ) ) {
			return;
		}

		$host = (string) getenv( 'FP_SMTP_HOST' );
		if ( '' === $host ) {
			return;
		}

		$port_env = getenv( 'FP_SMTP_PORT' );
		$port     = ( false === $port_env || '' === $port_env ) ? 587 : (int) $port_env;

		$encryption = strtolower( (string) getenv( 'FP_SMTP_ENCRYPTION' ) );
		if ( '' === $encryption ) {
			$encryption = 'tls';
		}
		if ( ! in_array( $encryption, array( 'tls', 'ssl', 'none' ), true ) ) {
			$this->log_error( "unknown FP_SMTP_ENCRYPTION '{$encryption}'; falling back to tls" );
			$encryption = 'tls';
		}

		$username = (string) getenv( 'FP_SMTP_USERNAME' );
		$password = (string) getenv( 'FP_SMTP_PASSWORD' );

		try {
			if ( method_exists( $phpmailer, 'isSMTP' ) ) {
				$phpmailer->isSMTP();
			}
			// PHPMailer's public property names are PascalCase (Host, Port,
			// SMTPSecure, SMTPAuth, Username, Password). They mirror the
			// upstream API and can't be renamed.
			// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$phpmailer->Host       = $host;
			$phpmailer->Port       = $port;
			$phpmailer->SMTPSecure = ( 'none' === $encryption ) ? '' : $encryption;
			$phpmailer->SMTPAuth   = ( '' !== $username );
			if ( '' !== $username ) {
				$phpmailer->Username = $username;
				$phpmailer->Password = $password;
			}
			// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		} catch ( Throwable $e ) {
			$this->log_error( 'PHPMailer configuration failed', $e );
		}
	}

	/**
	 * Was the mailer explicitly disabled by env / constant?
	 */
	private function is_disabled(): bool {
		if ( defined( 'FP_SMTP_DISABLED' ) && (bool) constant( 'FP_SMTP_DISABLED' ) ) {
			return true;
		}
		$env = getenv( 'FP_SMTP_DISABLED' );
		if ( false === $env || '' === $env ) {
			return false;
		}
		return ! in_array( strtolower( (string) $env ), array( '0', 'false', 'no', 'off' ), true );
	}

	private function log_error( string $message, ?Throwable $e = null ): void {
		$detail = null !== $e ? ' — ' . $e->getMessage() : '';
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional log target.
		error_log( '[FrankenPress\\SMTPMailer] ' . $message . $detail );
	}
}
