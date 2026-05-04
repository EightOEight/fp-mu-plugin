<?php
/**
 * S3 uploads bootstrap.
 *
 * Wires up `humanmade/s3-uploads` from FrankenPress environment variables
 * and adds the safety guarantees the platform needs:
 *
 *  - **No silent local-disk fallback.** In a containerized environment
 *    local disk is ephemeral and cross-replica-inconsistent. If S3 is not
 *    fully configured we *refuse* uploads (return a hard error to the
 *    uploader) rather than letting WordPress write to /tmp where the file
 *    will disappear on the next pod restart.
 *  - **Single source of truth.** All S3 config flows through `FP_S3_*`
 *    env vars; we map those to humanmade/s3-uploads' `S3_UPLOADS_*`
 *    constants only if the constants haven't already been defined.
 *  - **Composer-first activation.** When `humanmade/s3-uploads` is
 *    composer-installed we auto-include its entry file so sites don't
 *    need to wp-cli plugin activate it on first boot.
 *
 * Configuration (all env vars are optional unless flagged required):
 *
 *   FP_S3_BUCKET       (required)  S3 bucket name
 *   FP_S3_KEY          (required)  IAM access key id
 *   FP_S3_SECRET       (required)  IAM secret access key
 *   FP_S3_REGION       (default us-east-1)
 *   FP_S3_BUCKET_URL   (optional)  Public CDN URL for served media
 *                                  (e.g. https://cdn.example.com)
 *   FP_S3_ENDPOINT     (optional)  Custom S3-compatible endpoint (MinIO,
 *                                  R2, GCS XML API, etc.). Leave empty
 *                                  for AWS S3.
 *   FP_S3_DISABLED     (optional)  Truthy to disable the bootstrap and
 *                                  fall back to whatever upload handler
 *                                  WP would use (i.e. local disk).
 *                                  ONLY for local dev — never in prod.
 *
 * @package FrankenPress
 */

declare(strict_types=1);

namespace FrankenPress;

final class S3UploadsBootstrap {

	private const CONST_MAP = array(
		'FP_S3_BUCKET'     => 'S3_UPLOADS_BUCKET',
		'FP_S3_KEY'        => 'S3_UPLOADS_KEY',
		'FP_S3_SECRET'     => 'S3_UPLOADS_SECRET',
		'FP_S3_REGION'     => 'S3_UPLOADS_REGION',
		'FP_S3_BUCKET_URL' => 'S3_UPLOADS_BUCKET_URL',
		'FP_S3_ENDPOINT'   => 'S3_UPLOADS_ENDPOINT',
	);

	private const REQUIRED_KEYS = array( 'S3_UPLOADS_BUCKET', 'S3_UPLOADS_KEY', 'S3_UPLOADS_SECRET' );

	/**
	 * Whether the s3-uploads plugin entry file was successfully loaded.
	 */
	private bool $s3_uploads_loaded = false;

	/**
	 * Whether the bootstrap is in the "refuse uploads" failure mode.
	 */
	private bool $upload_refused = false;

	public function bootstrap(): void {
		if ( $this->is_disabled() ) {
			return;
		}

		$this->define_constants_from_env();

		$missing = $this->find_missing_required_constants();
		if ( ! empty( $missing ) ) {
			$this->refuse_uploads(
				'S3 not fully configured. Missing: ' . implode( ', ', $missing )
				. '. Set the matching FP_S3_* env vars or set FP_S3_DISABLED=1 (local dev only).'
			);
			return;
		}

		// Sane default: if the consumer didn't set a region, AWS S3 defaults
		// to us-east-1 anyway. Better to make it explicit so signed URLs
		// don't fail.
		if ( ! defined( 'S3_UPLOADS_REGION' ) ) {
			define( 'S3_UPLOADS_REGION', 'us-east-1' );
		}

		// Coordinate WP_CONTENT_URL with the bucket public URL (or CDN) so
		// emitted asset URLs use the public path even before s3-uploads
		// rewrites them. Optional — site can override.
		if ( defined( 'S3_UPLOADS_BUCKET_URL' ) && ! defined( 'WP_CONTENT_URL' ) ) {
			define( 'WP_CONTENT_URL', rtrim( (string) constant( 'S3_UPLOADS_BUCKET_URL' ), '/' ) . '/wp-content' );
		}

		// humanmade/s3-uploads documents `S3_UPLOADS_ENDPOINT` but doesn't
		// actually apply it to the AWS SDK on its own — you have to register
		// the `s3_uploads_s3_client_params` filter. Without this, MinIO / R2
		// / GCS-XML / any non-AWS endpoint still hits s3.amazonaws.com.
		if ( defined( 'S3_UPLOADS_ENDPOINT' ) && '' !== (string) constant( 'S3_UPLOADS_ENDPOINT' ) ) {
			$this->register_endpoint_filter();
		}

		$this->s3_uploads_loaded = $this->load_s3_uploads();
		if ( ! $this->s3_uploads_loaded ) {
			$this->refuse_uploads(
				'humanmade/s3-uploads is not installed. Run `composer require humanmade/s3-uploads`.'
			);
		}
	}

	/**
	 * Register the s3-uploads filter that applies S3_UPLOADS_ENDPOINT to
	 * the AWS SDK client params. Path-style addressing is forced because
	 * MinIO and most S3-compatible services don't support the
	 * `<bucket>.<endpoint>` virtual-host pattern that AWS uses.
	 */
	private function register_endpoint_filter(): void {
		add_filter(
			's3_uploads_s3_client_params',
			static function ( array $params ): array {
				$params['endpoint']                = (string) constant( 'S3_UPLOADS_ENDPOINT' );
				$params['use_path_style_endpoint'] = true;
				return $params;
			}
		);
	}

	public function s3_uploads_loaded(): bool {
		return $this->s3_uploads_loaded;
	}

	public function upload_refused(): bool {
		return $this->upload_refused;
	}

	/**
	 * Was the bootstrap explicitly disabled?
	 */
	private function is_disabled(): bool {
		if ( defined( 'FP_S3_DISABLED' ) && (bool) constant( 'FP_S3_DISABLED' ) ) {
			return true;
		}
		$env = getenv( 'FP_S3_DISABLED' );
		if ( false === $env || '' === $env ) {
			return false;
		}
		return ! in_array( strtolower( (string) $env ), array( '0', 'false', 'no', 'off' ), true );
	}

	/**
	 * Define the S3_UPLOADS_* constants from FP_S3_* env vars (when the
	 * matching constant is not already defined).
	 */
	private function define_constants_from_env(): void {
		foreach ( self::CONST_MAP as $env_name => $constant_name ) {
			if ( defined( $constant_name ) ) {
				continue;
			}
			$value = getenv( $env_name );
			if ( false === $value || '' === $value ) {
				continue;
			}
			define( $constant_name, $value );
		}
	}

	/**
	 * @return array<int, string> List of required constants that aren't defined.
	 */
	private function find_missing_required_constants(): array {
		$missing = array();
		foreach ( self::REQUIRED_KEYS as $constant_name ) {
			if ( ! defined( $constant_name ) || '' === (string) constant( $constant_name ) ) {
				$missing[] = $constant_name;
			}
		}
		return $missing;
	}

	/**
	 * Locate humanmade/s3-uploads' entry file and require it.
	 *
	 * Searches: composer-installed under the mu-plugin's own vendor dir,
	 * the site's root vendor dir, the WP plugins dir (if installed via
	 * composer/installers), and the wp-content/plugins fallback.
	 */
	private function load_s3_uploads(): bool {
		$candidates = array(
			// Composer-installed under our own vendor dir.
			dirname( __DIR__ ) . '/vendor/humanmade/s3-uploads/s3-uploads.php',
			// Composer-installed at the site root.
			defined( 'ABSPATH' ) ? rtrim( (string) constant( 'ABSPATH' ), '/' ) . '/../vendor/humanmade/s3-uploads/s3-uploads.php' : '',
			// Installed via composer/installers as a regular plugin (Bedrock layout).
			defined( 'WP_PLUGIN_DIR' ) ? rtrim( (string) constant( 'WP_PLUGIN_DIR' ), '/' ) . '/s3-uploads/s3-uploads.php' : '',
			// Last-resort fallback: alongside other mu-plugins.
			defined( 'WPMU_PLUGIN_DIR' ) ? rtrim( (string) constant( 'WPMU_PLUGIN_DIR' ), '/' ) . '/s3-uploads/s3-uploads.php' : '',
		);

		foreach ( $candidates as $path ) {
			if ( '' !== $path && is_file( $path ) ) {
				require_once $path;
				return true;
			}
		}

		return false;
	}

	/**
	 * Wire WordPress filters to refuse media uploads with a clear error.
	 *
	 * Better than silent fallback to local disk in a containerized env.
	 */
	private function refuse_uploads( string $reason ): void {
		$this->upload_refused = true;
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional log target.
		error_log( '[FrankenPress\\S3UploadsBootstrap] REFUSING uploads: ' . $reason );

		add_filter(
			'wp_handle_upload_prefilter',
			static function ( array $file ) use ( $reason ): array {
				$file['error'] = 'FrankenPress: media uploads disabled — ' . $reason;
				return $file;
			}
		);
		add_filter(
			'wp_handle_sideload_prefilter',
			static function ( array $file ) use ( $reason ): array {
				$file['error'] = 'FrankenPress: media uploads disabled — ' . $reason;
				return $file;
			}
		);
	}
}
