<?php
/**
 * Migration 5 — correlation_id on dm_audit_log (Phase 3A audit trail repair).
 *
 * DMS_Logging::log() has always written a correlation_id column so an event
 * can be traced across the rows of a single request/operation, but no schema
 * ever created the column. Every INSERT therefore failed and the audit trail
 * was empty.
 *
 * Live logic lives in DMS_DB_Tables::maybe_add_log_columns() (includes/
 * core/class-db-tables.php) and is gated by the schema version option
 * `dm_db_version` (DMS_DB_VERSION = 5). This file is the reference copy for
 * audit/reversibility tracking, following migrations 1–3.
 *
 * Reversibility: `down()` is NOT executed automatically — it is provided for
 * rollback planning. ALTER TABLE DROP COLUMN is destructive; run it only with
 * an explicit ops decision, and never while audit history is needed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/upgrade.php';

global $wpdb;

$table_logs = $wpdb->base_prefix . 'dm_audit_log';

/**
 * Adds correlation_id (and its index) to dm_audit_log, idempotently.
 */
function dm_migration_5_up() {
	global $wpdb, $table_logs;

	$columns = $wpdb->get_col( "DESCRIBE {$table_logs}", 0 );
	$columns = array_flip( $columns );

	if ( ! isset( $columns['correlation_id'] ) ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted table/column names.
		$wpdb->query( "ALTER TABLE {$table_logs} ADD COLUMN correlation_id varchar(36) DEFAULT NULL AFTER context" );
	}

	$index_exists = $wpdb->get_var(
		"SHOW INDEX FROM {$table_logs} WHERE Key_name = 'correlation_id'"
	);
	if ( ! $index_exists ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted table name.
		$wpdb->query( "ALTER TABLE {$table_logs} ADD KEY correlation_id (correlation_id)" );
	}
}

/**
 * Rolls migration 5 back. Destructive — never run automatically.
 */
function dm_migration_5_down() {
	global $wpdb, $table_logs;

	$columns = $wpdb->get_col( "DESCRIBE {$table_logs}", 0 );
	$columns = array_flip( $columns );

	if ( isset( $columns['correlation_id'] ) ) {
		$index_exists = $wpdb->get_var(
			"SHOW INDEX FROM {$table_logs} WHERE Key_name = 'correlation_id'"
		);
		if ( $index_exists ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted table name.
			$wpdb->query( "ALTER TABLE {$table_logs} DROP KEY correlation_id" );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted table/column names.
		$wpdb->query( "ALTER TABLE {$table_logs} DROP COLUMN correlation_id" );
	}
}

dm_migration_5_up();
