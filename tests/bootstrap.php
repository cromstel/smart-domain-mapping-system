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

// The WP core test bootstrap requires the Yoast PHPUnit Polyfills. The test
// suite is extracted into $_tests_dir without its own vendor/, so point core at
// the copy installed by Composer (constant = absolute path to the library root).
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	$dm_polyfill_dirs = array(
		dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills',          // Composer install at the repo root.
	);
	foreach ( $dm_polyfill_dirs as $dm_polyfill_dir ) {
		if ( file_exists( $dm_polyfill_dir . '/phpunitpolyfills-autoload.php' ) ) {
			define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $dm_polyfill_dir );
			break;
		}
	}
	unset( $dm_polyfill_dirs, $dm_polyfill_dir );
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

if ( ! function_exists( 'rest_do_remote_request' ) ) {
	/**
	 * Test helper: dispatch an internal REST request and return a
	 * wp_remote_*-shaped response array.
	 *
	 * The `dms/v1` contract tests call this instead of wp_remote_get()/wp_remote_post()
	 * because the WordPress test suite does not boot an HTTP server — requests
	 * have to be dispatched in-process through the REST server. The return shape
	 * matches what the tests assert on: $response['response']['code'] and
	 * $response['body'] (a JSON string).
	 *
	 * @param string $method HTTP method.
	 * @param string $url    A rest_url() for the route.
	 * @param array  $args   Optional 'body' (array or JSON string), 'headers', or
	 *                       bare query params.
	 * @return array{response:array{code:int,message:string},body:string,headers:array}
	 */
	function rest_do_remote_request( $method, $url, $args = array() ) {
		$method = strtoupper( (string) $method );
		$route  = _dm_route_from_url( $url );

		$request = new WP_REST_Request( $method, $route );

		if ( isset( $args['body'] ) ) {
			$body    = $args['body'];
			$decoded = is_string( $body ) ? json_decode( $body, true ) : $body;
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $key => $value ) {
					$request->set_param( $key, $value );
				}
			}
			$request->set_body( is_string( $body ) ? $body : wp_json_encode( $body ) );
			$request->set_header( 'Content-Type', 'application/json' );
		}

		foreach ( $args as $key => $value ) {
			if ( in_array( $key, array( 'body', 'headers' ), true ) ) {
				continue;
			}
			$request->set_param( $key, $value );
		}

		if ( isset( $args['headers'] ) && is_array( $args['headers'] ) ) {
			foreach ( $args['headers'] as $header => $value ) {
				$request->set_header( $header, $value );
			}
		}

		$response = rest_do_request( $request );
		$status   = $response->get_status();

		return array(
			'response' => array(
				'code'    => $status,
				'message' => get_status_header_desc( $status ),
			),
			'body'     => wp_json_encode( $response->get_data() ),
			'headers'  => $response->get_headers(),
		);
	}
}

if ( ! function_exists( '_dm_route_from_url' ) ) {
	/**
	 * Resolves a rest_url() back to a REST route path, handling both plain
	 * (?rest_route=/...) and pretty (/wp-json/...) permalink structures.
	 *
	 * @param string $url REST URL.
	 * @return string Route path.
	 */
	function _dm_route_from_url( $url ) {
		$parts = wp_parse_url( $url );

		if ( ! empty( $parts['query'] ) ) {
			$query = array();
			parse_str( $parts['query'], $query );
			if ( ! empty( $query['rest_route'] ) ) {
				return '/' . ltrim( $query['rest_route'], '/' );
			}
		}

		$path   = isset( $parts['path'] ) ? $parts['path'] : '';
		$prefix = '/' . trim( rest_get_url_prefix(), '/' ) . '/';
		$pos    = strpos( $path, $prefix );

		if ( false !== $pos ) {
			return '/' . ltrim( substr( $path, $pos + strlen( $prefix ) ), '/' );
		}

		return '/' . ltrim( $path, '/' );
	}
}
