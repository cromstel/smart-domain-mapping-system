<?php
/**
 * PHPUnit bootstrap for the WordPress test suite.
 *
 * Requires the WP develop test library. Point WP_TESTS_DIR at it:
 *
 *   WP_TESTS_DIR=/path/to/wordpress-develop/tests/phpunit \
 *   WP_MULTISITE=1 vendor/bin/phpunit -c phpunit.xml.dist
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo 'Could not locate the WordPress test library at ' . $_tests_dir . PHP_EOL;
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Loads the plugin under test as an mu-plugin and creates its tables
 * (activation hooks do not fire for directly-included test plugins).
 */
function _dm_register_plugin() {
	require_once dirname( __DIR__ ) . '/domain-mapping-system.php';

	if ( class_exists( 'DMS_DB_Tables' ) ) {
		DMS_DB_Tables::create_tables();
	}
}
tests_add_filter( 'muplugins_loaded', '_dm_register_plugin' );

require $_tests_dir . '/includes/bootstrap.php';
