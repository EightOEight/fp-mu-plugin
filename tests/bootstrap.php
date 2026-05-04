<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads composer's autoloader and Brain Monkey for WordPress hook
 * mocking. WordPress core itself is not loaded — these are unit tests
 * for components in isolation.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/fake-abspath/' );
}

// Silence error_log() output during tests — components log connection
// errors and refused-uploads warnings as part of their happy path, but
// in a unit-test runner that just creates noise.
ini_set( 'error_log', '/dev/null' );
