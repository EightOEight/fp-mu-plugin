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
