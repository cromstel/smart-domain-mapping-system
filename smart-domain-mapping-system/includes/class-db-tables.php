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
		$ssl_table = $wpdb->base_prefix . DMS_TABLE_SSL;
		$logs_table = $wpdb->base_prefix . DMS_TABLE_LOGS;

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

		dbDelta( $sql );
		dbDelta( $sql2 );
		dbDelta( $sql3 );
	}
}
