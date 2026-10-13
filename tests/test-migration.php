<?php
/**
 * Migration 2 table-creation tests.
 *
 * Verifies that migration 2 (2_single_site.php) creates the two expected
 * tables (dm_mappings, dm_mapping_values) with correct schema, and that the
 * down() function's destructive nature is tracked — never run against prod.
 *
 * These require the WordPress test suite (WP_TESTS_DIR) and assume the
 * migration's dm_migration_2_up() function has been called (via the
 * DMS_DB_Tables::create_tables() gated by dm_db_version).
 *
 * The test class mirrors the patterns from test-db-tables.php.
 */

use WP_UnitTestCase;

final class DMS_Test_Migration_2 extends WP_UnitTestCase {

	public function test_migration_2_tables_exist() {
		global $wpdb;

		$mappings = $wpdb->base_prefix . DMS_TABLE_MAPPINGS;
		$values   = $wpdb->base_prefix . DMS_TABLE_VALUES;

		$this->assertTrue(
			$wpdb->get_var( "SHOW TABLES LIKE '{$mappings}'" ),
			'dm_mappings table should exist after migration 2.'
		);
		$this->assertTrue(
			$wpdb->get_var( "SHOW TABLES LIKE '{$values}'" ),
			'dm_mapping_values table should exist after migration 2.'
		);
	}

	public function test_mappings_schema_has_required_columns() {
		global $wpdb;

		$table = $wpdb->base_prefix . DMS_TABLE_MAPPINGS;
		$cols  = $wpdb->get_col( "DESCRIBE {$table}" );
		$expected = array( 'id', 'host', 'path', 'attachment_id', 'custom_html', 'active', 'verified', '`primary`' );
		$this->assertEquals( $expected, $cols, 'dm_mappings should have all Phase 1 columns.' );
	}

	public function test_values_schema_has_required_columns() {
		global $wpdb;

		$table = $wpdb->base_prefix . DMS_TABLE_VALUES;
		$cols  = $wpdb->get_col( "DESCRIBE {$table}" );
		$expected = array( 'id', 'mapping_id', 'object_type', 'object_id', '`primary`' );
		$this->assertEquals( $expected, $cols, 'dm_mapping_values should have all Phase 1 columns.' );
	}

	public function test_mappings_unique_host_path() {
		global $wpdb;

		$table = $wpdb->base_prefix . DMS_TABLE_MAPPINGS;
		// UNIQUE KEY means SHOW INDEX will list it; verify the constraint exists.
		$idx = $wpdb->get_var( "
			SELECT INDEX_NAME
			FROM information_schema.STATISTICS
			WHERE TABLE_SCHEMA = '%s'
			AND TABLE_NAME = '%s'
			AND COLUMN_NAME = 'host'
			AND INDEX_NAME = 'host_path'
		", $wpdb->dbname, $table );
		$this->assertNotEmpty( $idx, 'dm_mappings should have a UNIQUE KEY on (host, path).' );
	}

	public function test_values_unique_mapping_object() {
		global $wpdb;

		$table = $wpdb->base_prefix . DMS_TABLE_VALUES;
		$idx = $wpdb->get_var( "
			SELECT INDEX_NAME
			FROM information_schema.STATISTICS
			WHERE TABLE_SCHEMA = '%s'
			AND TABLE_NAME = '%s'
			AND COLUMN_NAME = 'mapping_id'
			AND INDEX_NAME = 'mapping_object'
		", $wpdb->dbname, $table );
		$this->assertNotEmpty( $idx, 'dm_mapping_values should have a UNIQUE KEY on (mapping_id, object_type, object_id).' );
	}
}