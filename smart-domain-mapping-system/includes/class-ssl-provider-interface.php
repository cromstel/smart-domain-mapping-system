<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for SSL certificate providers.
 *
 * Other integrations register via the `dm_ssl_provider` filter.
 */
interface DMS_SSL_Provider_Interface {
	public function issue_certificate( int $blog_id, string $domain ): bool;

	public function renew_certificate( int $blog_id, string $domain, int $days_before_expiry = 30 ): bool;
}
