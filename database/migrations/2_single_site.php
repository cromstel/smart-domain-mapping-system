<?php
/**
 * Migration 2 — single-site mapping store (feature-integration plan Phase 1).
 *
 * Adds the host/path-keyed mapping table and the mapping-values table used by
 * the single-site mode and the `dms/v1` REST contract. Like migration 1, this
 * file is a reference copy for audit/reversibility tracking; the live logic
 * lives in DMS_DB_Tables::create_tables() (includes/core/class-db-tables.php)
 * and is gated by the schema version option `dm_db_version` (DMS_DB_VERSION).
 *
 * Reversibility: `down()` is NOT executed automatically — it is provided for
 * rollback planning. DROP TABLE is destructive; run it only with an explicit
 * ops decision, and never while single-site mappings are in use.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/upgrade.php';

global $wpdb;

$dm2_charset_collate = $wpdb->get_charset_collate();

$table_mappings = $wpdb->base_prefix . 'dm_mappings';
$table_values   = $wpdb->base_prefix . 'dm_mapping_values';

/**
 * Creates the two tables (idempotent via dbDelta).
 */
function dm_migration_2_up() {
	global $wpdb, $dm2_charset_collate, $table_mappings, $table_values;

	// `primary` is a reserved word in MySQL and must stay backticked.
	$sql_mappings = "CREATE TABLE $table_mappings (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		host varchar(255) NOT NULL,
		path varchar(255) NOT NULL DEFAULT '',
		attachment_id bigint(20) unsigned DEFAULT NULL,
		custom_html longtext,
		active tinyint(1) NOT NULL DEFAULT 1,
		verified tinyint(1) NOT NULL DEFAULT 0,
		`primary` tinyint(1) NOT NULL DEFAULT 0,
		created_at datetime DEFAULT CURRENT_TIMESTAMP,
		updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY host_path (host, path),
		KEY active (active),
		KEY verified (verified)
	) $dm2_charset_collate;";

	$sql_values = "CREATE TABLE $table_values (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		mapping_id bigint(20) unsigned NOT NULL,
		object_type varchar(32) NOT NULL,
		object_id bigint(20) unsigned NOT NULL,
		`primary` tinyint(1) NOT NULL DEFAULT 0,
		created_at datetime DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY mapping_object (mapping_id, object_type, object_id),
		KEY object (object_type, object_id),
		KEY mapping_id (mapping_id)
	) $dm2_charset_collate;";

	dbDelta( $sql_mappings );
	dbDelta( $sql_values );
}

/**
 * Rolls migration 2 back. Destructive — never run automatically.
 */
function dm_migration_2_down() {
	global $wpdb, $table_mappings, $table_values;

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted literal table names.
	$wpdb->query( "DROP TABLE IF EXISTS {$table_mappings}, {$table_values}" );
}

dm_migration_2_up();
