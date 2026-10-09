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
	 * Runs all health checks over active mappings.
	 */
	public function run() {
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
