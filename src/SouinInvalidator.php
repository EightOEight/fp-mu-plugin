<?php
/**
 * Souin cache invalidator.
 *
 * Connects directly to the Redis backend that Souin (caddyserver/cache-handler
 * via the FrankenPress runtime) uses, and DELs cache entries on WordPress
 * lifecycle events. This is the *direct-Redis-DEL* strategy chosen in the
 * Phase 0 spike — Souin's documented HTTP invalidation APIs (PURGE,
 * POST/DELETE-CRUD, /api.souin admin) are unreliable in cache-handler v0.16.0.
 * See https://github.com/EightOEight/fp-runtime/blob/main/PHASE-0.md.
 *
 * The Redis key shape Souin uses (verified empirically):
 *
 *   GET-<scheme>-<host>-<path>     cached response body
 *   IDX_GET-<scheme>-<host>-<path> index entry pointing at the body
 *   SURROGATE_<tag>                set of cache keys associated with a tag
 *
 * For per-URL invalidation (post save, etc.) we DEL the body + index keys.
 * For tag-based bulk invalidation we read the SURROGATE_<tag> set, pipeline-
 * DEL all members, then DEL the index itself.
 *
 * Configuration (env vars, all optional):
 *
 *   FP_SOUIN_REDIS_HOST       redis hostname        (default: redis)
 *   FP_SOUIN_REDIS_PORT       redis port            (default: 6379)
 *   FP_SOUIN_REDIS_PASSWORD   AUTH password         (default: empty / none)
 *   FP_SOUIN_REDIS_DB         redis logical db      (default: 0)
 *   FP_SOUIN_REDIS_TIMEOUT    connect timeout (s)   (default: 1.0)
 *   FP_SOUIN_DISABLED         set to truthy to no-op (default: false)
 *
 * No-op safe: if ext-redis isn't loaded, the connection fails, or
 * FP_SOUIN_DISABLED is set, the invalidator is a silent no-op. Errors are
 * logged but never raised — a broken cache layer must not break WP itself.
 *
 * @package FrankenPress
 */

declare(strict_types=1);

namespace FrankenPress;

use Throwable;

final class SouinInvalidator {

	/**
	 * Lazily-resolved Redis client. `null` means either disabled or the
	 * connection failed; in both cases all invalidation calls become no-ops.
	 */
	private ?\Redis $redis = null;

	/**
	 * Whether `bootstrap()` has run successfully (and hooks are wired).
	 */
	private bool $hooks_registered = false;

	/**
	 * Optional injection point for tests.
	 *
	 * @param \Redis|null $redis Pre-configured Redis client (test injection).
	 */
	public function __construct( ?\Redis $redis = null ) {
		if ( null !== $redis ) {
			$this->redis = $redis;
		}
	}

	/**
	 * Connect to Redis (if not already injected) and register WordPress hooks.
	 */
	public function bootstrap(): void {
		if ( $this->is_disabled() ) {
			return;
		}

		if ( null === $this->redis ) {
			$this->redis = $this->try_connect();
		}

		if ( null === $this->redis ) {
			return;
		}

		add_action( 'save_post', array( $this, 'on_save_post' ), 10, 1 );
		add_action( 'deleted_post', array( $this, 'on_save_post' ), 10, 1 );
		add_action( 'clean_post_cache', array( $this, 'on_save_post' ), 10, 1 );
		add_action( 'comment_post', array( $this, 'on_comment_post' ), 10, 2 );
		add_action( 'transition_comment_status', array( $this, 'on_comment_status' ), 10, 3 );
		add_action( 'switch_theme', array( $this, 'invalidate_all' ) );
		add_action( 'permalink_structure_changed', array( $this, 'invalidate_all' ) );
		add_action( 'updated_option', array( $this, 'on_updated_option' ), 10, 1 );

		$this->hooks_registered = true;
	}

	public function hooks_registered(): bool {
		return $this->hooks_registered;
	}

	/**
	 * Invalidate the cache for a single absolute URL.
	 *
	 * Souin's cache key for a request `https://example.com/post/1` is
	 * `GET-https-example.com-/post/1`. We DEL that key plus its
	 * `IDX_<key>` index entry.
	 *
	 * @return int Number of Redis keys deleted (0 if no-op or failure).
	 */
	public function invalidate_url( string $url ): int {
		if ( null === $this->redis || '' === $url ) {
			return 0;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return 0;
		}

		$host = $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$host .= ':' . $parts['port'];
		}
		$path = $parts['path'] ?? '/';
		if ( ! empty( $parts['query'] ) ) {
			$path .= '?' . $parts['query'];
		}

		$body_key  = sprintf( 'GET-%s-%s-%s', $parts['scheme'], $host, $path );
		$index_key = 'IDX_' . $body_key;

		try {
			return (int) $this->redis->del( array( $body_key, $index_key ) );
		} catch ( Throwable $e ) {
			$this->log_error( 'invalidate_url failed', $e );
			return 0;
		}
	}

	/**
	 * Invalidate all cache entries tagged with the given Surrogate-Key.
	 *
	 * @return int Number of Redis keys deleted (0 if no-op or failure).
	 */
	public function invalidate_tag( string $tag ): int {
		if ( null === $this->redis || '' === $tag ) {
			return 0;
		}

		$surrogate_key = 'SURROGATE_' . $tag;

		try {
			$members = $this->redis->sMembers( $surrogate_key );
			if ( ! is_array( $members ) ) {
				$members = array();
			}

			$keys = array();
			foreach ( $members as $cache_key ) {
				$keys[] = (string) $cache_key;
				$keys[] = 'IDX_' . $cache_key;
			}
			$keys[] = $surrogate_key;

			if ( count( $keys ) <= 1 ) {
				// Tag exists but is empty (or doesn't exist). Still try to
				// remove the surrogate index in case it lingers.
				return (int) $this->redis->del( array( $surrogate_key ) );
			}

			return (int) $this->redis->del( $keys );
		} catch ( Throwable $e ) {
			$this->log_error( 'invalidate_tag failed', $e );
			return 0;
		}
	}

	/**
	 * Invalidate every Souin-managed cache entry on this Redis backend.
	 *
	 * Used for changes that affect every URL (theme switch, permalink
	 * structure change, etc.). Targets only Souin's own keys (GET-*, IDX_*,
	 * SURROGATE_*) so we don't trample WP object cache or other shared use.
	 *
	 * @return int Number of Redis keys deleted (0 if no-op or failure).
	 */
	public function invalidate_all(): int {
		if ( null === $this->redis ) {
			return 0;
		}

		$total = 0;
		try {
			foreach ( array( 'GET-*', 'IDX_*', 'SURROGATE_*' ) as $pattern ) {
				$cursor = null;
				while ( true ) {
					$batch = $this->redis->scan( $cursor, $pattern, 256 );
					if ( ! is_array( $batch ) || empty( $batch ) ) {
						break;
					}
					$total += (int) $this->redis->del( $batch );
					if ( null === $cursor || 0 === (int) $cursor ) {
						break;
					}
				}
			}
		} catch ( Throwable $e ) {
			$this->log_error( 'invalidate_all failed', $e );
		}

		return $total;
	}

	/**
	 * Hook callback: invalidate the post URL + tag on save / delete / cache clear.
	 */
	public function on_save_post( int $post_id ): void {
		$permalink = get_permalink( $post_id );
		if ( is_string( $permalink ) && '' !== $permalink ) {
			$this->invalidate_url( $permalink );
		}
		$this->invalidate_tag( 'post-' . $post_id );

		// Front pages, archives, feeds typically include the post — bust them.
		$home = function_exists( 'home_url' ) ? home_url( '/' ) : '';
		if ( '' !== $home ) {
			$this->invalidate_url( $home );
		}
	}

	/**
	 * Hook callback: comment created against a post. Bust the parent post's cache.
	 *
	 * @param int $comment_id Comment ID.
	 * @param int $approved   1 if approved, 0 if held, 'spam' if spam.
	 */
	public function on_comment_post( int $comment_id, $approved ): void {
		if ( 1 !== (int) $approved ) {
			return;
		}
		$comment = function_exists( 'get_comment' ) ? get_comment( $comment_id ) : null;
		if ( ! $comment || empty( $comment->comment_post_ID ) ) {
			return;
		}
		$this->on_save_post( (int) $comment->comment_post_ID );
	}

	/**
	 * Hook callback: comment status transitioned (approved, unapproved, spam, trash).
	 * Any visibility change affects the parent post's cached state.
	 *
	 * @param string $new_status New status (unused — any change busts the cache).
	 * @param string $old_status Old status (unused).
	 * @param object $comment    WP_Comment object.
	 */
	public function on_comment_status( string $new_status, string $old_status, $comment ): void {
		unset( $new_status, $old_status );
		if ( ! is_object( $comment ) || empty( $comment->comment_post_ID ) ) {
			return;
		}
		$this->on_save_post( (int) $comment->comment_post_ID );
	}

	/**
	 * Hook callback: option changed. Invalidate everything for global-impact options.
	 *
	 * @param string $option Option name.
	 */
	public function on_updated_option( string $option ): void {
		$global_options = array(
			'blogname',
			'blogdescription',
			'home',
			'siteurl',
			'show_on_front',
			'page_on_front',
			'page_for_posts',
			'date_format',
			'time_format',
			'permalink_structure',
		);
		if ( in_array( $option, $global_options, true ) ) {
			$this->invalidate_all();
		}
	}

	/**
	 * Was the invalidator explicitly disabled by env / constant?
	 */
	private function is_disabled(): bool {
		if ( defined( 'FP_SOUIN_DISABLED' ) && (bool) constant( 'FP_SOUIN_DISABLED' ) ) {
			return true;
		}
		$env = getenv( 'FP_SOUIN_DISABLED' );
		return false !== $env && '' !== $env && '0' !== $env && 'false' !== strtolower( (string) $env );
	}

	/**
	 * Best-effort Redis connect. Returns null on any failure; caller treats null as no-op.
	 */
	private function try_connect(): ?\Redis {
		if ( ! class_exists( '\Redis' ) ) {
			$this->log_error( 'ext-redis not loaded; SouinInvalidator is a no-op', null );
			return null;
		}

		$host_env    = getenv( 'FP_SOUIN_REDIS_HOST' );
		$port_env    = getenv( 'FP_SOUIN_REDIS_PORT' );
		$db_env      = getenv( 'FP_SOUIN_REDIS_DB' );
		$timeout_env = getenv( 'FP_SOUIN_REDIS_TIMEOUT' );

		$host     = ( false === $host_env || '' === $host_env ) ? 'redis' : (string) $host_env;
		$port     = ( false === $port_env || '' === $port_env ) ? 6379 : (int) $port_env;
		$password = (string) getenv( 'FP_SOUIN_REDIS_PASSWORD' );
		$db       = ( false === $db_env || '' === $db_env ) ? 0 : (int) $db_env;
		$timeout  = ( false === $timeout_env || '' === $timeout_env ) ? 1.0 : (float) $timeout_env;

		try {
			$client = new \Redis();
			if ( ! $client->connect( $host, $port, $timeout ) ) {
				return null;
			}
			if ( '' !== $password && ! $client->auth( $password ) ) {
				return null;
			}
			if ( 0 !== $db ) {
				$client->select( $db );
			}
			return $client;
		} catch ( Throwable $e ) {
			$this->log_error( 'Redis connect failed', $e );
			return null;
		}
	}

	private function log_error( string $message, ?Throwable $e ): void {
		$detail = null !== $e ? ' — ' . $e->getMessage() : '';
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional log target.
		error_log( '[FrankenPress\\SouinInvalidator] ' . $message . $detail );
	}
}
