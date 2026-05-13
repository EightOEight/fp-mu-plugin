<?php
/**
 * Unit tests for FrankenPress\Cli\Snapshot\DriftLinter.
 *
 * @package FrankenPress\Tests
 */

declare(strict_types=1);

namespace FrankenPress\Tests;

use FrankenPress\Cli\Snapshot\DriftLinter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CliDriftLinterTest extends TestCase {

	public function test_no_op_when_all_active_plugins_composer_installed(): void {
		$linter = $this->linter(
			array(
				'plugins' => array( 'wordpress-importer', 'classic-editor' ),
				'themes'  => array( 'twentytwentyfive' ),
			),
			array(
				'plugins' => array( 'wordpress-importer', 'classic-editor' ),
				'theme'   => 'twentytwentyfive',
			)
		);
		// Should not throw.
		$linter->check();
		$this->assertTrue( true );
	}

	public function test_no_op_when_active_theme_is_site_tracked_only(): void {
		// Phase 3 motivating case: child theme committed at
		// web/app/themes/sts-design/, not composer-installed.
		// Should NOT trip the drift linter.
		$linter = $this->linter(
			array(
				'plugins' => array(),
				'themes'  => array( 'twentytwentyfive' ), // composer-installed parent
			),
			array(
				'plugins' => array(),
				'theme'   => 'sts-design', // active child — site-tracked, NOT composer
			),
			array(
				'plugins' => array(),
				'themes'  => array( 'twentytwentyfive', 'sts-design' ),
			)
		);
		$linter->check();
		$this->assertTrue( true );
	}

	public function test_no_op_when_active_plugin_is_site_tracked_only(): void {
		// A custom plugin committed into web/app/plugins/<slug>/ in
		// the site repo, not composer-installed. Acceptable —
		// the Dockerfile COPYs web/app/ so the plugin is in the image.
		$linter = $this->linter(
			array(
				'plugins' => array( 'wordpress-importer' ),
				'themes'  => array( 'twentytwentyfive' ),
			),
			array(
				'plugins' => array( 'wordpress-importer', 'sts-custom-blocks' ),
				'theme'   => 'twentytwentyfive',
			),
			array(
				'plugins' => array( 'sts-custom-blocks' ),
				'themes'  => array(),
			)
		);
		$linter->check();
		$this->assertTrue( true );
	}

	public function test_throws_when_active_plugin_not_composer_installed(): void {
		$linter = $this->linter(
			array(
				'plugins' => array( 'wordpress-importer' ),
				'themes'  => array( 'twentytwentyfive' ),
			),
			array(
				'plugins' => array( 'wordpress-importer', 'gutenberg' ), // gutenberg active but not composer
				'theme'   => 'twentytwentyfive',
			)
		);
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( "/plugin 'gutenberg'/" );
		$linter->check();
	}

	public function test_throws_when_active_theme_not_composer_installed(): void {
		$linter = $this->linter(
			array(
				'plugins' => array(),
				'themes'  => array( 'twentytwentyfive' ),
			),
			array(
				'plugins' => array(),
				'theme'   => 'dt-the7', // active but not composer
			)
		);
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( "/theme 'dt-the7'/" );
		$linter->check();
	}

	public function test_lists_multiple_drifted_plugins_in_single_throw(): void {
		$linter = $this->linter(
			array(
				'plugins' => array( 'wordpress-importer' ),
				'themes'  => array( 'twentytwentyfive' ),
			),
			array(
				'plugins' => array( 'wordpress-importer', 'gutenberg', 'jetpack' ),
				'theme'   => 'twentytwentyfive',
			)
		);
		try {
			$linter->check();
			$this->fail( 'expected RuntimeException' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( "plugin 'gutenberg'", $e->getMessage() );
			$this->assertStringContainsString( "plugin 'jetpack'", $e->getMessage() );
		}
	}

	public function test_throws_for_combined_plugin_and_theme_drift(): void {
		$linter = $this->linter(
			array(
				'plugins' => array(),
				'themes'  => array(),
			),
			array(
				'plugins' => array( 'gutenberg' ),
				'theme'   => 'dt-the7',
			)
		);
		try {
			$linter->check();
			$this->fail( 'expected RuntimeException' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( "plugin 'gutenberg'", $e->getMessage() );
			$this->assertStringContainsString( "theme 'dt-the7'", $e->getMessage() );
		}
	}

	public function test_no_op_when_no_active_theme(): void {
		// Some test fixtures might have no stylesheet set; the linter
		// should treat empty as "no drift to report on the theme axis".
		$linter = $this->linter(
			array(
				'plugins' => array(),
				'themes'  => array(),
			),
			array(
				'plugins' => array(),
				'theme'   => '',
			)
		);
		$linter->check();
		$this->assertTrue( true );
	}

	public function test_error_message_includes_actionable_remediation(): void {
		// The thrown message should tell the designer what to do —
		// either composer require, commit to site repo, OR deactivate —
		// for each drifted item.
		$linter = $this->linter(
			array(
				'plugins' => array(),
				'themes'  => array(),
			),
			array(
				'plugins' => array( 'fancy-blocks' ),
				'theme'   => '',
			)
		);
		try {
			$linter->check();
			$this->fail( 'expected RuntimeException' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'composer require', $e->getMessage() );
			$this->assertStringContainsString( 'site repo', $e->getMessage() );
			$this->assertStringContainsString( 'wp plugin deactivate', $e->getMessage() );
		}
	}

	/**
	 * @param array{plugins: array<int, string>, themes: array<int, string>} $composer
	 * @param array{plugins: array<int, string>, theme: string}              $active
	 * @param array{plugins: array<int, string>, themes: array<int, string>} $site_tracked
	 */
	private function linter( array $composer, array $active, array $site_tracked = array(
		'plugins' => array(),
		'themes'  => array(),
	) ): DriftLinter {
		return new DriftLinter(
			static fn (): array => $composer,
			static fn (): array => $active,
			static fn (): array => $site_tracked,
		);
	}
}
