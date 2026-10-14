<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DMS_DB_Tables {
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$verifications_table = $wpdb->base_prefix . DMS_TABLE_VERIFICATIONS;
		$ssl_table           = $wpdb->base_prefix . DMS_TABLE_SSL;
		$logs_table          = $wpdb->base_prefix . DMS_TABLE_LOGS;
		$mappings_table      = $wpdb->base_prefix . DMS_TABLE_MAPPINGS;
		$values_table        = $wpdb->base_prefix . DMS_TABLE_VALUES;

		$sql = "CREATE TABLE $verifications_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			blog_id bigint(20) unsigned NOT NULL,
			domain varchar(255) NOT NULL,
			method varchar(20) DEFAULT 'dns',
			token_hash varchar(128) NOT NULL,
			status varchar(20) DEFAULT 'pending',
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			expires_at datetime DEFAULT NULL,
			verified_at datetime DEFAULT NULL,
			attempts int DEFAULT 0,
			last_error text DEFAULT NULL,
			PRIMARY KEY (id),
			KEY blog_id (blog_id),
			KEY domain (domain),
			KEY status (status)
		) {$charset_collate};";

		$sql2 = "CREATE TABLE $ssl_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			blog_id bigint(20) unsigned NOT NULL,
			domain varchar(255) NOT NULL,
			provider varchar(20) DEFAULT 'none',
			status varchar(20) DEFAULT 'none',
			issued_at datetime DEFAULT NULL,
			expires_at datetime DEFAULT NULL,
			last_renewal_at datetime DEFAULT NULL,
			last_error text DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY blog_id (blog_id),
			KEY domain (domain),
			KEY status (status)
		) {$charset_collate};";

		$sql3 = "CREATE TABLE $logs_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			blog_id bigint(20) unsigned DEFAULT NULL,
			domain varchar(255) DEFAULT NULL,
			user_id bigint(20) unsigned DEFAULT NULL,
			action varchar(64) NOT NULL,
			context text DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY blog_id (blog_id),
			KEY domain (domain),
			KEY user_id (user_id)
		) {$charset_collate};";

		// Phase 1 (feature-integration plan): single-site mapping store.
		// Phase 2: added menu_id, language, redirect_404, rewrite_rules, query_string_rules.
		// `primary` is a reserved word in MySQL and must stay backticked.
		$sql4 = "CREATE TABLE $mappings_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			host varchar(255) NOT NULL,
			path varchar(255) NOT NULL DEFAULT '',
			attachment_id bigint(20) unsigned DEFAULT NULL,
			custom_html longtext,
			menu_id bigint(20) unsigned DEFAULT NULL,
			language varchar(10) DEFAULT NULL,
			redirect_404 text DEFAULT NULL,
			rewrite_rules json DEFAULT NULL,
			query_string_rules json DEFAULT NULL,
			active tinyint(1) NOT NULL DEFAULT 1,
			verified tinyint(1) NOT NULL DEFAULT 0,
			`primary` tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY host_path (host, path),
			KEY active (active),
			KEY verified (verified)
		) {$charset_collate};";

		$sql5 = "CREATE TABLE $values_table (
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
		) {$charset_collate};";

		dbDelta( $sql );
		dbDelta( $sql2 );
		dbDelta( $sql3 );
		dbDelta( $sql4 );
		dbDelta( $sql5 );

		// Migration 3: add per-domain meta columns to existing dm_mappings table.
		// dbDelta handles CREATE TABLE but not ALTER TABLE for new columns on existing tables.
		// Run explicit ALTERs if columns don't exist (idempotent via IF NOT EXISTS via dbDelta limitations).
		self::maybe_add_mapping_columns( $mappings_table );
	}

	/**
	 * Adds Phase 2 columns to dm_mappings if they don't exist.
	 * Runs on every schema upgrade (gated by DMS_DB_VERSION).
	 *
	 * @param string $table Fully qualified table name.
	 * @return void
	 */
	private static function maybe_add_mapping_columns( string $table ) {
		global $wpdb;

		$columns = $wpdb->get_col( "DESCRIBE {$table}", 0 );
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
			$sql = "ALTER TABLE {$table} " . implode( ', ', $alter_statements );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted table/column names.
			$wpdb->query( $sql );
		}
	}
}
