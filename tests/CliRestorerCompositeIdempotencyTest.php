<?php
/**
 * Unit tests for the v0.14.0 composite-idempotency-hash addition to
 * FrankenPress\Cli\Apply\Restorer.
 *
 * Two methods under test:
 *
 *   compute_composite_sha256(array $manifest): string
 *   already_applied(string $id, string $wxr_sha, string $composite_sha): bool
 *
 * Reached via reflection. Construct a Restorer with no-op closures
 * except option_reader (set per-test to simulate the DB state from a
 * previous apply).
 *
 * @package FrankenPress\Tests
 */

declare(strict_types=1);

namespace FrankenPress\Tests;

use FrankenPress\Cli\Apply\Restorer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CliRestorerCompositeIdempotencyTest extends TestCase {

	public function test_compute_composite_is_deterministic_for_identical_input(): void {
		$manifest = $this->manifest( 'a', 'b', 'c', 'd' );
		$h1       = $this->compute_composite( $manifest );
		$h2       = $this->compute_composite( $manifest );
		$this->assertSame( $h1, $h2 );
		// SHA-256 hex is 64 chars.
		$this->assertSame( 64, strlen( $h1 ) );
	}

	public function test_compute_composite_changes_when_any_content_sha_changes(): void {
		$base = $this->compute_composite( $this->manifest( 'a', 'b', 'c', 'd' ) );

		$this->assertNotSame( $base, $this->compute_composite( $this->manifest( 'X', 'b', 'c', 'd' ) ), 'wxr sha change' );
		$this->assertNotSame( $base, $this->compute_composite( $this->manifest( 'a', 'X', 'c', 'd' ) ), 'templates sha change' );
		$this->assertNotSame( $base, $this->compute_composite( $this->manifest( 'a', 'b', 'X', 'd' ) ), 'options sha change' );
		$this->assertNotSame( $base, $this->compute_composite( $this->manifest( 'a', 'b', 'c', 'X' ) ), 'attachments sha change' );
	}

	public function test_already_applied_true_when_ref_and_composite_match(): void {
		$options = array(
			Restorer::APPLIED_REF_OPTION              => 'snap-2026-05-13',
			Restorer::APPLIED_COMPOSITE_SHA256_OPTION => 'composite-abc',
			Restorer::APPLIED_SHA256_OPTION           => 'wxr-irrelevant',
		);
		$this->assertTrue(
			$this->already_applied( $options, 'snap-2026-05-13', 'wxr-irrelevant', 'composite-abc' )
		);
	}

	public function test_already_applied_false_when_composite_differs(): void {
		// Same id, but the templates/options/attachments changed under
		// the same snapshot dir — the gap this fix exists to close.
		$options = array(
			Restorer::APPLIED_REF_OPTION              => 'snap-2026-05-13',
			Restorer::APPLIED_COMPOSITE_SHA256_OPTION => 'composite-OLD',
			Restorer::APPLIED_SHA256_OPTION           => 'wxr-empty',
		);
		$this->assertFalse(
			$this->already_applied( $options, 'snap-2026-05-13', 'wxr-empty', 'composite-NEW' )
		);
	}

	public function test_already_applied_false_when_ref_differs(): void {
		// Different snapshot id (e.g. a fresh release): re-apply regardless
		// of any hash comparison.
		$options = array(
			Restorer::APPLIED_REF_OPTION              => 'snap-2026-05-13',
			Restorer::APPLIED_COMPOSITE_SHA256_OPTION => 'composite-xyz',
			Restorer::APPLIED_SHA256_OPTION           => 'wxr-xyz',
		);
		$this->assertFalse(
			$this->already_applied( $options, 'snap-2026-05-14', 'wxr-xyz', 'composite-xyz' )
		);
	}

	public function test_already_applied_falls_back_to_wxr_sha_when_no_prior_composite(): void {
		// Backward-compat: site was last applied by v0.13.x (only the
		// wxr-sha marker is stamped). On first v0.14+ apply against the
		// SAME manifest, we shouldn't force a no-op re-apply.
		$options = array(
			Restorer::APPLIED_REF_OPTION              => 'snap-2026-05-13',
			Restorer::APPLIED_SHA256_OPTION           => 'wxr-abc',
			Restorer::APPLIED_COMPOSITE_SHA256_OPTION => '', // pre-composite era
		);
		$this->assertTrue(
			$this->already_applied( $options, 'snap-2026-05-13', 'wxr-abc', 'composite-fresh' )
		);
	}

	public function test_already_applied_pre_composite_false_when_wxr_sha_changed(): void {
		// Pre-composite era + the wxr_sha differs: old logic returns false.
		$options = array(
			Restorer::APPLIED_REF_OPTION              => 'snap-2026-05-13',
			Restorer::APPLIED_SHA256_OPTION           => 'wxr-OLD',
			Restorer::APPLIED_COMPOSITE_SHA256_OPTION => '',
		);
		$this->assertFalse(
			$this->already_applied( $options, 'snap-2026-05-13', 'wxr-NEW', 'composite-fresh' )
		);
	}

	public function test_already_applied_treats_null_prior_composite_as_pre_composite(): void {
		// option_reader returns null for missing options (the production
		// get_option(key, null) shape). Must be treated the same as ''.
		$options = array(
			Restorer::APPLIED_REF_OPTION              => 'snap-2026-05-13',
			Restorer::APPLIED_SHA256_OPTION           => 'wxr-abc',
			Restorer::APPLIED_COMPOSITE_SHA256_OPTION => null,
		);
		$this->assertTrue(
			$this->already_applied( $options, 'snap-2026-05-13', 'wxr-abc', 'composite-fresh' )
		);
	}

	/**
	 * @param array<string, mixed> $options option_reader stub state
	 */
	private function already_applied( array $options, string $id, string $wxr_sha, string $composite_sha ): bool {
		$restorer = $this->build_restorer( $options );
		$method   = new ReflectionMethod( Restorer::class, 'already_applied' );
		return (bool) $method->invoke( $restorer, $id, $wxr_sha, $composite_sha );
	}

	/**
	 * @param array<string, mixed> $manifest
	 */
	private function compute_composite( array $manifest ): string {
		$restorer = $this->build_restorer( array() );
		$method   = new ReflectionMethod( Restorer::class, 'compute_composite_sha256' );
		return (string) $method->invoke( $restorer, $manifest );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function manifest( string $wxr_sha, string $templates_sha, string $options_sha, string $attachments_sha ): array {
		return array(
			'contents' => array(
				'wxr_sha256'         => $wxr_sha,
				'templates_sha256'   => $templates_sha,
				'options_sha256'     => $options_sha,
				'attachments_sha256' => $attachments_sha,
			),
		);
	}

	/**
	 * @param array<string, mixed> $options
	 */
	private function build_restorer( array $options ): Restorer {
		$option_reader = static fn ( string $key ): mixed => $options[ $key ] ?? null;
		$snapshot_dir  = sys_get_temp_dir() . '/fp-composite-test-' . uniqid();
		mkdir( $snapshot_dir, 0755, true );
		// Constructor takes 15 args; we only need option_reader to behave.
		return new Restorer(
			$snapshot_dir,
			'http://target.example',
			array(),
			static fn ( string $cmd, array $assoc ): mixed => null,
			$option_reader,
			static fn ( string $key, mixed $value, bool $autoload ): bool => true,
			static function (): void {},
			static fn ( string $pt, string $slug, array $terms = array() ): ?int => null,
			static function (): void {},
			static fn (): int => 0,
			static fn ( string $rel ): ?int => null,
			static function (): void {},
			static fn (): int => 0,
			static fn ( string $slug, string $type ): ?int => null,
			'/tmp/uploads',
			'http://target.example/app/uploads',
		);
	}
}
