<?php
/**
 * Migration 1 — initial schema (v1.0.0 / v1.1.0 / v1.1.1 compatible).
 *
 * Used by DMS_DB_Tables::create_tables() (includes/core/class-db-tables.php)
 * via dbDelta(). This file is a reference copy of the initial migration for
 * audit/reversibility tracking; the live logic remains in the plugin class.
 */

require_once ABSPATH . 'wp-admin/includes/upgrade.php';

global $wpdb;

// Reference table names (constants defined in domain-mapping-system.php).
$table_verifications = $wpdb->base_prefix . 'dm_verifications';
$table_ssl           = $wpdb->base_prefix . 'dm_certificates';
$table_logs          = $wpdb->base_prefix . 'dm_audit_log';

$charset_collate = $wpdb->get_charset_collate();

$sql1 = "CREATE TABLE $table_verifications (
  id bigint(20) unsigned AUTO_INCREMENT PRIMARY KEY,
  blog_id bigint(20) unsigned NOT NULL,
  domain varchar(255) NOT NULL,
  method varchar(20) DEFAULT 'dns',
  token_hash varchar(128) NOT NULL,
  challenge_url text DEFAULT NULL,
  challenge_content text DEFAULT NULL,
  status varchar(20) DEFAULT 'pending',
  verified_at datetime DEFAULT NULL,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  expires_at datetime DEFAULT NULL,
  attempts int DEFAULT 0,
  last_error text DEFAULT NULL,
  KEY blog_id (blog_id),
  KEY domain (domain),
  KEY status (status)
) $charset_collate;";

dbDelta( $sql1 );

$sql2 = "CREATE TABLE $table_ssl (
  id bigint(20) unsigned AUTO_INCREMENT PRIMARY KEY,
  blog_id bigint(20) unsigned NOT NULL,
  domain varchar(255) NOT NULL,
  provider varchar(20) DEFAULT 'none',
  status varchar(20) DEFAULT 'none',
  issued_at datetime DEFAULT NULL,
  expires_at datetime DEFAULT NULL,
  last_renewal_attempt datetime DEFAULT NULL,
  last_renewal_result varchar(20) DEFAULT NULL,
  last_error text DEFAULT NULL,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY blog_id (blog_id),
  KEY domain (domain),
  KEY provider (provider),
  KEY status (status)
) $charset_collate;";

dbDelta( $sql2 );

$sql3 = "CREATE TABLE $table_logs (
  id bigint(20) unsigned AUTO_INCREMENT PRIMARY KEY,
  blog_id bigint(20) unsigned DEFAULT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  domain varchar(255) DEFAULT NULL,
  action varchar(64) NOT NULL,
  context text DEFAULT NULL,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  KEY blog_id (blog_id),
  KEY user_id (user_id),
  KEY action (action)
) $charset_collate;";

dbDelta( $sql3 );
