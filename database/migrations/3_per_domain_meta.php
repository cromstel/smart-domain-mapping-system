<?php
/**
 * Migration 3 — per-domain meta columns (feature-integration plan Phase 2).
 *
 * Adds the per-domain customization columns to dm_mappings:
 *   menu_id, language, redirect_404, rewrite_rules, query_string_rules.
 *
 * Like migration 1 and 2, this file is a reference copy for audit/reversibility
 * tracking; the live logic lives in DMS_DB_Tables::create_tables() (includes/
 * core/class-db-tables.php) and is gated by the schema version option
 * `dm_db_version` (DMS_DB_VERSION = 3).
 *
 * Reversibility: `down()` is NOT executed automatically — it is provided for
 * rollback planning. ALTER TABLE DROP COLUMN is destructive; run it only with
 * an explicit ops decision, and never while per-domain meta is in use.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/upgrade.php';

global $wpdb;

$table_mappings = $wpdb->base_prefix . 'dm_mappings';

/**
 * Creates the Phase 2 columns (idempotent via explicit ALTER checks).
 */
function dm_migration_3_up() {
	global $wpdb, $table_mappings;

	$columns = $wpdb->get_col( "DESCRIBE {$table_mappings}", 0 );
	$columns = array_flip( $columns );

	$alter_statements = array();

	if ( ! isset( $columns['menu_id'] ) ) {
		$alter_statements[] = 'ADD COLUMN menu_id bigint(20) unsigned DEFAULT NULL';
	}
	if ( ! isset( $columns['language'] ) ) {
		$alter_statements[] = 'ADD COLUMN language varchar(10) DEFAULT NULL';
	}
	if ( ! isset( $columns['redirect_404'] ) ) {
		$alter_statements[] = 'ADD COLUMN redirect_404 text DEFAULT NULL';
	}
	if ( ! isset( $columns['rewrite_rules'] ) ) {
		$alter_statements[] = 'ADD COLUMN rewrite_rules json DEFAULT NULL';
	}
	if ( ! isset( $columns['query_string_rules'] ) ) {
		$alter_statements[] = 'ADD COLUMN query_string_rules json DEFAULT NULL';
	}

	if ( ! empty( $alter_statements ) ) {
		$sql = "ALTER TABLE {$table_mappings} " . implode( ', ', $alter_statements );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted table/column names.
		$wpdb->query( $sql );
	}
}

/**
 * Rolls migration 3 back. Destructive — never run automatically.
 */
function dm_migration_3_down() {
	global $wpdb, $table_mappings;

	$columns = $wpdb->get_col( "DESCRIBE {$table_mappings}", 0 );
	$columns = array_flip( $columns );

	$alter_statements = array();

	foreach ( array( 'menu_id', 'language', 'redirect_404', 'rewrite_rules', 'query_string_rules' ) as $col ) {
		if ( isset( $columns[ $col ] ) ) {
			$alter_statements[] = "DROP COLUMN {$col}";
		}
	}

	if ( ! empty( $alter_statements ) ) {
		$sql = "ALTER TABLE {$table_mappings} " . implode( ', ', $alter_statements );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted table/column names.
		$wpdb->query( $sql );
	}
}

dm_migration_3_up();
