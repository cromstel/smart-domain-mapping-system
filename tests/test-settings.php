<?php
/**
 * Audit-log retention: option sanitization (default + clamp) and the daily
 * purge performed by the health check.
 */
class DMS_Test_Settings extends WP_UnitTestCase {

	public function test_audit_retention_defaults_when_absent() {
		$clean = DMS_Settings::get_instance()->sanitize_options( array() );
		$this->assertSame( '180', $clean['audit_retention_days'] );
	}

	public function test_audit_retention_is_clamped_to_maximum() {
		$clean = DMS_Settings::get_instance()->sanitize_options( array( 'audit_retention_days' => 99999 ) );
		$this->assertSame( '3650', $clean['audit_retention_days'] );
	}

	public function test_audit_retention_zero_means_keep_forever() {
		$clean = DMS_Settings::get_instance()->sanitize_options( array( 'audit_retention_days' => 0 ) );
		$this->assertSame( '0', $clean['audit_retention_days'] );
	}

	public function test_daily_health_check_purges_entries_older_than_retention() {
		global $wpdb;
		$table = $wpdb->base_prefix . DMS_TABLE_LOGS;

		update_site_option( 'dm_options', array( 'audit_retention_days' => '30' ) );
		DMS_Logging::get_instance()->log( 1, 'retention.example.com', 0, 'retention.old', '' );

		// Backdate the row beyond the retention window.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET created_at = %s WHERE action = %s",
				'2000-01-01 00:00:00',
				'retention.old'
			)
		);

		DMS_Health_Check::get_instance()->run();

		$remaining = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE action = %s", 'retention.old' )
		);
		$this->assertSame( 0, (int) $remaining, 'Old audit rows should be purged by the daily health check.' );
	}
}
