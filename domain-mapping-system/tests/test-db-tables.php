<?php
/**
 * PHPUnit test for Domain Mapping System DB tables (network-wide base prefix).
 */
class DMS_Test_DB_Tables extends WP_UnitTestCase {

	public function test_dm_verifications_table_exists() {
		global $wpdb;
		$table  = $wpdb->base_prefix . DMS_TABLE_VERIFICATIONS;
		$result = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
		$this->assertNotEmpty( $result, 'dm_verifications table should exist' );
		$this->assertEquals( $table, $result );
		$this->assertEquals( 'dm_verifications', DMS_TABLE_VERIFICATIONS );
	}

	public function test_dm_certificates_table_exists() {
		global $wpdb;
		$table  = $wpdb->base_prefix . DMS_TABLE_SSL;
		$result = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
		$this->assertNotEmpty( $result, 'dm_certificates table should exist' );
		$this->assertEquals( $table, $result );
		$this->assertEquals( 'dm_certificates', DMS_TABLE_SSL, 'SSL table constant must be dm_certificates' );
	}

	public function test_dm_audit_log_table_exists() {
		global $wpdb;
		$table  = $wpdb->base_prefix . DMS_TABLE_LOGS;
		$result = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
		$this->assertNotEmpty( $result, 'dm_audit_log table should exist' );
		$this->assertEquals( $table, $result );
		$this->assertEquals( 'dm_audit_log', DMS_TABLE_LOGS, 'Log table constant must be dm_audit_log' );
	}

	public function test_verifications_schema_has_spec_columns() {
		global $wpdb;
		$table    = $wpdb->base_prefix . DMS_TABLE_VERIFICATIONS;
		$columns  = $wpdb->get_col( "DESCRIBE {$table}", 0 );
		$expected = array( 'id', 'blog_id', 'domain', 'method', 'token_hash', 'status', 'created_at', 'expires_at', 'verified_at', 'attempts', 'last_error' );
		$this->assertEquals( $expected, $columns );
	}

	public function test_dm_tables_all_present() {
		global $wpdb;
		$tables = array(
			$wpdb->base_prefix . DMS_TABLE_VERIFICATIONS,
			$wpdb->base_prefix . DMS_TABLE_SSL,
			$wpdb->base_prefix . DMS_TABLE_LOGS,
		);
		foreach ( $tables as $table ) {
			$this->assertNotEmpty( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ), "Table {$table} missing" );
		}
	}
}
