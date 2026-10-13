<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks certificates managed by the site's hosting environment.
 *
 * This provider does not invoke an ACME CA. It records the requested lifecycle
 * action so hosting-managed certificate status can be audited.
 */
final class DMS_SSL_Provider_Hosting implements DMS_SSL_Provider_Interface {

	public function issue_certificate( int $blog_id, string $domain ): bool {
		return DMS_SSL_Manager::get_instance()->record_certificate(
			$blog_id,
			$domain,
			'hosting',
			'pending'
		);
	}

	public function renew_certificate( int $blog_id, string $domain, int $days_before_expiry = 30 ): bool {
		return DMS_SSL_Manager::get_instance()->record_certificate(
			$blog_id,
			$domain,
			'hosting',
			'issued'
		);
	}
}
