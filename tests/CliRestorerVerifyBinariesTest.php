<?php
/**
 * Unit tests for FrankenPress\Cli\Apply\Restorer::verify_attachment_binaries —
 * the preflight pass that catches snapshots whose attachments.json
 * declares files not actually present under <snapshot_dir>/uploads/.
 *
 * Reached via reflection.
 *
 * @package FrankenPress\Tests
 */

declare(strict_types=1);

namespace FrankenPress\Tests;

use FrankenPress\Cli\Apply\Restorer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class CliRestorerVerifyBinariesTest extends TestCase {

	private string $snapshot_dir;

	protected function setUp(): void {
		$this->snapshot_dir = sys_get_temp_dir() . '/fp-verify-binaries-test-' . uniqid();
		mkdir( $this->snapshot_dir, 0755, true );
	}

	protected function tearDown(): void {
		$this->rmrf( $this->snapshot_dir );
	}

	public function test_passes_when_attachments_json_absent(): void {
		// No attachments.json at all → nothing to verify; preflight is a no-op.
		$this->invoke();
		$this->assertTrue( true );
	}

	public function test_passes_when_all_declared_binaries_present(): void {
		$this->write_attachments(
			array(
				'by_file' => array(
					'2026/05/logo.png' => array(
						'files' => array( '2026/05/logo.png', '2026/05/logo-300x300.png' ),
					),
				),
			)
		);
		$this->write_upload( '2026/05/logo.png', 'binary-data' );
		$this->write_upload( '2026/05/logo-300x300.png', 'thumb-data' );

		$this->invoke();
		$this->assertTrue( true );
	}

	public function test_fails_with_all_missing_paths_named(): void {
		$this->write_attachments(
			array(
				'by_file' => array(
					'2026/05/logo.png'  => array(
						'files' => array( '2026/05/logo.png', '2026/05/logo-300x300.png' ),
					),
					'2026/06/cover.jpg' => array(
						'files' => array( '2026/06/cover.jpg' ),
					),
				),
			)
		);
		// Only the original logo is on disk; the thumbnail and cover.jpg are missing.
		$this->write_upload( '2026/05/logo.png', 'binary-data' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( '2026/05/logo-300x300.png' );
		$this->expectExceptionMessage( '2026/06/cover.jpg' );
		$this->expectExceptionMessage( 'snapshot is missing 2 binary file(s)' );

		$this->invoke();
	}

	public function test_passes_when_attachments_json_has_no_by_file_entries(): void {
		// Snapshot from a site with no captured attachments — by_file
		// is an empty array. Nothing to verify; do not error.
		$this->write_attachments( array( 'by_file' => array() ) );

		$this->invoke();
		$this->assertTrue( true );
	}

	public function test_skips_entries_with_blank_or_non_string_paths(): void {
		// Defensive: a malformed entry with an empty-string file path
		// should be skipped, not reported as missing.
		$this->write_attachments(
			array(
				'by_file' => array(
					'real.png' => array(
						'files' => array( '', 'real.png' ),
					),
				),
			)
		);
		$this->write_upload( 'real.png', 'data' );

		$this->invoke();
		$this->assertTrue( true );
	}

	private function invoke(): void {
		$restorer = $this->build_restorer();
		$method   = new ReflectionMethod( Restorer::class, 'verify_attachment_binaries' );
		$method->invoke( $restorer );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function write_attachments( array $payload ): void {
		file_put_contents( $this->snapshot_dir . '/attachments.json', json_encode( $payload ) );
	}

	private function write_upload( string $rel, string $content ): void {
		$path = $this->snapshot_dir . '/uploads/' . $rel;
		$dir  = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
		file_put_contents( $path, $content );
	}

	private function build_restorer(): Restorer {
		return new Restorer(
			$this->snapshot_dir,
			'http://target.example',
			array(),
			static fn ( string $cmd, array $assoc ): mixed => null,
			static fn ( string $key ): mixed => null,
			static fn ( string $key, mixed $value, bool $autoload ): bool => true,
			static function (): void {},
			static fn ( string $pt, string $slug, array $terms = array() ): ?int => null,
			static function (): void {},
			static fn (): int => 0,
			static fn ( string $rel ): ?int => null,
			static function (): void {},
			static fn (): int => 0,
			static fn ( string $slug, string $type ): ?int => null,
			static fn ( string $pt, ?string $theme ): array => array(),
			static function (): void {},
			'/tmp/uploads',
			'http://target.example/app/uploads',
		);
	}

	private function rmrf( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$entries = glob( $dir . '/*' );
		if ( false !== $entries ) {
			foreach ( $entries as $f ) {
				is_dir( $f ) ? $this->rmrf( $f ) : unlink( $f );
			}
		}
		rmdir( $dir );
	}
}
