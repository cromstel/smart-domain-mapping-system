<?php
/**
 * Smart Domain Mapping System
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
 * Plugin Name:       Smart Domain Mapping System
 * Description:       Multisite custom domain mapping using wp_sitemeta with DNS verification, SSL lifecycle tracking, and audit logging.
 * Version:           1.1.1
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

define( 'DMS_VERSION', '1.1.1' );
define( 'DMS_PLUGIN_FILE', __FILE__ );
define( 'DMS_PLUGIN_DIR', __DIR__ );

define( 'DMS_TABLE_VERIFICATIONS', 'dm_verifications' );
define( 'DMS_TABLE_SSL', 'dm_certificates' );
define( 'DMS_TABLE_LOGS', 'dm_audit_log' );

/**
 * Autoloads the plugin's DMS_* classes from includes/.
 *
 * Classes are grouped by feature (core/, verification/, ssl/, admin/, rest/,
 * cli/) in files named class-<feature>.php, so an explicit class => file map
 * keeps loading deterministic without a filesystem scan on every request.
 *
 * @param string $class_name Fully-qualified class or interface name.
 * @return void
 */
function dms_autoload( $class_name ) {
	static $classes = array(
		'DMS_DB_Tables'              => 'includes/core/class-db-tables.php',
		'DMS_Mapping_Engine'         => 'includes/core/class-mapping-engine.php',
		'DMS_Logging'                => 'includes/core/class-logging.php',
		'DMS_Migration'              => 'includes/core/class-migration.php',
		'DMS_Plugin'                 => 'includes/core/class-domain-mapping-system.php',
		'DMS_DNS_Verification'       => 'includes/verification/class-dns-verification.php',
		'DMS_Health_Check'           => 'includes/verification/class-health-check.php',
		'DMS_SSL_Manager'            => 'includes/ssl/class-ssl-manager.php',
		'DMS_SSL_Provider_Interface' => 'includes/ssl/class-ssl-provider-interface.php',
		'DMS_SSL_Provider_None'      => 'includes/ssl/class-ssl-provider-none.php',
		'DMS_SSL_Provider_ACME'      => 'includes/ssl/class-ssl-provider-acme.php',
		'DMS_SSL_Providers'          => 'includes/ssl/class-ssl-providers.php',
		'DMS_Settings'               => 'includes/admin/class-settings.php',
		'DMS_REST_API'               => 'includes/rest/class-rest-api.php',
	);

	if ( isset( $classes[ $class_name ] ) ) {
		require_once DMS_PLUGIN_DIR . '/' . $classes[ $class_name ];
	}
}
spl_autoload_register( 'dms_autoload' );

// WP-CLI self-registers its command at load time and is never referenced by
// class name elsewhere, so it must be required eagerly. The file is a no-op
// unless WP_CLI is defined.
require_once __DIR__ . '/includes/cli/class-cli.php';

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
