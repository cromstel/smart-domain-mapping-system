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

define( 'DMS_VERSION', '1.2.0' );
define( 'DMS_PLUGIN_FILE', __FILE__ );
define( 'DMS_PLUGIN_DIR', __DIR__ );

// Schema version is decoupled from the release version: the data layer can
// gain tables/migrations in unreleased work without bumping the plugin header.
define( 'DMS_DB_VERSION', '3' );

define( 'DMS_TABLE_VERIFICATIONS', 'dm_verifications' );
define( 'DMS_TABLE_SSL', 'dm_certificates' );
define( 'DMS_TABLE_LOGS', 'dm_audit_log' );
define( 'DMS_TABLE_MAPPINGS', 'dm_mappings' );
define( 'DMS_TABLE_VALUES', 'dm_mapping_values' );

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
		'DMS_DB_Tables'                  => 'includes/core/class-db-tables.php',
		'DMS_Mapping_Engine'             => 'includes/core/class-mapping-engine.php',
		'DMS_Mode'                       => 'includes/core/class-mode.php',
		'DMS_Single_Site_Mappings'       => 'includes/core/class-single-site-mappings.php',
		'DMS_Mapping_Values'             => 'includes/core/class-mapping-values.php',
		'DMS_Logging'                    => 'includes/core/class-logging.php',
		'DMS_Migration'                  => 'includes/core/class-migration.php',
		'DMS_Plugin'                     => 'includes/core/class-domain-mapping-system.php',
		'DMS_DNS_Verification'           => 'includes/verification/class-dns-verification.php',
		'DMS_Health_Check'               => 'includes/verification/class-health-check.php',
		'DMS_SSL_Manager'                => 'includes/ssl/class-ssl-manager.php',
		'DMS_SSL_Provider_Interface'     => 'includes/ssl/class-ssl-provider-interface.php',
		'DMS_SSL_Provider_None'          => 'includes/ssl/class-ssl-provider-none.php',
		'DMS_SSL_Provider_Hosting'       => 'includes/ssl/class-ssl-provider-hosting.php',
		'DMS_SSL_Provider_ACME'          => 'includes/ssl/class-ssl-provider-acme.php',
		'DMS_SSL_Providers'              => 'includes/ssl/class-ssl-providers.php',
		'DMS_Settings'                   => 'includes/admin/class-settings.php',
		'DMS_Single_Site_Admin'          => 'includes/admin/class-single-site-admin.php',
		'DMS_REST_API'                   => 'includes/rest/class-rest-api.php',
		'DMS_REST_DMS'                   => 'includes/rest/class-rest-dms.php',
		// Phase 2B: Per-domain customization.
		'DMS_Per_Domain_Custom_Code'     => 'includes/per-domain/class-custom-code.php',
		'DMS_Per_Domain_Favicon'         => 'includes/per-domain/class-favicon.php',
		'DMS_Per_Domain_Menu'            => 'includes/per-domain/class-menu.php',
		'DMS_Per_Domain_Language'        => 'includes/per-domain/class-language.php',
		'DMS_Per_Domain_404'             => 'includes/per-domain/class-404.php',
		// Phase 2C: Global mapping scopes.
		'DMS_Global_Archive_Mapping'     => 'includes/global/class-archive-mapping.php',
		'DMS_Global_Page_Mapping'        => 'includes/global/class-page-mapping.php',
		'DMS_Global_Product_Mapping'     => 'includes/global/class-product-mapping.php',
		'DMS_Global_Domain_Mapping'      => 'includes/global/class-domain-mapping.php',
		// Phase 2D: Rewrite engine.
		'DMS_Rewrite_Engine'             => 'includes/rewrite/class-rewrite-engine.php',
		// Phase 2E: Auth & integrations.
		'DMS_Alias_Auth'                 => 'includes/auth/class-alias-auth.php',
		'DMS_Subdomain_Auth'             => 'includes/auth/class-subdomain-auth.php',
		'DMS_Integration_Guard'          => 'includes/integrations/class-integration-guard.php',
		'DMS_Integration_Yoast'          => 'includes/integrations/class-yoast.php',
		'DMS_Integration_Cache'          => 'includes/integrations/class-cache.php',
		'DMS_Integration_Multilingual'   => 'includes/integrations/class-multilingual.php',
		'DMS_Integration_Borlabs'        => 'includes/integrations/class-borlabs.php',
		'DMS_Integration_MDM'            => 'includes/integrations/class-mdm.php',
		'DMS_Integrations'               => 'includes/integrations/class-integrations.php',
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
	// Phase 2D: Install rewrite engine mu-plugins drop-in.
	if ( DMS_Mode::is_single_site() ) {
		dms_rewrite_install();
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
 * Uninstall handler: drops tables, removes mu-plugins drop-in.
 */
function dm_uninstall() {
	// Phase 2D: Remove rewrite engine mu-plugins drop-in.
	if ( DMS_Mode::is_single_site() ) {
		dms_rewrite_uninstall();
	}
}

register_uninstall_hook( DMS_PLUGIN_FILE, 'dm_uninstall' );

/**
 * Phase 2D: Installs the rewrite engine mu-plugins drop-in.
 */
function dms_rewrite_install() {
	$mu_dir  = WP_CONTENT_DIR . '/mu-plugins';
	$mu_file = $mu_dir . '/dms-rewrite-loader.php';

	// Create mu-plugins directory if needed.
	if ( ! is_dir( $mu_dir ) ) {
		wp_mkdir_p( $mu_dir );
	}

	$source = DMS_PLUGIN_DIR . '/includes/rewrite/class-rewrite-loader.php';
	if ( file_exists( $source ) && ! file_exists( $mu_file ) ) {
		copy( $source, $mu_file );
	}
}

/**
 * Phase 2D: Removes the rewrite engine mu-plugins drop-in.
 */
function dms_rewrite_uninstall() {
	$mu_file = WP_CONTENT_DIR . '/mu-plugins/dms-rewrite-loader.php';
	if ( file_exists( $mu_file ) ) {
		@unlink( $mu_file );
	}
	flush_rewrite_rules();
}

/**
 * Creates/updates tables when the schema version changes, and records the
 * plugin version marker. Schema (dm_db_version) is tracked independently of
 * the release version so data-layer migrations can ship without a version
 * bump (see DMS_DB_VERSION).
 */
add_action( 'plugins_loaded', 'dm_version_check' );
function dm_version_check() {
	$db_version = get_site_option( 'dm_db_version', '0' );
	if ( version_compare( $db_version, DMS_DB_VERSION, '<' ) ) {
		DMS_DB_Tables::create_tables();
		update_site_option( 'dm_db_version', DMS_DB_VERSION );
	}

	$current = get_site_option( 'dm_version', '0' );
	if ( version_compare( $current, DMS_VERSION, '<' ) ) {
		update_site_option( 'dm_version', DMS_VERSION );
	}
}

DMS_REST_API::get_instance();
DMS_REST_DMS::get_instance();
DMS_Health_Check::get_instance();
DMS_Single_Site_Admin::get_instance();
DMS_Plugin::get_instance();

// Phase 2B: Per-domain customization (single-site mode only).
if ( DMS_Mode::is_single_site() ) {
	DMS_Per_Domain_Custom_Code::get_instance();
	DMS_Per_Domain_Favicon::get_instance();
	DMS_Per_Domain_Menu::get_instance();
	DMS_Per_Domain_Language::get_instance();
	DMS_Per_Domain_404::get_instance();

	// Phase 2C: Global mapping scopes (single-site mode only).
	DMS_Global_Archive_Mapping::get_instance();
	DMS_Global_Page_Mapping::get_instance();
	DMS_Global_Product_Mapping::get_instance();
	DMS_Global_Domain_Mapping::get_instance();
}
