<?php
/**
 * Unit tests for FrankenPress\Cli\Snapshot\PluginInspector.
 *
 * @package FrankenPress\Tests
 */

declare(strict_types=1);

namespace FrankenPress\Tests;

use FrankenPress\Cli\Snapshot\PluginInspector;
use PHPUnit\Framework\TestCase;

final class CliPluginInspectorTest extends TestCase {

	private string $workspace;

	protected function setUp(): void {
		parent::setUp();
		$this->workspace = sys_get_temp_dir() . '/fp-plugin-inspector-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->workspace . '/plugins', 0777, true );
		mkdir( $this->workspace . '/site', 0777, true );
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->workspace );
		parent::tearDown();
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				$this->rrmdir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	private function write_composer_json( array $requires ): void {
		file_put_contents(
			$this->workspace . '/site/composer.json',
			json_encode( array( 'require' => $requires ), JSON_PRETTY_PRINT )
		);
	}

	private function make_plugin_dir( string $slug ): void {
		mkdir( $this->workspace . '/plugins/' . $slug, 0777, true );
		file_put_contents( $this->workspace . '/plugins/' . $slug . '/' . $slug . '.php', '<?php' );
	}

	public function test_active_plugins_already_declared_yield_empty_patch(): void {
		$this->write_composer_json(
			array(
				'wpackagist-plugin/contact-form-7' => '^6.0',
			)
		);
		$this->make_plugin_dir( 'contact-form-7' );

		$patch = ( new PluginInspector(
			$this->workspace . '/plugins',
			$this->workspace . '/site/composer.json',
			array( 'contact-form-7/wp-contact-form-7.php' )
		) )->build_patch();

		$this->assertSame( array(), $patch['pending_requires'] );
		$this->assertSame( array(), $patch['unresolved'] );
	}

	public function test_undeclared_active_plugin_goes_into_pending(): void {
		$this->write_composer_json(
			array(
				'wpackagist-plugin/contact-form-7' => '^6.0',
			)
		);
		$this->make_plugin_dir( 'contact-form-7' );
		$this->make_plugin_dir( 'wordpress-seo' );

		$patch = ( new PluginInspector(
			$this->workspace . '/plugins',
			$this->workspace . '/site/composer.json',
			array(
				'contact-form-7/wp-contact-form-7.php',
				'wordpress-seo/wp-seo.php',
			)
		) )->build_patch();

		$this->assertSame( array( 'wordpress-seo' ), $patch['pending_requires'] );
		$this->assertSame( array(), $patch['unresolved'] );
	}

	public function test_active_plugin_without_disk_directory_goes_into_unresolved(): void {
		$this->write_composer_json( array() );
		// Don't create disk dir for js_composer — emulates a theme-bundled
		// plugin activated via a non-WP_PLUGIN_DIR path.
		$patch = ( new PluginInspector(
			$this->workspace . '/plugins',
			$this->workspace . '/site/composer.json',
			array( 'js_composer/js_composer.php' )
		) )->build_patch();

		$this->assertSame( array(), $patch['pending_requires'] );
		$this->assertSame( array( 'js_composer' ), $patch['unresolved'] );
	}

	public function test_top_level_single_file_plugins_are_ignored(): void {
		$this->write_composer_json( array() );

		$patch = ( new PluginInspector(
			$this->workspace . '/plugins',
			$this->workspace . '/site/composer.json',
			array( 'hello.php' )  // bare file — no directory
		) )->build_patch();

		$this->assertSame( array(), $patch['pending_requires'] );
		$this->assertSame( array(), $patch['unresolved'] );
	}

	public function test_results_are_sorted_alphabetically(): void {
		$this->write_composer_json( array() );
		$this->make_plugin_dir( 'zebra' );
		$this->make_plugin_dir( 'apple' );
		$this->make_plugin_dir( 'mango' );

		$patch = ( new PluginInspector(
			$this->workspace . '/plugins',
			$this->workspace . '/site/composer.json',
			array(
				'zebra/zebra.php',
				'apple/apple.php',
				'mango/mango.php',
			)
		) )->build_patch();

		$this->assertSame( array( 'apple', 'mango', 'zebra' ), $patch['pending_requires'] );
	}

	public function test_missing_composer_json_treats_everything_as_pending(): void {
		$this->make_plugin_dir( 'akismet' );

		$patch = ( new PluginInspector(
			$this->workspace . '/plugins',
			$this->workspace . '/site/does-not-exist.json',
			array( 'akismet/akismet.php' )
		) )->build_patch();

		$this->assertSame( array( 'akismet' ), $patch['pending_requires'] );
	}

	public function test_composer_json_without_wpackagist_requires_still_works(): void {
		$this->write_composer_json(
			array(
				'php'                    => '^8.2',
				'composer/installers'    => '^2.0',
				'frankenpress/mu-plugin' => '^0.7.0',
				// non-wpackagist plugin (committed direct under web/app/plugins)
				'roots/wordpress'        => '^6.8',
			)
		);
		$this->make_plugin_dir( 'akismet' );

		$patch = ( new PluginInspector(
			$this->workspace . '/plugins',
			$this->workspace . '/site/composer.json',
			array( 'akismet/akismet.php' )
		) )->build_patch();

		$this->assertSame( array( 'akismet' ), $patch['pending_requires'] );
	}

	public function test_duplicate_slugs_in_active_plugins_collapse(): void {
		// `active_plugins` is supposed to be unique but defensively
		// handle duplicates.
		$this->write_composer_json( array() );
		$this->make_plugin_dir( 'akismet' );

		$patch = ( new PluginInspector(
			$this->workspace . '/plugins',
			$this->workspace . '/site/composer.json',
			array(
				'akismet/akismet.php',
				'akismet/akismet.php',
			)
		) )->build_patch();

		$this->assertSame( array( 'akismet' ), $patch['pending_requires'] );
	}
}
