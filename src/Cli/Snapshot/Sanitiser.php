<?php
/**
 * SQL dump sanitiser for `wp fp snapshot`.
 *
 * Reads a `wp db export --extended-insert=0` dump one line at a time
 * and either drops the line (rows we don't want in the snapshot) or
 * rewrites it (sensitive values redacted). URL rewriting is deliberately
 * out of scope — the apply-side runs `wp search-replace {source_url}
 * {target_url}` against the restored DB, which is the only correct way
 * to handle URLs inside serialised PHP (a naive str_replace breaks the
 * length prefixes that `serialize()` emits).
 *
 * What we strip / redact (in v1, hard-coded):
 *
 *   - Transients (wp_options.option_name LIKE '_transient_%' or
 *     '_site_transient_%'). They're cache, regenerated on demand.
 *   - Session tokens (wp_usermeta.meta_key = 'session_tokens'). Stale
 *     logins from local dev shouldn't grant access in the target env.
 *   - User password hashes (wp_users.user_pass column → sentinel).
 *     wp-cli `core install` overwrites the admin password during
 *     install; designer's local password should not propagate.
 *   - Action Scheduler queue / logs (wp_actionscheduler_*). It's
 *     ephemeral run-state, will rebuild itself on first cron tick.
 *   - WP CLI's own scheduled-event store (wp_options.option_name =
 *     'cron'). Local cron timestamps don't translate.
 *
 * Caller drives line-by-line iteration; this class is stateless on the
 * dump itself, so adapter-specific extensions in future phases can
 * decorate without subclassing.
 *
 * @package FrankenPress\Cli\Snapshot
 */

declare(strict_types=1);

namespace FrankenPress\Cli\Snapshot;

final class Sanitiser {

	/**
	 * Sentinel substituted into user_pass values. wp-cli `core install`
	 * (which the chart's post-install Job runs as part of the apply path)
	 * overwrites the admin user_pass anyway, so this never needs to be
	 * an actually-hashable bcrypt prefix; it just has to be obviously
	 * "this was redacted" if it ever leaks into a log.
	 */
	public const REDACTED_PASSWORD = '[FP-REDACTED-PASSWORD]';

	/**
	 * Table-prefix-agnostic regex fragments.
	 *
	 * Prefixes are matched as `(?:[a-zA-Z0-9]+_)?` — an optional
	 * alphanumeric run followed by `_`. The trailing underscore is what
	 * lets the regex distinguish `wp_options` (matches) from
	 * `wp_useroptions` (doesn't match — no `_` immediately before
	 * `options`). Captured groups stay in the same numeric positions
	 * across matches so rewrite helpers don't have to special-case
	 * prefixes.
	 *
	 * @var array<string, string>
	 */
	private const PATTERNS = array(
		// Single-row INSERT INTO `<prefix>_options` VALUES (id, name, value, autoload);
		'options'         => '/^INSERT\s+INTO\s+`((?:[a-zA-Z0-9]+_)?options)`\s+VALUES\s*\(\s*[0-9]+\s*,\s*\'((?:[^\'\\\\]|\\\\.)*?)\'\s*,/i',
		// Single-row INSERT INTO `<prefix>_usermeta` VALUES (id, user_id, key, value);
		'usermeta'        => '/^INSERT\s+INTO\s+`((?:[a-zA-Z0-9]+_)?usermeta)`\s+VALUES\s*\(\s*[0-9]+\s*,\s*[0-9]+\s*,\s*\'((?:[^\'\\\\]|\\\\.)*?)\'\s*,/i',
		// Single-row INSERT INTO `<prefix>_users` VALUES (id, login, user_pass, ...);
		// We capture the user_pass column (position 3, 0-indexed) for replacement.
		'users'           => '/^(INSERT\s+INTO\s+`((?:[a-zA-Z0-9]+_)?users)`\s+VALUES\s*\(\s*[0-9]+\s*,\s*\'(?:[^\'\\\\]|\\\\.)*?\'\s*,\s*)\'(?:[^\'\\\\]|\\\\.)*?\'(\s*,)/i',
		// Any INSERT INTO `<prefix>_actionscheduler_*` table.
		'actionscheduler' => '/^INSERT\s+INTO\s+`(?:[a-zA-Z0-9]+_)?actionscheduler_[a-z_]+`/i',
	);

	/**
	 * Sanitise a single dump line. Returns `null` to drop the line
	 * entirely (caller should skip it), or the line (possibly rewritten)
	 * to emit. Newline handling is the caller's concern.
	 *
	 * @param string $line One line from the SQL dump (no trailing newline expected).
	 * @return string|null The sanitised line, or null to drop.
	 */
	public function sanitise( string $line ): ?string {
		// Action Scheduler tables: drop the whole INSERT.
		if ( 1 === preg_match( self::PATTERNS['actionscheduler'], $line ) ) {
			return null;
		}

		// wp_options rows: drop transients + cron + similar housekeeping.
		if ( 1 === preg_match( self::PATTERNS['options'], $line, $m ) ) {
			$option_name = $this->unescape_sql_string( $m[2] );
			if ( $this->is_droppable_option( $option_name ) ) {
				return null;
			}
			return $line;
		}

		// wp_usermeta rows: drop session tokens + WP-CLI's own auth state.
		if ( 1 === preg_match( self::PATTERNS['usermeta'], $line, $m ) ) {
			$meta_key = $this->unescape_sql_string( $m[2] );
			if ( $this->is_droppable_usermeta( $meta_key ) ) {
				return null;
			}
			return $line;
		}

		// wp_users rows: redact the user_pass column.
		if ( 1 === preg_match( self::PATTERNS['users'], $line ) ) {
			return preg_replace(
				self::PATTERNS['users'],
				'$1\'' . self::REDACTED_PASSWORD . '\'$3',
				$line,
				1
			);
		}

		return $line;
	}

	/**
	 * Hard-coded list of wp_options rows we don't want in the snapshot.
	 *
	 * Transients (both single-site and site-wide), the cron schedule,
	 * and a handful of WP/runtime housekeeping options that never
	 * translate cleanly across environments.
	 */
	private function is_droppable_option( string $option_name ): bool {
		if ( str_starts_with( $option_name, '_transient_' ) || str_starts_with( $option_name, '_site_transient_' ) ) {
			return true;
		}
		return in_array(
			$option_name,
			array(
				'cron',
				'recovery_keys',
				'auth_key',
				'auth_salt',
				'secure_auth_key',
				'secure_auth_salt',
				'logged_in_key',
				'logged_in_salt',
				'nonce_key',
				'nonce_salt',
				// Bedrock-autoloader caches mu-plugin discovery; safer to let
				// the target env rediscover on first request.
				'bedrock_autoloader',
			),
			true
		);
	}

	private function is_droppable_usermeta( string $meta_key ): bool {
		return in_array(
			$meta_key,
			array(
				'session_tokens',
				'wp_user-settings',
				'wp_user-settings-time',
			),
			true
		);
	}

	/**
	 * Reverse what mysqldump-style SQL escaping does inside a single-quoted
	 * string. Only the four escapes we actually encounter: \\, \', \", \n.
	 * Anything more exotic falls through to its raw form — fine for the
	 * option-name / meta-key matching we use it for.
	 */
	private function unescape_sql_string( string $escaped ): string {
		return strtr(
			$escaped,
			array(
				'\\\\' => '\\',
				"\\'"  => "'",
				'\\"'  => '"',
				'\\n'  => "\n",
			)
		);
	}
}
