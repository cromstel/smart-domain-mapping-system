<?php
/**
 * Daily health checks (WP-Cron, hook `dm_daily_health_check`):
 * verifies each active mapped domain still resolves and that tracked
 * certificates are not near expiry; issues are logged to dm_audit_log.
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_Health_Check {
	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'dm_daily_health_check', array( $this, 'run' ) );
	}

	/**
	 * Runs all health checks over active mappings and prunes old audit rows.
	 */
	public function run() {
		$this->purge_old_logs();

		$mappings = DMS_Mapping_Engine::list_mappings();
		if ( is_wp_error( $mappings ) || empty( $mappings ) ) {
			return;
		}
		foreach ( $mappings as $mapping ) {
			if ( empty( $mapping['active'] ) ) {
				continue;
			}
			$this->check_dns( $mapping );
			$this->check_certificate( $mapping );
		}
	}

	/**
	 * Deletes audit entries older than the configured retention window
	 * (dm_options.audit_retention_days; 0 keeps entries forever).
	 */
	private function purge_old_logs(): void {
		$options = get_site_option( 'dm_options', array() );
		$days    = isset( $options['audit_retention_days'] ) ? (int) $options['audit_retention_days'] : 180;
		if ( $days < 1 ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->base_prefix . DMS_TABLE_LOGS;
		// Custom table; the query is prepared and bounded by an indexed datetime column.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s",
				wp_date( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) )
			)
		);

		if ( $deleted ) {
			DMS_Logging::get_instance()->log(
				0,
				'',
				0,
				'audit.purged',
				wp_json_encode(
					array(
						'deleted'        => (int) $deleted,
						'retention_days' => $days,
					)
				)
			);
		}
	}

	/**
	 * Checks that the mapped domain resolves to at least one address.
	 *
	 * @param array $mapping Mapping row.
	 */
	private function check_dns( array $mapping ): void {
		$host = $mapping['domain'];
		if ( str_starts_with( $host, '*.' ) ) {
			$host = substr( $host, 2 );
		}
		$ips = function_exists( 'gethostbynamel' ) ? gethostbynamel( $host ) : false;
		if ( empty( $ips ) ) {
			DMS_Logging::get_instance()->log(
				(int) $mapping['blog_id'],
				$mapping['domain'],
				0,
				'health.dns_failed',
				wp_json_encode( array( 'host' => $host ) )
			);
		}
	}

	/**
	 * Flags certificates expiring within the default window.
	 *
	 * @param array $mapping Mapping row.
	 */
	private function check_certificate( array $mapping ): void {
		$manager = DMS_SSL_Manager::get_instance();
		if ( $manager->is_near_expiry( (int) $mapping['blog_id'], $mapping['domain'] ) ) {
			$certificate = $manager->get_certificate( (int) $mapping['blog_id'], $mapping['domain'] );
			DMS_Logging::get_instance()->log(
				(int) $mapping['blog_id'],
				$mapping['domain'],
				0,
				'health.cert_expiring',
				wp_json_encode(
					array(
						'expires_at' => $certificate ? $certificate->expires_at : null,
					)
				)
			);
		}
	}
}
