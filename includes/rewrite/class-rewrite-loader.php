<?php
/**
 * mu-plugins drop-in: DMS Rewrite Loader.
 *
 * This file is designed to be placed in `wp-content/mu-plugins/dms-rewrite-loader.php`
 * by the plugin's activation hook. It auto-loads on every request before regular plugins.
 *
 * Responsibilities:
 *  - Load rewrite rules from cache/db.
 *  - Apply query string transformations.
 *  - Handle internal rewrites (add_rewrite_rule equivalent).
 *  - Handle external redirects (wp_redirect).
 *
 * Must be loaded very early (mu-plugins runs before regular plugins).
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prevents direct access if not installed via plugin.
 */
if ( ! defined( 'DMS_REWRITE_LOADER_VERSION' ) ) {
	define( 'DMS_REWRITE_LOADER_VERSION', '1.0.0' );
}

/**
 * Loads the rewrite engine class.
 * The class file is in the plugin directory, not mu-plugins.
 * We locate it via the plugin's main file constant if available.
 */
$dms_plugin_file = false;
if ( defined( 'DMS_PLUGIN_FILE' ) ) {
	$dms_plugin_file = DMS_PLUGIN_FILE;
} elseif ( file_exists( WP_PLUGIN_DIR . '/smart-domain-mapping-system/domain-mapping-system.php' ) ) {
	$dms_plugin_file = WP_PLUGIN_DIR . '/smart-domain-mapping-system/domain-mapping-system.php';
} elseif ( file_exists( WPMU_PLUGIN_DIR . '/smart-domain-mapping-system/domain-mapping-system.php' ) ) {
	$dms_plugin_file = WPMU_PLUGIN_DIR . '/smart-domain-mapping-system/domain-mapping-system.php';
}

if ( $dms_plugin_file && file_exists( dirname( $dms_plugin_file ) . '/includes/rewrite/class-rewrite-engine.php' ) ) {
	require_once dirname( $dms_plugin_file ) . '/includes/rewrite/class-rewrite-engine.php';
} else {
	// Plugin not found or not installed correctly — silently exit.
	return;
}

/**
 * Main rewrite handler.
 * Runs on `template_redirect` priority 1 (earliest possible).
 */
add_action( 'template_redirect', 'dms_rewrite_handle_request', 1 );

/**
 * Handles rewrite/redirect logic for mapped domains.
 *
 * @return void
 */
function dms_rewrite_handle_request() {
	// Only process single-site mode.
	if ( ! function_exists( 'DMS_Mode::is_single_site' ) || ! DMS_Mode::is_single_site() ) {
		return;
	}

	$host = dms_rewrite_get_host();
	$path = dms_rewrite_get_path();

	// Apply query string rules first (affects $_GET).
	DMS_Rewrite_Engine::apply_query_rules( $host );

	// Check for path rewrite/redirect.
	$match = DMS_Rewrite_Engine::match_path( $path, $host );
	if ( ! $match ) {
		return;
	}

	$mapping = DMS_Single_Site_Mappings::get_mapping( $match['mapping_id'] );
	if ( is_wp_error( $mapping ) ) {
		return;
	}

	$target_url = dms_rewrite_build_target_url( $mapping, $match, $_SERVER['REQUEST_URI'] );
	if ( ! $target_url ) {
		return;
	}

	if ( $match['redirect'] ) {
		// External redirect.
		wp_safe_redirect( $target_url, $match['redirect_code'] );
		exit;
	} else {
		// Internal rewrite: we can't easily do internal rewrites in WP without
		// add_rewrite_rule, so we set a global and let WP handle it.
		// For now, we'll do a redirect but this is a limitation.
		// TODO: Implement proper internal rewrite via add_rewrite_rule on init.
		wp_safe_redirect( $target_url, 302 );
		exit;
	}
}

/**
 * Gets the request host (normalized, no port).
 *
 * @return string
 */
function dms_rewrite_get_host() {
	$host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';
	if ( false !== strpos( $host, ':' ) ) {
		$host = substr( $host, 0, strpos( $host, ':' ) );
	}
	return strtolower( $host );
}

/**
 * Gets the request path (normalized, no leading/trailing slash, no query).
 *
 * @return string
 */
function dms_rewrite_get_path() {
	$path = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
	if ( false !== strpos( $path, '?' ) ) {
		$path = substr( $path, 0, strpos( $path, '?' ) );
	}
	$path = trim( $path, '/' );
	return strtolower( $path );
}

/**
 * Builds the target URL for a rewrite/redirect match.
 *
 * @param array  $mapping    Mapping array.
 * @param array  $match      Matched rule.
 * @param string $request_uri Original request URI.
 * @return string|false Target URL or false.
 */
function dms_rewrite_build_target_url( $mapping, $match, $request_uri ) {
	if ( empty( $mapping['host'] ) ) {
		return false;
	}

	$scheme    = is_ssl() ? 'https' : 'http';
	$base_path = $mapping['path'] ? '/' . ltrim( $mapping['path'], '/' ) : '';

	// Extract path + query from request.
	$parsed       = wp_parse_url( $request_uri );
	$request_path = isset( $parsed['path'] ) ? $parsed['path'] : '/';
	$query        = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';

	// Apply rewrite target if it's a path rewrite.
	$target_path = $match['target'] ? $match['target'] : $request_path;

	// Ensure target_path is absolute.
	if ( $target_path && '/' !== $target_path[0] ) {
		$target_path = '/' . ltrim( $target_path, '/' );
	}

	return $scheme . '://' . $mapping['host'] . $base_path . $target_path . $query;
}

/**
 * Cache invalidation hook.
 * Called when a mapping is updated/deleted.
 */
add_action( 'dms_mapping_updated', 'dms_rewrite_clear_cache', 10, 2 );

/**
 * Clears rewrite cache for a host.
 *
 * @param int    $mapping_id Mapping ID.
 * @param string $host       Host.
 * @return void
 */
function dms_rewrite_clear_cache( $mapping_id, $host ) {
	DMS_Rewrite_Engine::clear_cache( $host );
}

/*
 * The activation/uninstall helpers below are owned by the main plugin file
 * (domain-mapping-system.php). This drop-in is copied into mu-plugins, which
 * WordPress loads BEFORE regular plugins, so declaring these names
 * unconditionally here would make the plugin file's own declarations fatal
 * ("Cannot redeclare ..."). Guard with function_exists() so either load order
 * is safe.
 */
if ( ! function_exists( 'dms_rewrite_uninstall' ) ) {
	/**
	 * Uninstall cleanup: remove mu-plugins drop-in file.
	 * Called by the plugin's uninstall hook.
	 */
	function dms_rewrite_uninstall() {
		$mu_file = WP_CONTENT_DIR . '/mu-plugins/dms-rewrite-loader.php';
		if ( file_exists( $mu_file ) ) {
			wp_delete_file( $mu_file );
		}
		// Flush rewrite rules.
		flush_rewrite_rules();
	}
}

if ( ! function_exists( 'dms_rewrite_install' ) ) {
	/**
	 * Activation: install mu-plugins drop-in.
	 * Called by the plugin's activation hook.
	 */
	function dms_rewrite_install() {
		$mu_dir  = WP_CONTENT_DIR . '/mu-plugins';
		$mu_file = $mu_dir . '/dms-rewrite-loader.php';

		// Create mu-plugins directory if needed.
		if ( ! is_dir( $mu_dir ) ) {
			wp_mkdir_p( $mu_dir );
		}

		// Copy this file to mu-plugins.
		$source = __FILE__;
		if ( file_exists( $source ) && ! file_exists( $mu_file ) ) {
			copy( $source, $mu_file );
		}
	}
}

// If this file is loaded directly in mu-plugins, run installation.
if ( defined( 'DMS_REWRITE_LOADER_INSTALL' ) && DMS_REWRITE_LOADER_INSTALL ) {
	dms_rewrite_install();
}
