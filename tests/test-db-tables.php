<?php
/**
 * PHPUnit test for Smart Domain Mapping System DB tables (network-wide base prefix).
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

	public function test_audit_log_schema_has_correlation_id() {
		global $wpdb;
		$table    = $wpdb->base_prefix . DMS_TABLE_LOGS;
		$columns  = $wpdb->get_col( "DESCRIBE {$table}", 0 );
		$expected = array( 'id', 'blog_id', 'domain', 'user_id', 'action', 'context', 'correlation_id', 'created_at' );
		$this->assertEquals( $expected, $columns, 'dm_audit_log must expose correlation_id to the logger' );
	}

	public function test_audit_log_insert_writes_correlation_id() {
		global $wpdb;
		$table = $wpdb->base_prefix . DMS_TABLE_LOGS;

		// Scope cleanup to this test's action so other rows are untouched.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE action = %s", 'dms.test.audit' ) );

		$written = DMS_Logging::get_instance()->log( null, 'dms-test.example', 7, 'dms.test.audit', array( 'k' => 'v' ) );
		$this->assertTrue( $written, 'log() must report a successful write' );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE action = %s ORDER BY id DESC LIMIT 1", 'dms.test.audit' ),
			ARRAY_A
		);
		$this->assertNotEmpty( $row, 'The audit row must be written, not silently dropped by a format mismatch' );
		$this->assertNotEmpty( $row['correlation_id'], 'A correlation_id must be stored for tracing' );
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
