<?php
/**
 * Plugin Name:  FrankenPress mu-plugin
 * Plugin URI:   https://github.com/EightOEight/fp-mu-plugin
 * Description:  Platform-essential WordPress glue for the FrankenPress stack: S3-uploads bootstrap, Souin cache invalidator, Site Health adjustments for the immutable-image lockdown, and SMTP mailer for wp_mail.
 * Version:      0.3.0
 * Author:       EightOEight
 * Author URI:   https://eightoeight.io
 * License:      Apache-2.0
 * License URI:  https://www.apache.org/licenses/LICENSE-2.0
 *
 * This file is the must-use bootstrapper. WordPress loads files in the
 * mu-plugins root alphabetically; everything else lives in subdirectories
 * (e.g. `fp-mu-plugin/`) so this single bootstrap is sufficient.
 *
 * @package FrankenPress
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// When composer-installed, the package lives at <mu-plugins>/fp-mu-plugin/
// and a symlink or composer-installer script places this file at the root.
$fp_mu_plugin_dir = __DIR__ . '/fp-mu-plugin';
if ( is_dir( $fp_mu_plugin_dir ) && file_exists( $fp_mu_plugin_dir . '/vendor/autoload.php' ) ) {
	require_once $fp_mu_plugin_dir . '/vendor/autoload.php';
} elseif ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

// If neither autoloader is present, fall back to a simple PSR-4 loader for
// the FrankenPress namespace. Useful when the plugin is checked into a site
// repo without a vendor/ directory.
if ( ! class_exists( \FrankenPress\MuPlugin::class ) ) {
	$fp_src = is_dir( $fp_mu_plugin_dir . '/src' ) ? $fp_mu_plugin_dir . '/src' : __DIR__ . '/src';
	if ( is_dir( $fp_src ) ) {
		spl_autoload_register(
			static function ( string $class_name ) use ( $fp_src ): void {
				if ( strpos( $class_name, 'FrankenPress\\' ) !== 0 ) {
					return;
				}
				$relative = str_replace( '\\', '/', substr( $class_name, strlen( 'FrankenPress\\' ) ) );
				$path     = $fp_src . '/' . $relative . '.php';
				if ( is_file( $path ) ) {
					require_once $path;
				}
			}
		);
	}
}

if ( class_exists( \FrankenPress\MuPlugin::class ) ) {
	( new \FrankenPress\MuPlugin() )->bootstrap();
}
