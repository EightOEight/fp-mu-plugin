<?php
/**
 * Snapshot adapter contract for `wp fp snapshot` / `wp fp apply`.
 *
 * Each implementation represents a specific design-surface (currently
 * FSE; historically premium-theme adapters like The7 lived here too)
 * and tells fp two things:
 *
 *   1. What rows it considers in-scope for a snapshot (via
 *      {@see scope()}), which is the safety boundary — anything not in
 *      the active adapter's scope can never enter a snapshot, can never
 *      be overwritten by an apply, can never be deleted by an apply.
 *
 *   2. How to fix up post-import state (via {@see post_apply()}),
 *      e.g. theme-orphan cleanup that needs to fire AFTER WP-Importer
 *      + scoped-options-apply finishes.
 *
 * Plus capture-side state (via {@see capture_state()}) for any
 * adapter-specific bookkeeping that should ride along in the manifest
 * but isn't a normal DB row (e.g. the source-theme slug captured at
 * snapshot time so post_apply knows which `wp_global_styles` rows are
 * orphans after a theme switch).
 *
 * @package FrankenPress\Cli\Adapters
 */

declare(strict_types=1);

namespace FrankenPress\Cli\Adapters;

use FrankenPress\Cli\Snapshot\SnapshotScope;

interface AdapterInterface {

	/**
	 * Stable identifier surfaced into `manifest.adapter`. Must be a
	 * lowercase slug. Apply-side dispatch looks up the registered
	 * adapter by this string.
	 */
	public function name(): string;

	/**
	 * Returns true if this adapter is relevant to the current site
	 * (e.g. the site is in FSE mode). The capture path only calls
	 * `scope()` / `capture_state()` on adapters that detect positively;
	 * the apply path only fires `post_apply()` when the manifest's
	 * `adapter` field matches.
	 */
	public function detect(): bool;

	/**
	 * Declares which rows this adapter considers in-scope for a
	 * snapshot. The capture path runs `wp export` + an options dump
	 * against the scope's footprint.
	 */
	public function scope(): SnapshotScope;

	/**
	 * Returns adapter-specific bookkeeping data captured at snapshot
	 * time. Embedded into `manifest.adapter_state`.
	 *
	 * @return array<string, mixed>
	 */
	public function capture_state(): array;

	/**
	 * Apply-side hook: runs AFTER the WP-Importer has imported the
	 * WXR + the scoped options have been applied. Use for orphan
	 * cleanup of snapshot-managed metadata (e.g. removing
	 * `wp_global_styles` rows belonging to a previous active theme)
	 * or other "fix up the environment now that the data is in" tasks.
	 *
	 * IMPORTANT: post_apply may delete rows of post types in the
	 * adapter's scope (those are snapshot-managed metadata, owned by
	 * this adapter). It must NEVER touch rows outside scope — that's
	 * UGC and the "no DROPs of user-generated content" safety property
	 * applies.
	 *
	 * @param array<string, mixed> $state The `adapter_state` blob from
	 *     the manifest — whatever this adapter's `capture_state()`
	 *     returned at snapshot time.
	 */
	public function post_apply( array $state ): void;
}
