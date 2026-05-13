<?php
/**
 * Snapshot restorer — orchestrates `wp fp apply` in fp.snapshot/v5.
 *
 * Inverse of {@see \FrankenPress\Cli\Snapshot\Capturer}. Reads a
 * snapshot directory, verifies its integrity, imports the WXR via
 * WP-Importer, applies the scoped options sidecar, fires adapter
 * post_apply() hooks, and stamps idempotency markers.
 *
 * Why WP-Importer (rather than `wp db import`):
 *
 *   - WXR is additive — only INSERTs new posts; skips existing terms
 *     by slug; remaps post IDs on collision and fixes up postmeta
 *     references automatically.
 *   - NO DROPs, NO DELETEs, NO TRUNCATES anywhere in the apply path.
 *   - Tables outside the snapshot scope (wc_orders, wp_users,
 *     wp_comments) cannot be touched — they're literally not in the
 *     WXR file, and the options sidecar only touches option_names
 *     declared in scope.
 *
 * Idempotency boundary (v0.14.0+):
 *
 *   wp_options.fp_snapshot_applied_ref                === manifest.id
 *   wp_options.fp_snapshot_applied_composite_sha256   === sha256 of the
 *                                                          four content
 *                                                          shas concat'd
 *                                                          (wxr +
 *                                                           templates +
 *                                                           options +
 *                                                           attachments)
 *
 * Both match → skipped. Either differs → re-runs the full apply.
 *
 * Backward-compat: pre-v0.14.0 builds stamped only
 * `fp_snapshot_applied_sha256` (the wxr-sha alone). Sites upgrading
 * from an older fp don't re-apply once on first run — when the
 * composite marker is absent, the comparison falls back to the wxr-
 * sha alone. The next apply that actually changes the snapshot id
 * stamps the composite marker and migrates the site forward.
 *
 * @package FrankenPress\Cli\Apply
 */

declare(strict_types=1);

namespace FrankenPress\Cli\Apply;

use FrankenPress\Cli\Adapters\AdapterInterface;
use RuntimeException;

final class Restorer {

	public const APPLIED_REF_OPTION              = 'fp_snapshot_applied_ref';
	public const APPLIED_SHA256_OPTION           = 'fp_snapshot_applied_sha256';
	public const APPLIED_COMPOSITE_SHA256_OPTION = 'fp_snapshot_applied_composite_sha256';

	/**
	 * @param string                       $snapshot_dir    Local directory with manifest.json + content.xml.gz + options.json + templates.json + attachments.json.
	 * @param string                       $target_url      home_url() at apply time.
	 * @param array<int, AdapterInterface> $adapters        Registered adapters; the one whose name() matches manifest.adapter is fired.
	 * @param callable                     $wp_runner       fn(string $cmd, array $assoc): mixed — wraps WP_CLI::runcommand.
	 * @param callable                     $option_reader   fn(string $key): mixed.
	 * @param callable                     $option_writer   fn(string $key, mixed $value, bool $autoload): bool.
	 * @param callable                     $theme_mod_set   fn(string $stylesheet, string $key, mixed $value): void.
	 * @param callable                     $owned_finder    fn(string $post_type, string $slug, array $terms): ?int — returns post ID or null. $terms is the captured `terms` map (taxonomy => [slugs]); for theme-bound post types the closure filters on the `wp_theme` slug to avoid cross-theme collisions.
	 * @param callable                     $owned_updater   fn(int $post_id, array $fields, array $meta, array $terms): void — wp_update_post + meta writes + wp_set_object_terms (replace, not append).
	 * @param callable                     $owned_inserter  fn(string $post_type, string $slug, array $fields, array $meta, array $terms): int — wp_insert_post + meta writes + wp_set_object_terms.
	 * @param callable                     $att_finder      fn(string $relative_file): ?int — looks up attachment post ID by `_wp_attached_file` value.
	 * @param callable                     $att_updater     fn(int $post_id, array $fields, array $meta): void.
	 * @param callable                     $att_inserter    fn(array $fields, array $meta): int — wraps `wp_insert_post` for attachments.
	 * @param callable                     $page_finder     fn(string $slug, string $post_type): ?int — resolves a captured page reference to the local post ID, or null when not present on the target. Used by the option_page_refs apply path to rewrite page_on_front / page_for_posts.
	 * @param string                       $uploads_basedir Absolute path to `wp_upload_dir()['basedir']`. May be an `s3://` stream wrapper when S3UploadsBootstrap is active.
	 * @param string                       $uploads_baseurl Public URL prefix for uploads (`wp_upload_dir()['baseurl']`). On FrankenPress with S3UploadsBootstrap this is the S3 bucket URL (or the configured CDN). Used to rewrite captured `<source>/app/uploads` URLs in block markup so attachment renders point at S3 rather than the WP host (which doesn't proxy /app/uploads/ to S3).
	 */
	public function __construct(
		private string $snapshot_dir,
		private string $target_url,
		private array $adapters,
		private $wp_runner,
		private $option_reader,
		private $option_writer,
		private $theme_mod_set,
		private $owned_finder,
		private $owned_updater,
		private $owned_inserter,
		private $att_finder,
		private $att_updater,
		private $att_inserter,
		private $page_finder,
		private string $uploads_basedir,
		private string $uploads_baseurl,
	) {}

	/**
	 * Apply the snapshot. Returns "applied" or "skipped".
	 *
	 * @throws RuntimeException on integrity / IO failure.
	 */
	public function apply(): string {
		$manifest = $this->read_manifest();
		$id       = (string) ( $manifest['id'] ?? '' );

		$expected_sha = (string) ( $manifest['contents']['wxr_sha256'] ?? '' );
		if ( '' === $id || '' === $expected_sha ) {
			throw new RuntimeException( 'manifest is missing required fields (id, contents.wxr_sha256)' );
		}

		$schema = (string) ( $manifest['schema'] ?? '' );
		if ( 'fp.snapshot/v5' !== $schema ) {
			throw new RuntimeException(
				sprintf(
					'manifest schema "%s" is not supported by this fp build (accepts fp.snapshot/v5). v4 snapshots were missing taxonomy data and silently shipped renderer-invisible template-part rows — re-run `wp fp snapshot` on a stack with this mu-plugin version to regenerate.',
					$schema
				)
			);
		}

		$composite_sha = $this->compute_composite_sha256( $manifest );

		if ( $this->already_applied( $id, $expected_sha, $composite_sha ) ) {
			return 'skipped';
		}

		$wxr_path = $this->snapshot_dir . '/content.xml.gz';
		$this->verify_blob( $wxr_path, $expected_sha );

		// Stage 1: import the WXR (post_types_additive — INSERT-only).
		// Post v0.12.0 the Fse adapter's additive scope is empty so
		// this is a no-op for FSE sites; the stage stays for future
		// adapters that need to ship editor-content.
		$this->import_wxr( $wxr_path );

		// Stage 2: upsert attachments + copy binaries FIRST so we have
		// the captured-ID → local-ID remap available for the owned-
		// posts rewrite + options remap. AttachmentRefCapturer captures
		// both option-referenced (site_logo etc.) and block-inline
		// (`<!-- wp:image {"id":42} -->`) attachments; both need their
		// IDs remapped on apply.
		$id_remap = $this->apply_attachments();

		// Stage 3: upsert the owned posts (post_types_owned — UPSERT
		// by post_name+post_type so designer iteration propagates).
		// Block-attribute `"id":<captured>` and `wp-image-<captured>`
		// CSS-class references in post_content are rewritten to the
		// local attachment IDs via $id_remap before the upsert lands.
		$this->apply_owned_posts( $id_remap );

		// Stage 4: apply the scoped options, remapping attachment-ID
		// values via $id_remap so site_logo/site_icon/custom_logo land
		// pointing at the local attachment post ID rather than the
		// designer's captured ID.
		$this->apply_options( $id_remap );

		// Stage 5: search-replace URLs in the imported content.
		//
		// Two passes:
		//   5a. Upload URLs first: <source>/app/uploads → wp_upload_dir.baseurl
		//       (which on FrankenPress + S3UploadsBootstrap is the S3 URL).
		//       Captured block-attr `url` strings are literal — no
		//       wp_get_attachment_url filter runs on them at render time —
		//       so they need to be rewritten to point directly at S3.
		//   5b. Remaining host: <source> → <target>. Catches non-upload
		//       refs (page links, hrefs in widget content, etc.).
		//
		// Order matters: upload-URL pass must run first, before the host-
		// only replace turns `<source>/app/uploads/...` into `<target>/app/uploads/...`
		// (which the second pass wouldn't recognise as an uploads URL).
		$source_url = (string) ( $manifest['source']['site_url'] ?? '' );
		if ( '' !== $source_url ) {
			$captured_uploads = $source_url . '/app/uploads';
			if ( '' !== $this->uploads_baseurl && $captured_uploads !== $this->uploads_baseurl ) {
				$this->retarget_urls( $captured_uploads, $this->uploads_baseurl );
			}
			if ( $source_url !== $this->target_url ) {
				$this->retarget_urls( $source_url, $this->target_url );
			}
		}

		// Stage 6: adapter post_apply hook (single adapter).
		$this->fire_adapter(
			(string) ( $manifest['adapter'] ?? '' ),
			(array) ( $manifest['adapter_state'] ?? array() )
		);

		// Stage 7: idempotency markers. Three options written so this
		// build is forward-compatible (future readers can rely on the
		// composite hash) AND backward-compatible (older readers that
		// only know about the wxr-sha marker keep working).
		( $this->option_writer )( self::APPLIED_REF_OPTION, $id, true );
		( $this->option_writer )( self::APPLIED_SHA256_OPTION, $expected_sha, true );
		( $this->option_writer )( self::APPLIED_COMPOSITE_SHA256_OPTION, $composite_sha, true );

		return 'applied';
	}

	/**
	 * @return array<string, mixed>
	 */
	private function read_manifest(): array {
		$path = $this->snapshot_dir . '/manifest.json';
		if ( ! is_file( $path ) ) {
			throw new RuntimeException( "manifest.json not found in {$this->snapshot_dir}" );
		}
		$raw = file_get_contents( $path );
		if ( false === $raw || '' === $raw ) {
			throw new RuntimeException( "manifest.json at {$path} is empty or unreadable" );
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			throw new RuntimeException( "manifest.json at {$path} is not a JSON object" );
		}
		return $decoded;
	}

	/**
	 * Decide whether the snapshot at $id has already been applied.
	 *
	 * Compares the on-disk manifest's id + content hashes against the
	 * markers stamped by a previous apply. The composite hash is the
	 * authoritative comparison from v0.14.0 onward; the wxr_sha check
	 * is the pre-composite fallback so sites that were applied by an
	 * older fp build don't re-apply once when this build first runs.
	 *
	 * Why the composite hash matters: for FSE sites the WXR is empty
	 * and its sha is constant across snapshots. A manifest with the
	 * same id but tampered templates.json / options.json content would
	 * skip falsely under the wxr-only check. The composite mixes every
	 * content-sha from the manifest, so any change to any sidecar
	 * forces a re-apply.
	 */
	private function already_applied( string $id, string $wxr_sha, string $composite_sha ): bool {
		$prior_ref       = ( $this->option_reader )( self::APPLIED_REF_OPTION );
		$prior_composite = ( $this->option_reader )( self::APPLIED_COMPOSITE_SHA256_OPTION );
		$prior_wxr_sha   = ( $this->option_reader )( self::APPLIED_SHA256_OPTION );

		if ( $id !== $prior_ref ) {
			return false;
		}

		// Composite-era: trust the composite. Mismatch on any content
		// sha (templates / options / attachments) triggers re-apply.
		if ( is_string( $prior_composite ) && '' !== $prior_composite ) {
			return $composite_sha === $prior_composite;
		}

		// Pre-composite-era marker (site was last applied by a build
		// before v0.14.0). Fall back to the old wxr-sha check so the
		// upgrade doesn't cascade into a forced re-apply for every
		// site on first install Job run. The next apply (with any
		// snapshot change) stamps the composite marker and from then
		// on the precise comparison kicks in.
		return $wxr_sha === $prior_wxr_sha;
	}

	/**
	 * Compute the composite sha256 = sha256 of the four content shas
	 * concatenated in a stable order. Same input → same output, every
	 * time (used for both the apply-time decision and the post-apply
	 * marker).
	 *
	 * @param array<string, mixed> $manifest
	 */
	private function compute_composite_sha256( array $manifest ): string {
		$contents = is_array( $manifest['contents'] ?? null ) ? (array) $manifest['contents'] : array();
		$parts    = array(
			(string) ( $contents['wxr_sha256'] ?? '' ),
			(string) ( $contents['templates_sha256'] ?? '' ),
			(string) ( $contents['options_sha256'] ?? '' ),
			(string) ( $contents['attachments_sha256'] ?? '' ),
		);
		return hash( 'sha256', implode( '|', $parts ) );
	}

	private function verify_blob( string $path, string $expected_sha ): void {
		if ( ! is_file( $path ) ) {
			throw new RuntimeException( "content.xml.gz not found at {$path}" );
		}
		$actual = hash_file( 'sha256', $path );
		if ( $actual !== $expected_sha ) {
			throw new RuntimeException(
				"content.xml.gz integrity check failed: manifest says {$expected_sha}, actual {$actual}"
			);
		}
	}

	/**
	 * Run the WP-Importer against content.xml.gz. The importer plugin
	 * is the canonical WordPress mechanism for ingesting WXR; it
	 * handles ID remapping, term dedup, attachment refs, etc.
	 *
	 * WP-Importer lifecycle: managed transparently by ensure_wxr_importer()
	 * and torn down in finally. Three modes the apply path navigates:
	 *
	 *   borrowed  — plugin already in active_plugins when we started.
	 *               Don't touch it on teardown. The site is using it.
	 *   activated — plugin was installed (file on disk) but inactive.
	 *               We activated it. Leave it active on teardown (the
	 *               file persists, the site can keep using it).
	 *   installed — plugin wasn't on disk. We installed AND activated
	 *               it. The file is in the install Job's overlay FS
	 *               only — gone when the pod GC's. We MUST deactivate
	 *               on teardown to keep wp_options.active_plugins
	 *               consistent with the next web Pod's disk state.
	 *
	 * `--authors=skip` because designer-side WXR exports include the
	 * designer's user as the post_author; we don't want to ship user
	 * accounts. `wp post` calls preserve existing post authors if the
	 * referenced user doesn't exist.
	 */
	private function import_wxr( string $gz_path ): void {
		$mode = $this->ensure_wxr_importer();

		// Decompress to a tmp .xml file. wp import expects a path.
		$tmp = tempnam( sys_get_temp_dir(), 'fp-wxr-' );
		if ( false === $tmp ) {
			$this->teardown_wxr_importer( $mode );
			throw new RuntimeException( 'could not create temp file for WXR' );
		}
		$xml_path = $tmp . '.xml';
		try {
			$gz = gzopen( $gz_path, 'rb' );
			if ( false === $gz ) {
				throw new RuntimeException( "could not gzopen {$gz_path}" );
			}
			$out = fopen( $xml_path, 'wb' );
			if ( false === $out ) {
				gzclose( $gz );
				throw new RuntimeException( "could not open {$xml_path}" );
			}
			while ( ! gzeof( $gz ) ) {
				$chunk = gzread( $gz, 64 * 1024 );
				if ( false === $chunk || '' === $chunk ) {
					break;
				}
				fwrite( $out, $chunk );
			}
			fclose( $out );
			gzclose( $gz );

			// Run `wp import` as a fresh subprocess (NOT via
			// WP_CLI::runcommand with `launch => false`).
			//
			// WP-Importer's main file early-returns unless
			// `WP_LOAD_IMPORTERS` is defined. wp-cli's `import` command
			// package defines that constant in its `load_import_class()`
			// helper at top-level dispatch — but `runcommand(..., launch
			// => false)` shares the parent process where wp-cli already
			// loaded all plugins (incl. WP-Importer) with `WP_LOAD_IMPORTERS`
			// UNDEFINED. Result: WP-Importer's main file short-circuited
			// on first load, `WP_Import` class never defined, and the
			// inner `import` command bails with "WordPress Importer
			// needs to be activated." PHP's `require_once` won't re-
			// evaluate the file no matter how late we define the
			// constant — once it's in `get_included_files()`, it's done.
			//
			// proc_open of `wp import ...` as a fresh subprocess
			// guarantees wp-cli's top-level dispatch path runs:
			// `load_import_class()` defines WP_LOAD_IMPORTERS, then
			// `require_once` the plugin file fresh — and this time the
			// file executes its body, defining WP_Import. The whole
			// class hierarchy comes up cleanly.
			$this->run_wp_import( $xml_path );
		} finally {
			if ( file_exists( $xml_path ) ) {
				unlink( $xml_path );
			}
			if ( file_exists( $tmp ) ) {
				unlink( $tmp );
			}
			$this->teardown_wxr_importer( $mode );
		}
	}

	/**
	 * Run `wp import <file> --authors=skip --skip=image_resize` as a
	 * fresh subprocess (not via WP_CLI::runcommand). See the comment
	 * block in import_wxr() for why this can't go through the shared
	 * wp_runner closure.
	 *
	 * Stderr is targeted at a regular file (same pattern as
	 * WxrCapturer::run_export) — wp import emits a progress line per
	 * post and `wp_runner`'s default in-process invocation deadlocks
	 * when the kernel pipe buffer fills.
	 */
	private function run_wp_import( string $xml_path ): void {
		$wp_bin     = $this->locate_wp_binary();
		$stderr_log = $xml_path . '.stderr.log';
		$cmd        = array(
			$wp_bin,
			'--allow-root',
			'--path=' . $this->wp_path(),
			'import',
			$xml_path,
			'--authors=skip',
			'--skip=image_resize',
		);
		$descs      = array(
			0 => array( 'file', '/dev/null', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'file', $stderr_log, 'w' ),
		);
		$proc       = proc_open( $cmd, $descs, $pipes );
		if ( ! is_resource( $proc ) ) {
			throw new RuntimeException( 'apply: proc_open(wp import) failed' );
		}
		// Drain stdout to keep the child unblocked. wp-cli's import
		// command writes a progress line per post; we don't need the
		// content, just need to keep reading so the pipe doesn't fill.
		while ( ! feof( $pipes[1] ) ) {
			$chunk = fread( $pipes[1], 64 * 1024 );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
		}
		fclose( $pipes[1] );
		$exit_code = proc_close( $proc );

		$stderr = is_file( $stderr_log ) ? (string) file_get_contents( $stderr_log ) : '';
		@unlink( $stderr_log );

		if ( 0 !== $exit_code ) {
			throw new RuntimeException(
				sprintf(
					'apply: wp import exited %d%s',
					$exit_code,
					'' !== trim( $stderr ) ? ' (stderr: ' . trim( $stderr ) . ')' : ''
				)
			);
		}
	}

	private function locate_wp_binary(): string {
		foreach ( array( '/usr/local/bin/wp', '/usr/bin/wp' ) as $candidate ) {
			if ( is_executable( $candidate ) ) {
				return $candidate;
			}
		}
		return 'wp';
	}

	private function wp_path(): string {
		if ( defined( 'ABSPATH' ) ) {
			return rtrim( (string) constant( 'ABSPATH' ), '/' );
		}
		return '';
	}

	/**
	 * Make sure WP-Importer is active before `wp import` runs.
	 *
	 *   borrowed  — already active, leave it alone
	 *   activated — installed but inactive → activate (option write only)
	 *   installed — neither installed nor active → install + activate
	 *
	 * `wp plugin install` requires (a) a writable filesystem and
	 * (b) `DISALLOW_FILE_MODS` not defined or false. Both are arranged
	 * by the chart's install Job: it runs with
	 * `readOnlyRootFilesystem: false` and writes a one-shot
	 * `/tmp/lockdown-override.php` (sourced via wp-cli `--require=`)
	 * that pre-defines the lockdown constants to `false` before
	 * wp-config.php loads. Web Pods (where the lockdown protects
	 * against supply-chain mutation) are unaffected.
	 *
	 * @return 'borrowed' | 'activated' | 'installed'
	 */
	private function ensure_wxr_importer(): string {
		$slug = 'wordpress-importer/wordpress-importer.php';

		$active_plugins = (array) ( ( $this->option_reader )( 'active_plugins' ) ?? array() );
		if ( in_array( $slug, $active_plugins, true ) ) {
			return 'borrowed';
		}

		$plugin_dir  = defined( 'WP_PLUGIN_DIR' ) ? (string) constant( 'WP_PLUGIN_DIR' ) : '';
		$plugin_file = '' !== $plugin_dir ? rtrim( $plugin_dir, '/' ) . '/' . $slug : '';
		$installed   = '' !== $plugin_file && is_file( $plugin_file );

		if ( $installed ) {
			// Disk has it, options doesn't — activate. Pure option-write,
			// no filesystem mutation, safe even on a RO root.
			( $this->wp_runner )(
				sprintf( 'plugin activate %s', escapeshellarg( 'wordpress-importer' ) ),
				array()
			);
			return 'activated';
		}

		// Not on disk — install + activate in one shot. Requires the
		// chart-side install Job relaxations described above. The plugin
		// file lives in the Job pod's writable overlay only.
		( $this->wp_runner )(
			sprintf( 'plugin install %s --activate', escapeshellarg( 'wordpress-importer' ) ),
			array()
		);
		return 'installed';
	}

	/**
	 * Reverse `ensure_wxr_importer()`'s side effects appropriately for
	 * the mode it returned. Only `installed` requires action: the plugin
	 * file is in the Job pod's ephemeral overlay, so we deactivate so
	 * the next web Pod doesn't try to load a file that's no longer on
	 * disk (`require_once` would fatal on each request).
	 */
	private function teardown_wxr_importer( string $mode ): void {
		if ( 'installed' !== $mode ) {
			return;
		}
		try {
			( $this->wp_runner )(
				sprintf( 'plugin deactivate %s', escapeshellarg( 'wordpress-importer' ) ),
				array()
			);
		} catch ( \Throwable $e ) {
			// Deactivation failure during teardown is loud-but-non-fatal:
			// the apply itself has either succeeded or already thrown
			// the real error. We don't want to mask that with a
			// secondary teardown exception. Best-effort.
			error_log( 'fp apply: WP-Importer teardown deactivate failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Apply the templates.json sidecar — upsert each entry by
	 * `post_name + post_type`. Existing rows get `wp_update_post`;
	 * missing rows get `wp_insert_post`. Postmeta in `meta` is set
	 * via `update_post_meta` after the post fields land. Taxonomy
	 * terms in `terms` are applied (REPLACE not append) via
	 * `wp_set_object_terms` so the row is correctly bound to the
	 * active theme on the target.
	 *
	 * Without the taxonomy step, customised `wp_template_part` rows
	 * are invisible to `get_block_templates()` on the target —
	 * WP keys design-state lookup off the `wp_theme` taxonomy term,
	 * not postmeta. v4 snapshots that skipped this step shipped
	 * renderer-invisible rows (the "header doesn't update on apply"
	 * symptom from sts-stg 2026-05-13).
	 *
	 * Solves the v3 silent-skip problem where WP-Importer's GUID
	 * dedup retained existing FSE-CPT rows on second-apply, blocking
	 * designer iteration. See `iteration-ux.md` in the workspace
	 * `.aidocs/` for the reproduction.
	 *
	 * @param array<int, int> $attachment_id_remap captured_id => local_id
	 */
	private function apply_owned_posts( array $attachment_id_remap = array() ): void {
		$path = $this->snapshot_dir . '/templates.json';
		if ( ! is_file( $path ) ) {
			return;
		}
		$raw = file_get_contents( $path );
		if ( false === $raw || '' === $raw ) {
			return;
		}
		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) ) {
			return;
		}

		// captured_id → local_id for wp_block entries. Built up as we
		// upsert wp_block rows (the alpha order of $payload keys puts
		// wp_block before wp_global_styles / wp_navigation / wp_template* —
		// see OwnedPostsCapturer's ksort). Used to rewrite
		// `wp:block {"ref":N}` references in the content of subsequent
		// owned-post types.
		$wp_block_id_remap = array();

		foreach ( $payload as $post_type => $by_slug ) {
			if ( ! is_string( $post_type ) || ! is_array( $by_slug ) ) {
				continue;
			}
			foreach ( $by_slug as $slug => $entry ) {
				if ( ! is_string( $slug ) || '' === $slug || ! is_array( $entry ) ) {
					continue;
				}
				$post_content = (string) ( $entry['post_content'] ?? '' );
				if ( ! empty( $attachment_id_remap ) && '' !== $post_content ) {
					$post_content = $this->rewrite_attachment_ids_in_content( $post_content, $attachment_id_remap );
				}

				// wp_navigation: rewrite local-page IDs inside
				// wp:navigation-link / wp:navigation-submenu blocks via
				// the captured slug → local-id lookup. Done after the
				// attachment rewrite because the two are disjoint: nav
				// page IDs never appear in $attachment_id_remap, and
				// attachment IDs never appear in $page_id_remap (built
				// here from entry['block_refs']).
				if ( 'wp_navigation' === $post_type && '' !== $post_content ) {
					$block_refs    = is_array( $entry['block_refs'] ?? null ) ? (array) $entry['block_refs'] : array();
					$page_id_remap = $this->resolve_block_refs_to_local_ids( $block_refs );
					if ( ! empty( $page_id_remap ) ) {
						$post_content = $this->rewrite_navigation_link_ids_in_content( $post_content, $page_id_remap );
					}
				}

				// `wp:block {"ref":N}` refs: remap to local wp_block IDs.
				// wp_block entries themselves are NOT rewritten here (they
				// don't typically self-reference; if a synced pattern ever
				// contains a `wp:block` referencing another synced pattern,
				// the inner ref would dangle on apply — accept as a
				// follow-up if a real use case surfaces).
				if ( 'wp_block' !== $post_type && '' !== $post_content && ! empty( $wp_block_id_remap ) ) {
					$post_content = $this->rewrite_block_ref_ids_in_content( $post_content, $wp_block_id_remap );
				}

				$fields = array(
					'post_title'   => (string) ( $entry['post_title'] ?? '' ),
					'post_content' => $post_content,
					'post_status'  => (string) ( $entry['post_status'] ?? 'publish' ),
					'post_excerpt' => (string) ( $entry['post_excerpt'] ?? '' ),
				);
				$meta   = is_array( $entry['meta'] ?? null ) ? (array) $entry['meta'] : array();
				$terms  = is_array( $entry['terms'] ?? null ) ? (array) $entry['terms'] : array();

				$existing_id = ( $this->owned_finder )( $post_type, $slug, $terms );
				if ( null !== $existing_id ) {
					( $this->owned_updater )( (int) $existing_id, $fields, $meta, $terms );
					$local_id = (int) $existing_id;
				} else {
					$local_id = (int) ( $this->owned_inserter )( $post_type, $slug, $fields, $meta, $terms );
				}

				// For wp_block entries, stash captured_id → local_id so
				// subsequent owned-post entries get their `wp:block`
				// refs rewritten.
				if ( 'wp_block' === $post_type ) {
					$captured_id = (int) ( $entry['captured_id'] ?? 0 );
					if ( $captured_id > 0 && $local_id > 0 ) {
						$wp_block_id_remap[ $captured_id ] = $local_id;
					}
				}
			}
		}
	}

	/**
	 * Rewrite the `ref` attr inside every `wp:block` block-delimiter
	 * line in $content, using the captured-id → local-id map built
	 * from wp_block upserts.
	 *
	 * Scope: only the JSON attrs in `wp:block` block-comment delimiters
	 * are touched. Other blocks (`wp:image`, `wp:navigation-link`,
	 * etc.) have their own remap passes. Numeric values outside this
	 * regex are unaffected.
	 *
	 * @param array<int, int> $wp_block_id_remap captured_id => local_id
	 */
	private function rewrite_block_ref_ids_in_content( string $content, array $wp_block_id_remap ): string {
		if ( empty( $wp_block_id_remap ) ) {
			return $content;
		}
		return (string) preg_replace_callback(
			'/(<!-- wp:block\s+)(\{[^}]+\})(\s*\/?-->)/',
			static function ( array $outer_m ) use ( $wp_block_id_remap ): string {
				$rewritten = (string) preg_replace_callback(
					'/("ref"\s*:\s*)(\d+)/',
					static function ( array $m ) use ( $wp_block_id_remap ): string {
						$captured = (int) $m[2];
						return isset( $wp_block_id_remap[ $captured ] )
							? $m[1] . (string) $wp_block_id_remap[ $captured ]
							: $m[0];
					},
					$outer_m[2]
				);
				return $outer_m[1] . $rewritten . $outer_m[3];
			},
			$content
		);
	}

	/**
	 * Walk the captured `block_refs` map for a wp_navigation entry and
	 * resolve each captured ID to a local post ID via `page_finder`
	 * (slug + type lookup). Captured refs that don't resolve on the
	 * target are omitted; the original `id` attr in the markup stays
	 * untouched + an error_log warning surfaces the gap.
	 *
	 * @param array<int|string, array{slug?: string, type?: string}> $block_refs
	 * @return array<int, int> captured_id => local_id
	 */
	private function resolve_block_refs_to_local_ids( array $block_refs ): array {
		$out = array();
		foreach ( $block_refs as $captured_id => $ref ) {
			$captured_id = (int) $captured_id;
			if ( $captured_id <= 0 || ! is_array( $ref ) ) {
				continue;
			}
			$slug = (string) ( $ref['slug'] ?? '' );
			$type = (string) ( $ref['type'] ?? '' );
			if ( '' === $slug || '' === $type ) {
				continue;
			}
			$local_id = ( $this->page_finder )( $slug, $type );
			if ( null === $local_id ) {
				error_log(
					sprintf(
						"fp apply: wp_navigation block ref slug '%s' (post_type '%s', captured ID %d) not found on target — leaving the link's id attr untouched. The URL search-replace will still rewrite the href; the post-type ref will dangle until the target has a matching slug.",
						$slug,
						$type,
						$captured_id
					)
				);
				continue;
			}
			$out[ $captured_id ] = (int) $local_id;
		}
		return $out;
	}

	/**
	 * Rewrite the `id` attr inside every `wp:navigation-link` and
	 * `wp:navigation-submenu` block-delimiter line in $content, using
	 * the captured-id → local-id map.
	 *
	 * Scope: only the JSON attrs in the navigation-link / submenu
	 * block-comment delimiters are touched. Other blocks in the same
	 * post (image, group, etc.) are unaffected because the outer
	 * regex anchors on the `wp:navigation-(link|submenu)` block name.
	 *
	 * Regex caveat: the outer regex matches a single `{...}` JSON
	 * object without nested braces. Nav-link attrs never contain
	 * nested objects in practice (kind/type/url/id/label are all
	 * scalar), so this is safe for current block shapes. If a future
	 * core change introduces nested attrs (e.g. an icon object),
	 * upgrade to a parse_blocks-based walk.
	 *
	 * @param array<int, int> $page_id_remap captured_id => local_id
	 */
	private function rewrite_navigation_link_ids_in_content( string $content, array $page_id_remap ): string {
		if ( empty( $page_id_remap ) ) {
			return $content;
		}
		return (string) preg_replace_callback(
			'/(<!-- wp:navigation-(?:link|submenu)\s+)(\{[^}]+\})(\s*\/?-->)/',
			static function ( array $outer_m ) use ( $page_id_remap ): string {
				$rewritten = (string) preg_replace_callback(
					'/("id"\s*:\s*)(\d+)/',
					static function ( array $m ) use ( $page_id_remap ): string {
						$captured = (int) $m[2];
						return isset( $page_id_remap[ $captured ] )
							? $m[1] . (string) $page_id_remap[ $captured ]
							: $m[0];
					},
					$outer_m[2]
				);
				return $outer_m[1] . $rewritten . $outer_m[3];
			},
			$content
		);
	}

	/**
	 * Block-attribute names that carry a single attachment ID as their
	 * value. `wp:image` / `wp:cover` / `wp:video` / `wp:audio` / `wp:file`
	 * use `id`; `wp:media-text` uses `mediaId`. Add new attr names here
	 * as new core blocks (or plugin-shipped blocks) gain attachment refs.
	 */
	private const ATTACHMENT_ID_ATTRS_SCALAR = array( 'id', 'mediaId' );

	/**
	 * Block-attribute names that carry an ARRAY of attachment IDs. The
	 * legacy `wp:gallery` block uses `ids`; modern `wp:gallery` uses
	 * inner `wp:image` blocks (covered by the scalar `id` rewrite).
	 */
	private const ATTACHMENT_ID_ATTRS_ARRAY = array( 'ids' );

	/**
	 * Rewrite JSON block-attribute references to captured attachment IDs
	 * so they point at the local attachment post IDs in $remap.
	 *
	 * Covered surfaces:
	 *   - Scalar attrs: `"id":42`, `"mediaId":42` (configurable list).
	 *   - Array attrs:  `"ids":[1,2,3]`            (configurable list).
	 *   - CSS class:    `wp-image-42`              (rendered img markup).
	 *
	 * Only rewrites integers that are KEYS in $remap. Other numeric
	 * values (e.g. wp:navigation-link block-attr `"id"` pointing at a
	 * page) stay untouched because their IDs are never in the
	 * attachment-id remap. That property is what lets us safely run a
	 * generic "any int after `"id"`" regex without false rewrites.
	 *
	 * Implementation: regex per attr name. Faster than a parse_blocks /
	 * serialize_blocks round-trip and matches the existing style here.
	 * If a future block surfaces a new attachment-ID attr shape that
	 * can't be expressed as a simple "<attr>":<value> regex (e.g.
	 * nested under another attr), consider switching to a parse_blocks
	 * walk that mutates attrs structurally.
	 *
	 * @param array<int, int> $remap captured_id => local_id
	 */
	private function rewrite_attachment_ids_in_content( string $content, array $remap ): string {
		if ( empty( $remap ) ) {
			return $content;
		}

		foreach ( self::ATTACHMENT_ID_ATTRS_SCALAR as $attr ) {
			$pattern = '/("' . preg_quote( $attr, '/' ) . '"\s*:\s*)(\d+)/';
			$content = (string) preg_replace_callback(
				$pattern,
				static function ( array $m ) use ( $remap ): string {
					$captured = (int) $m[2];
					return isset( $remap[ $captured ] ) ? $m[1] . (string) $remap[ $captured ] : $m[0];
				},
				$content
			);
		}

		foreach ( self::ATTACHMENT_ID_ATTRS_ARRAY as $attr ) {
			$pattern = '/("' . preg_quote( $attr, '/' ) . '"\s*:\s*\[)([^\]]*)(\])/';
			$content = (string) preg_replace_callback(
				$pattern,
				static function ( array $m ) use ( $remap ): string {
					$inner = (string) preg_replace_callback(
						'/\d+/',
						static function ( array $mm ) use ( $remap ): string {
							$captured = (int) $mm[0];
							return isset( $remap[ $captured ] ) ? (string) $remap[ $captured ] : $mm[0];
						},
						$m[2]
					);
					return $m[1] . $inner . $m[3];
				},
				$content
			);
		}

		// `class="... wp-image-42 ..."` (img tag CSS class).
		$content = (string) preg_replace_callback(
			'/wp-image-(\d+)/',
			static function ( array $m ) use ( $remap ): string {
				$captured = (int) $m[1];
				return isset( $remap[ $captured ] ) ? 'wp-image-' . (string) $remap[ $captured ] : $m[0];
			},
			$content
		);
		return $content;
	}

	/**
	 * Apply the attachments.json sidecar — upsert each captured
	 * attachment by `_wp_attached_file` (relative path under uploads,
	 * stable across environments), copy its binary files into
	 * `wp_upload_dir()` (S3UploadsBootstrap's stream wrapper handles
	 * the S3 write), and return a captured-ID → local-ID remap that
	 * the options stage uses to rewrite `site_logo` etc.
	 *
	 * Returns the remap so {@see apply_options()} can rewrite values
	 * for `option_keys_attachment_refs` to point at the local
	 * attachment post ID rather than the designer's captured ID.
	 *
	 * @return array<int, int> captured_id => local_id
	 */
	private function apply_attachments(): array {
		$remap = array();

		$path = $this->snapshot_dir . '/attachments.json';
		if ( ! is_file( $path ) ) {
			return $remap;
		}
		$raw = file_get_contents( $path );
		if ( false === $raw || '' === $raw ) {
			return $remap;
		}
		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) ) {
			return $remap;
		}

		$by_file = is_array( $payload['by_file'] ?? null ) ? (array) $payload['by_file'] : array();
		foreach ( $by_file as $rel_file => $entry ) {
			if ( ! is_string( $rel_file ) || '' === $rel_file || ! is_array( $entry ) ) {
				continue;
			}
			$fields = array(
				'post_title'     => (string) ( $entry['post_title'] ?? '' ),
				'post_excerpt'   => (string) ( $entry['post_excerpt'] ?? '' ),
				'post_content'   => (string) ( $entry['post_content'] ?? '' ),
				'post_status'    => (string) ( $entry['post_status'] ?? 'inherit' ),
				'post_mime_type' => (string) ( $entry['post_mime_type'] ?? '' ),
			);
			$meta   = is_array( $entry['meta'] ?? null ) ? (array) $entry['meta'] : array();

			$existing_id = ( $this->att_finder )( $rel_file );
			if ( null !== $existing_id ) {
				( $this->att_updater )( (int) $existing_id, $fields, $meta );
				$local_id = (int) $existing_id;
			} else {
				$local_id = ( $this->att_inserter )( $fields, $meta );
			}

			$captured_id = (int) ( $entry['captured_id'] ?? 0 );
			if ( $captured_id > 0 ) {
				$remap[ $captured_id ] = $local_id;
			}

			$this->copy_binaries( (array) ( $entry['files'] ?? array() ) );
		}

		return $remap;
	}

	/**
	 * Copy each file from `$snapshot_dir/uploads/<rel>` into
	 * `$uploads_basedir/<rel>`. When S3UploadsBootstrap is active
	 * the basedir is an `s3://` stream wrapper path, so this
	 * transparently writes to S3.
	 *
	 * @param array<int, string> $rel_files
	 */
	private function copy_binaries( array $rel_files ): void {
		foreach ( $rel_files as $rel ) {
			$rel = (string) $rel;
			if ( '' === $rel ) {
				continue;
			}
			$src = $this->snapshot_dir . '/uploads/' . $rel;
			if ( ! is_file( $src ) ) {
				// Snapshot was captured without the binary on disk;
				// skip silently. The attachment post still landed
				// (so option_remap works) but the URL will 404 until
				// the file is uploaded out-of-band.
				continue;
			}
			$dest = $this->uploads_basedir . '/' . $rel;
			// Ensure the dest directory exists. With the s3-uploads
			// stream wrapper, mkdir is mostly a no-op (S3 has no
			// directories) — set_error_handler keeps any warning out
			// of the apply log without using `@`.
			$dest_dir = dirname( $dest );
			if ( ! is_dir( $dest_dir ) ) {
				set_error_handler( static fn (): bool => true );
				mkdir( $dest_dir, 0755, true );
				restore_error_handler();
			}
			set_error_handler( static fn (): bool => true );
			$ok = copy( $src, $dest );
			restore_error_handler();
			if ( ! $ok ) {
				throw new RuntimeException( "apply: copy binary failed {$src} → {$dest}" );
			}
		}
	}

	/**
	 * Apply the options.json sidecar — `update_option` for each entry,
	 * `set_theme_mod` for each theme_mods entry.
	 *
	 * Two remap passes are layered on top of the raw values:
	 *
	 *   1. Attachment-ref remap: options listed in
	 *      `attachments.json.option_ref_to_file` (site_logo etc.) have
	 *      their captured attachment ID swapped for the local one via
	 *      `$attachment_id_remap`. Without this, site_logo would point
	 *      at the designer's stack's attachment ID, which is rarely
	 *      the local ID.
	 *
	 *   2. Page-ref remap: options listed in
	 *      `options.json.option_page_refs` (page_on_front etc.) are
	 *      resolved by slug via the `page_finder` seam — `get_page_by_path()`
	 *      in production. When the slug isn't found on the target, the
	 *      option write is SKIPPED (not stamped with a dangling captured
	 *      ID). A loud `error_log` warning surfaces the gap so a designer
	 *      can either pick a slug that exists on prod or have an editor
	 *      create the page first.
	 *
	 * @param array<int, int> $attachment_id_remap captured_id => local_id
	 */
	private function apply_options( array $attachment_id_remap = array() ): void {
		$path = $this->snapshot_dir . '/options.json';
		if ( ! is_file( $path ) ) {
			return;
		}
		$raw = file_get_contents( $path );
		if ( false === $raw || '' === $raw ) {
			return;
		}
		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) ) {
			return;
		}

		// Apply the captured-ID → local-ID remap to attachment-
		// referenced option values. The manifest's scope.option_keys_attachment_refs
		// is the canonical list, but here we just look up the captured
		// option value: if it's an integer we have a mapping for, swap it.
		$options            = (array) ( $payload['options'] ?? array() );
		$option_page_refs   = is_array( $payload['option_page_refs'] ?? null ) ? (array) $payload['option_page_refs'] : array();
		$remap_meta_path    = $this->snapshot_dir . '/attachments.json';
		$option_ref_to_file = array();
		if ( is_file( $remap_meta_path ) ) {
			$att_raw            = (string) file_get_contents( $remap_meta_path );
			$att                = json_decode( $att_raw, true );
			$option_ref_to_file = is_array( $att['option_ref_to_file'] ?? null ) ? (array) $att['option_ref_to_file'] : array();
		}

		foreach ( $options as $key => $value ) {
			$key = (string) $key;

			if ( isset( $option_page_refs[ $key ] ) && is_array( $option_page_refs[ $key ] ) ) {
				$slug = (string) ( $option_page_refs[ $key ]['slug'] ?? '' );
				$type = (string) ( $option_page_refs[ $key ]['type'] ?? '' );
				if ( '' === $slug || '' === $type ) {
					// Malformed page ref — fall through to the literal value.
				} else {
					$local_id = ( $this->page_finder )( $slug, $type );
					if ( null === $local_id ) {
						error_log(
							sprintf(
								"fp apply: option '%s' page ref slug '%s' (post_type '%s') not found on target — skipping option write to avoid stamping a dangling ID. Create the page on the target (or pick a slug that exists) before re-applying.",
								$key,
								$slug,
								$type
							)
						);
						continue;
					}
					$value = (string) $local_id;
					( $this->option_writer )( $key, $value, true );
					continue;
				}
			}

			if ( isset( $option_ref_to_file[ $key ] ) ) {
				$captured = (int) $value;
				if ( $captured > 0 && isset( $attachment_id_remap[ $captured ] ) ) {
					$value = (string) $attachment_id_remap[ $captured ];
				}
			}
			( $this->option_writer )( $key, $value, true );
		}

		$theme_mods = (array) ( $payload['theme_mods'] ?? array() );
		foreach ( $theme_mods as $stylesheet => $mods ) {
			if ( ! is_array( $mods ) ) {
				continue;
			}
			foreach ( $mods as $key => $value ) {
				( $this->theme_mod_set )( (string) $stylesheet, (string) $key, $value );
			}
		}
	}

	private function retarget_urls( string $source_url, string $target_url ): void {
		( $this->wp_runner )(
			sprintf(
				'search-replace %s %s --all-tables --report-changed-only',
				escapeshellarg( $source_url ),
				escapeshellarg( $target_url )
			),
			array()
		);
	}

	/**
	 * Dispatch the single adapter named in the manifest. If the named
	 * adapter isn't registered in this build, throw — the v3 design
	 * forbids silent skip (the v2 behaviour was a footgun: snapshots
	 * could LOOK applied while their adapter post_apply step was
	 * silently no-op'd against a build that had since dropped the
	 * adapter).
	 *
	 * @param array<string, mixed> $adapter_state
	 */
	private function fire_adapter( string $adapter_name, array $adapter_state ): void {
		if ( '' === $adapter_name ) {
			throw new RuntimeException( 'manifest is missing required field `adapter`' );
		}
		foreach ( $this->adapters as $adapter ) {
			if ( $adapter->name() === $adapter_name ) {
				$adapter->post_apply( $adapter_state );
				return;
			}
		}
		throw new RuntimeException(
			"manifest names adapter '{$adapter_name}' but no adapter with that name is registered in this build"
		);
	}
}
