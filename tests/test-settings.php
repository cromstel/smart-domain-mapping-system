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

	public function test_audit_retention_is_clamped_to_minimum() {
		$clean = DMS_Settings::get_instance()->sanitize_options( array( 'audit_retention_days' => -100 ) );
		$this->assertSame( '0', $clean['audit_retention_days'] );
	}

	public function test_none_ssl_provider_does_not_claim_issuance() {
		$provider = new DMS_SSL_Provider_None();

		$this->assertFalse( $provider->issue_certificate( 1, 'none.example.com' ) );
		$this->assertFalse( $provider->renew_certificate( 1, 'none.example.com' ) );
	}

	public function test_hosting_ssl_provider_is_selected_explicitly() {
		update_site_option( 'dm_options', array( 'ssl_provider' => 'hosting' ) );

		$this->assertInstanceOf( DMS_SSL_Provider_Hosting::class, DMS_SSL_Providers::get() );
	}

	public function test_health_check_respects_retention_setting_from_site_option() {
		global $wpdb;
		$table = $wpdb->base_prefix . DMS_TABLE_LOGS;

		update_site_option( 'dm_options', array( 'audit_retention_days' => '3650' ) );
		DMS_Logging::get_instance()->log( 1, 'health.example.com', 0, 'health.retention', '' );

		DMS_Health_Check::get_instance()->run();

		$remaining = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE action = %s", 'health.retention' )
		);
		$this->assertSame( 1, (int) $remaining, 'Row within 3650-day retention should NOT be purged by health check.' );
	}
}
