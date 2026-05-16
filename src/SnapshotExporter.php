<?php
/**
 * SnapshotExporter — captures prod site state and uploads to an S3
 * snapshot bucket so designers can `fp pull` real-volume content
 * for local theme work.
 *
 * Two entry points, one code path:
 *
 *   - **Daily wp-cron event** (`frankenpress_snapshot_export`) fires
 *     at the next site-local midnight (`wp_timezone()`) and re-arms
 *     daily. Picked up by the K8s wpcron CronJob's `wp cron event
 *     run --due-now` invocation, so the capture runs inside a WP-CLI
 *     process where `\WP_CLI::runcommand` is available.
 *   - **Admin button** under Tools → Snapshot Export schedules a
 *     one-shot event on the same hook (`wp_schedule_single_event(
 *     time(), ... )`), so the button-initiated capture also runs in
 *     the wpcron CronJob's WP-CLI process within ~60s. The button
 *     itself just enqueues; it never runs the capture inline (which
 *     would mean an admin browser hanging for the duration of an
 *     export, plus no WP-CLI context for the inner `wp export`).
 *
 * Dormant by design: if `FP_SNAPSHOT_BUCKET` is unset, bootstrap is a
 * no-op (no hooks registered, no cron event scheduled). Sites that
 * don't opt in pay zero overhead.
 *
 * @package FrankenPress
 */

declare(strict_types=1);

namespace FrankenPress;

use Aws\S3\S3Client;
use DateTimeImmutable;
use DateTimeZone;
use FrankenPress\Cli\Snapshot\Factory;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

final class SnapshotExporter {

	private const ENV_BUCKET = 'FP_SNAPSHOT_BUCKET';
	private const ENV_KEY    = 'FP_SNAPSHOT_KEY';
	private const ENV_SECRET = 'FP_SNAPSHOT_SECRET';
	private const ENV_REGION = 'FP_SNAPSHOT_REGION';

	private const CRON_HOOK     = 'frankenpress_snapshot_export';
	private const CRON_SCHEDULE = 'daily';
	private const MENU_SLUG     = 'frankenpress-snapshot-export';
	private const FORM_ACTION   = 'frankenpress_snapshot_export_now';

	private bool $hooks_registered = false;

	public function bootstrap(): void {
		if ( '' === (string) getenv( self::ENV_BUCKET ) ) {
			return;
		}

		// Re-arm the daily event if not already scheduled. WP keeps the
		// schedule alive across requests; this just ensures it survives
		// the first request after enablement and after `wp cron event
		// delete` style maintenance.
		if ( false === wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event(
				$this->next_local_midnight_utc(),
				self::CRON_SCHEDULE,
				self::CRON_HOOK
			);
		}

		add_action( self::CRON_HOOK, array( $this, 'export_now' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_post_' . self::FORM_ACTION, array( $this, 'handle_button_submit' ) );

		$this->hooks_registered = true;
	}

	/**
	 * Whether bootstrap registered hooks — used by tests to verify the
	 * dormant/enabled gating.
	 */
	public function hooks_registered(): bool {
		return $this->hooks_registered;
	}

	/**
	 * Cron + button entry point. Captures + uploads + cleans up.
	 * Errors are logged, never raised, so a single failure can't break
	 * other due wp-cron events.
	 */
	public function export_now(): void {
		$bucket = (string) getenv( self::ENV_BUCKET );
		if ( '' === $bucket ) {
			return;
		}

		$slug       = $this->slug_for_now();
		$output_dir = $this->temp_output_dir( $slug );

		try {
			$capturer = ( new Factory() )->make( $output_dir, $slug, 'fp-snapshot-export' );
			$capturer->capture();
			$this->upload_dir( $output_dir, $slug, $bucket );
			error_log( sprintf( '[fp-snapshot-export] uploaded %s to s3://%s/%s/', $slug, $bucket, $slug ) );
		} catch ( Throwable $e ) {
			error_log( '[fp-snapshot-export] capture/upload failed: ' . $e->getMessage() );
		} finally {
			$this->cleanup_dir( $output_dir );
		}
	}

	public function register_admin_page(): void {
		add_management_page(
			'FrankenPress Snapshot Export',
			'Snapshot Export',
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_admin_page' )
		);
	}

	public function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		$bucket         = (string) getenv( self::ENV_BUCKET );
		$next_scheduled = wp_next_scheduled( self::CRON_HOOK );
		$queued         = isset( $_GET['fp_export_queued'] ) && '1' === (string) $_GET['fp_export_queued'];

		echo '<div class="wrap">';
		echo '<h1>FrankenPress Snapshot Export</h1>';
		echo '<p>Captures site state to <code>fp.snapshot/v5</code> wire format and uploads to the configured S3 bucket. Designers fetch the bundle via <code>fp pull</code> for local theme work against real-volume content.</p>';

		if ( $queued ) {
			echo '<div class="notice notice-success"><p>Snapshot queued. The next wp-cron tick (within ~60s) will pick it up; check the bucket and the WordPress debug log afterwards.</p></div>';
		}

		echo '<h2>Configuration</h2>';
		echo '<table class="form-table"><tbody>';
		printf( '<tr><th scope="row">Bucket</th><td><code>%s</code></td></tr>', esc_html( $bucket ) );
		echo '<tr><th scope="row">Daily schedule</th><td>';
		if ( $next_scheduled ) {
			printf(
				'%s (site-local midnight)',
				esc_html( wp_date( 'Y-m-d H:i T', (int) $next_scheduled ) )
			);
		} else {
			echo '<em>Not scheduled</em>';
		}
		echo '</td></tr>';
		echo '</tbody></table>';

		echo '<h2>Capture now</h2>';
		echo '<p>Schedule a one-shot capture for the next wp-cron tick. Use this after publishing a content batch you want available to designers immediately, rather than waiting for the nightly run.</p>';
		printf(
			'<form method="post" action="%s">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::FORM_ACTION ) );
		wp_nonce_field( self::FORM_ACTION );
		echo '<p class="submit"><button type="submit" class="button button-primary">Queue snapshot for next cron tick</button></p>';
		echo '</form>';
		echo '</div>';
	}

	public function handle_button_submit(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( self::FORM_ACTION );

		wp_schedule_single_event( time(), self::CRON_HOOK );

		$redirect = add_query_arg(
			array(
				'page'             => self::MENU_SLUG,
				'fp_export_queued' => '1',
			),
			admin_url( 'tools.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Next site-local midnight expressed as a UTC timestamp. `wp_timezone()`
	 * honours the site's Settings → General timezone choice, so a London
	 * site fires at 00:00 BST/GMT regardless of server clock.
	 */
	private function next_local_midnight_utc(): int {
		$tz       = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$now      = new DateTimeImmutable( 'now', $tz );
		$midnight = $now->setTime( 0, 0, 0 )->modify( '+1 day' );
		return $midnight->getTimestamp();
	}

	private function slug_for_now(): string {
		$now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		return 'prod-' . $now->format( 'Y-m-d\TH-i-s\Z' );
	}

	private function temp_output_dir( string $slug ): string {
		$base = sys_get_temp_dir() . '/fp-snapshot-export';
		if ( ! is_dir( $base ) && ! mkdir( $base, 0700, true ) && ! is_dir( $base ) ) {
			throw new \RuntimeException( "could not create temp base dir: {$base}" );
		}
		return $base . '/' . $slug;
	}

	/**
	 * Upload every file under $local_dir to s3://$bucket/$slug/<relative>.
	 * PutObject per file; aws-sdk-php internally streams from disk, so
	 * memory pressure is bounded by chunk size, not file size.
	 */
	private function upload_dir( string $local_dir, string $slug, string $bucket ): void {
		if ( ! is_dir( $local_dir ) ) {
			throw new \RuntimeException( "capture produced no output directory: {$local_dir}" );
		}

		$client   = $this->make_s3_client();
		$base_len = strlen( rtrim( $local_dir, '/' ) ) + 1;
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $local_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $entry ) {
			if ( ! $entry->isFile() ) {
				continue;
			}
			$local_path = (string) $entry->getRealPath();
			$relative   = substr( $local_path, $base_len );
			$key        = $slug . '/' . str_replace( '\\', '/', $relative );

			$client->putObject(
				array(
					'Bucket'     => $bucket,
					'Key'        => $key,
					'SourceFile' => $local_path,
				)
			);
		}
	}

	private function make_s3_client(): S3Client {
		$region = (string) getenv( self::ENV_REGION );
		if ( '' === $region ) {
			$region = 'eu-west-2';
		}
		return new S3Client(
			array(
				'version'     => 'latest',
				'region'      => $region,
				'credentials' => array(
					'key'    => (string) getenv( self::ENV_KEY ),
					'secret' => (string) getenv( self::ENV_SECRET ),
				),
			)
		);
	}

	private function cleanup_dir( string $dir ): void {
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $entry ) {
			$path = (string) $entry->getRealPath();
			if ( $entry->isDir() ) {
				@rmdir( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}
}
