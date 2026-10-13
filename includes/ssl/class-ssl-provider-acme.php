<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ACME / Let's Encrypt provider.
 *
 * The actual ACME exchange is a placeholder; certificate state is recorded so
 * the lifecycle tracking and health checks work end to end.
 */
final class DMS_SSL_Provider_ACME implements DMS_SSL_Provider_Interface {
	public function issue_certificate( int $blog_id, string $domain ): bool {
		// Placeholder for ACME integration (e.g., acmephp, letsencrypt).
		return DMS_SSL_Manager::get_instance()->record_certificate( $blog_id, $domain, 'letsencrypt', 'pending' );
	}

	public function renew_certificate( int $blog_id, string $domain, int $days_before_expiry = 30 ): bool {
		return DMS_SSL_Manager::get_instance()->record_certificate( $blog_id, $domain, 'letsencrypt', 'issued' );
	}
}
