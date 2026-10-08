<?php
/**
 * Domain Mapping System
 *
 * Multisite custom domain mapping stored in wp_sitemeta (Mercator-style),
 * with DNS verification, SSL lifecycle tracking and audit logging.
 *
 * Mapped domains resolve through core's `wp_blogs.domain` (kept in sync by
 * the mapping engine) — no sunrise.php, no post-bootstrap interception.
 *
 * @package Domain_Mapping_System
 */

/**
 * Plugin Name:       Domain Mapping System
 * Description:       Multisite custom domain mapping using wp_sitemeta with DNS verification, SSL lifecycle tracking, and audit logging.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            CITGROUP
 * Author URI:        https://cromstelit.com
 * License:           GPL-2.0-or-later
 * Text Domain:       domain-mapping-system
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DMS_VERSION', '1.0.0' );
define( 'DMS_PLUGIN_FILE', __FILE__ );

define( 'DMS_TABLE_VERIFICATIONS', 'dm_verifications' );
define( 'DMS_TABLE_SSL', 'dm_certificates' );
define( 'DMS_TABLE_LOGS', 'dm_audit_log' );

require_once __DIR__ . '/includes/class-db-tables.php';
require_once __DIR__ . '/includes/class-mapping-engine.php';
require_once __DIR__ . '/includes/class-dns-verification.php';
require_once __DIR__ . '/includes/class-ssl-manager.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-rest-api.php';
require_once __DIR__ . '/includes/class-cli.php';
require_once __DIR__ . '/includes/class-domain-mapping-system.php';
require_once __DIR__ . '/includes/class-logging.php';
require_once __DIR__ . '/includes/class-ssl-provider.php';
require_once __DIR__ . '/includes/class-migration.php';
require_once __DIR__ . '/includes/class-health-check.php';
require_once __DIR__ . '/public/redirect-helpers.php';

/**
 * Runs schema creation + scheduled health checks on activation.
 */
function dm_activation() {
	DMS_DB_Tables::create_tables();
	if ( ! wp_next_scheduled( 'dm_daily_health_check' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'dm_daily_health_check' );
	}
}

/**
 * Clears scheduled events on deactivation.
 */
function dm_deactivation() {
	wp_clear_scheduled_hook( 'dm_daily_health_check' );
}

register_activation_hook( DMS_PLUGIN_FILE, 'dm_activation' );
register_deactivation_hook( DMS_PLUGIN_FILE, 'dm_deactivation' );

/**
 * Creates/updates tables when the plugin version changes.
 */
add_action( 'plugins_loaded', 'dm_version_check' );
function dm_version_check() {
	$current = get_site_option( 'dm_version', '0' );
	if ( version_compare( $current, DMS_VERSION, '<' ) ) {
		DMS_DB_Tables::create_tables();
		update_site_option( 'dm_version', DMS_VERSION );
	}
}

DMS_REST_API::get_instance();
DMS_Health_Check::get_instance();
DMS_Plugin::get_instance();
