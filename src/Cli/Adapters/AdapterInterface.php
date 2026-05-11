<?php
/**
 * Premium-theme adapter contract for `wp fp snapshot` / `wp fp apply`.
 *
 * Each implementation represents a specific theme (or builder, or
 * premium content plugin) and tells fp two things:
 *
 *   1. What rows it considers in-scope for a snapshot (via
 *      {@see scope()}), which is the safety boundary — anything not
 *      in any adapter's scope can never enter a snapshot, can never
 *      be overwritten by a promote, can never be deleted by an apply.
 *
 *   2. How to fix up post-import state (via {@see post_apply()}),
 *      e.g. theme-specific cache rebuilds that need to fire AFTER
 *      WP-Importer + scoped-options-apply finishes.
 *
 * Plus capture-side state (via {@see capture_state()}) for any
 * adapter-specific bookkeeping that should ride along in the manifest
 * but isn't a normal DB row — flag options like The7's
 * `the7_demo_history` that mark "this demo has been imported".
 *
 * @package FrankenPress\Cli\Adapters
 */

declare(strict_types=1);

namespace FrankenPress\Cli\Adapters;

use FrankenPress\Cli\Snapshot\SnapshotScope;

interface AdapterInterface {

	/**
	 * Stable identifier surfaced into `manifest.adapters_fired`. Must
	 * be a lowercase slug. Apply-side dispatch looks adapters up by
	 * this string.
	 */
	public function name(): string;

	/**
	 * Returns true if this adapter is relevant to the current site
	 * (e.g. the theme is active). The capture path only consults
	 * `scope()` / `capture_state()` on adapters that detect positively;
	 * the apply path only fires `post_apply()` for adapters listed in
	 * `manifest.adapters_fired`.
	 */
	public function detect(): bool;

	/**
	 * Declares which rows this adapter considers in-scope for a
	 * snapshot. The capture path runs `wp export` + an options dump
	 * against the union of every fired adapter's scope.
	 */
	public function scope(): SnapshotScope;

	/**
	 * Returns adapter-specific bookkeeping data captured at snapshot
	 * time. Embedded into `manifest.adapter_state[name]`.
	 *
	 * @return array<string, mixed>
	 */
	public function capture_state(): array;

	/**
	 * Apply-side hook: runs AFTER the WP-Importer has imported the
	 * WXR + the scoped options have been applied. Use for cache
	 * rebuilds, marker option writes, or other "fix up the
	 * environment now that the data is in" tasks.
	 *
	 * @param array<string, mixed> $state The `adapter_state[name]`
	 *     blob from the manifest — whatever this adapter's
	 *     `capture_state()` returned at snapshot time.
	 */
	public function post_apply( array $state ): void;
}
