<?php
/**
 * Uninstall handler: drops the plugin's tables and removes every piece of
 * state it stores in wp_sitemeta / site options.
 *
 * Table names are literals here because plugin constants are not loaded in
 * uninstall context; keep them in sync with DMS_TABLE_* in the main file.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Custom tables (network-wide base prefix, not per-site prefix).
$tables = array(
	$wpdb->base_prefix . 'dm_verifications',
	$wpdb->base_prefix . 'dm_certificates',
	$wpdb->base_prefix . 'dm_audit_log',
);
foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted literal table name.
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// Mapping rows and per-mapping state flags in wp_sitemeta.
$prefixes = array( 'dm_domain_', 'dm_active_', 'dm_original_' );
foreach ( $prefixes as $prefix ) {
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( $prefix ) . '%'
		)
	);
}

// Site options + upgrade marker.
delete_site_option( 'dm_options' );
delete_site_option( 'dm_version' );
