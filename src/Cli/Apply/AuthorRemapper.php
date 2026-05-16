<?php
/**
 * AuthorRemapper — rewrites `wp_posts.post_author` on every row
 * imported by WP-Importer during `wp fp apply`.
 *
 * Why: `wp_users` is NOT in the snapshot allowlist (PII envelope —
 * see Capturer + the fp.snapshot/v5 spec), so prod's author IDs
 * don't resolve on the target site. Without intervention, the WXR
 * importer either fails to map the author (`--authors=skip`) or
 * lands every row with `post_author = 0`, which the WP Media Library
 * and Posts list render as "(no author)". Designer expectation is
 * "I'm working on this locally"; the local admin (user ID 1) is the
 * intuitive owner.
 *
 * Implementation: filters `wp_import_post_data_processed` (fired by
 * the WordPress Importer plugin after its author-mapping pass) and
 * overrides `post_author` to the configured target ID. The filter
 * is registered only when the `FP_APPLY_REMAP_AUTHORS` env var is
 * non-empty — so it has zero overhead on normal site operations
 * (the value is set transiently by {@see Restorer::run_wp_import()}
 * before spawning the `wp import` subprocess, and the subprocess
 * inherits the parent's env).
 *
 * Schema is **unchanged** — this is apply-side behaviour, not
 * capture-side. fp.snapshot/v5 captures still ship with prod author
 * IDs in the WXR; only the import step rewrites them.
 *
 * @package FrankenPress\Cli\Apply
 */

declare(strict_types=1);

namespace FrankenPress\Cli\Apply;

final class AuthorRemapper {

	private const ENV_TARGET = 'FP_APPLY_REMAP_AUTHORS';

	/**
	 * Register the filter when the env var is set, otherwise no-op.
	 * Called from {@see \FrankenPress\MuPlugin::bootstrap()} on every
	 * request; gating keeps the filter inactive outside of an
	 * in-flight apply.
	 */
	public function register(): void {
		$target = $this->target_user_id();
		if ( $target < 1 ) {
			return;
		}
		add_filter(
			'wp_import_post_data_processed',
			/**
			 * @param array<string, mixed> $postdata
			 * @return array<string, mixed>
			 */
			static function ( array $postdata ) use ( $target ): array {
				$postdata['post_author'] = $target;
				return $postdata;
			},
			10,
			1
		);
	}

	/**
	 * Read the env-var-configured target user ID. Returns 0 when
	 * unset (filter is not registered). Accepts:
	 *
	 *   - "1" / "true" / "yes" / "on" → target = 1 (local admin)
	 *   - "<positive int>"            → target = <int>
	 *   - "" / "0" / "false" / unset  → 0 (no remap)
	 */
	public function target_user_id(): int {
		$value = (string) getenv( self::ENV_TARGET );
		if ( '' === $value ) {
			return 0;
		}
		$lower = strtolower( $value );
		if ( in_array( $lower, array( '0', 'false', 'no', 'off' ), true ) ) {
			return 0;
		}
		if ( ctype_digit( $value ) ) {
			$int = (int) $value;
			return $int > 0 ? $int : 0;
		}
		// Truthy non-numeric ("1"/"true"/"yes"/"on") → local admin.
		if ( in_array( $lower, array( '1', 'true', 'yes', 'on' ), true ) ) {
			return 1;
		}
		return 0;
	}
}
