<?php
/**
 * Migration 4 — performance indexes + denormalized host in values table (Phase 3B).
 *
 * Adds composite indexes for query performance and denormalizes host into
 * dm_mapping_values for direct lookup without joining dm_mappings.
 *
 * Live logic lives in DMS_DB_Tables::create_tables() (includes/
 * core/class-db-tables.php) and is gated by the schema version option
 * `dm_db_version` (DMS_DB_VERSION = 4).
 *
 * Reversibility: `down()` is NOT executed automatically — it is provided for
 * rollback planning. ALTER TABLE operations are destructive; run only with
 * explicit ops decision.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/upgrade.php';

// This file is require()d from inside DMS_DB_Tables::run_migration_4(), so its
// "file scope" is actually that method's local scope. Declare the table names
// as globals here, otherwise the assignments below land in local scope and the
// functions underneath (which read them through `global`) see empty strings —
// issuing "DESCRIBE " and "ALTER TABLE  ..." against no table at all.
global $wpdb, $table_mappings, $table_values;

$table_mappings = $wpdb->base_prefix . 'dm_mappings';
$table_values   = $wpdb->base_prefix . 'dm_mapping_values';

/**
 * Creates the Phase 3B performance optimizations.
 */
function dm_migration_4_up() {
	global $wpdb, $table_mappings, $table_values;

	$mapping_cols = $wpdb->get_col( "DESCRIBE {$table_mappings}", 0 );
	$mapping_cols = array_flip( $mapping_cols );

	$value_cols = $wpdb->get_col( "DESCRIBE {$table_values}", 0 );
	$value_cols = array_flip( $value_cols );

	$alter_mapping = array();
	$alter_values  = array();

	// Add composite indexes to dm_mappings.
	if ( ! isset( $mapping_cols['host_active'] ) ) {
		$alter_mapping[] = 'ADD KEY host_active (host, active)';
	}
	if ( ! isset( $mapping_cols['host_verified'] ) ) {
		$alter_mapping[] = 'ADD KEY host_verified (host, verified)';
	}

	// Add host column to dm_mapping_values for denormalized lookups.
	if ( ! isset( $value_cols['host'] ) ) {
		$alter_values[] = 'ADD COLUMN host varchar(255) NOT NULL DEFAULT \'\' AFTER mapping_id';
		$alter_values[] = 'ADD KEY host (host)';
	}

	if ( ! empty( $alter_mapping ) ) {
		$sql = "ALTER TABLE {$table_mappings} " . implode( ', ', $alter_mapping );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted table/column names.
		$wpdb->query( $sql );
	}

	if ( ! empty( $alter_values ) ) {
		$sql = "ALTER TABLE {$table_values} " . implode( ', ', $alter_values );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted table/column names.
		$wpdb->query( $sql );
	}

	// Backfill host column in existing mapping_values rows.
	if ( ! empty( $alter_values ) ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted table names.
		$wpdb->query(
			"
			UPDATE {$table_values} v
			INNER JOIN {$table_mappings} m ON m.id = v.mapping_id
			SET v.host = m.host
			WHERE v.host = '' OR v.host IS NULL
		"
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted table names, static SQL.
	}
}

/**
 * Rolls migration 4 back. Destructive — never run automatically.
 */
function dm_migration_4_down() {
	global $wpdb, $table_mappings, $table_values;

	$mapping_cols = $wpdb->get_col( "DESCRIBE {$table_mappings}", 0 );
	$mapping_cols = array_flip( $mapping_cols );

	$value_cols = $wpdb->get_col( "DESCRIBE {$table_values}", 0 );
	$value_cols = array_flip( $value_cols );

	$alter_mapping = array();
	$alter_values  = array();

	foreach ( array( 'host_active', 'host_verified' ) as $idx ) {
		if ( isset( $mapping_cols[ $idx ] ) ) {
			$alter_mapping[] = "DROP KEY {$idx}";
		}
	}

	if ( isset( $value_cols['host'] ) ) {
		$alter_values[] = 'DROP KEY host';
		$alter_values[] = 'DROP COLUMN host';
	}

	if ( ! empty( $alter_mapping ) ) {
		$sql = "ALTER TABLE {$table_mappings} " . implode( ', ', $alter_mapping );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted table/column names.
		$wpdb->query( $sql );
	}

	if ( ! empty( $alter_values ) ) {
		$sql = "ALTER TABLE {$table_values} " . implode( ', ', $alter_values );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted table/column names.
		$wpdb->query( $sql );
	}
}

dm_migration_4_up();
