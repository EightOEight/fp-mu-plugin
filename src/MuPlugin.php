<?php
/**
 * FrankenPress mu-plugin component bootstrapper.
 *
 * @package FrankenPress
 */

declare(strict_types=1);

namespace FrankenPress;

/**
 * Wires the platform-essential components into WordPress hooks.
 *
 * Three components, all platform-housekeeping for the FrankenPress
 * model — anything beyond this is "optional" by the platform's
 * baseline definition:
 *
 *   - {@see S3UploadsBootstrap}: configure humanmade/s3-uploads from env
 *     vars, refuse uploads if S3 isn't fully wired (vs silently writing
 *     to ephemeral local disk).
 *   - {@see SouinInvalidator}: DEL Souin's Redis cache entries on
 *     post-save / theme-switch / etc., because Souin's documented HTTP
 *     invalidation APIs are broken in cache-handler v0.16.0 (see
 *     fp-runtime/PHASE-0.md).
 *   - {@see SiteHealth}: silence Site Health tests whose failure is
 *     intentional under the immutable-image lockdown
 *     (background_updates, FS-write probes, plugin/theme auto-update
 *     probe), and add a passing FrankenPress test that explains why.
 *
 * Each component's constructor is side-effect-free; the actual hook
 * registration happens in `bootstrap()`. This makes the components
 * unit-testable in isolation.
 */
final class MuPlugin {

	public function bootstrap(): void {
		( new S3UploadsBootstrap() )->bootstrap();
		( new SouinInvalidator() )->bootstrap();
		( new SiteHealth() )->bootstrap();
	}
}
