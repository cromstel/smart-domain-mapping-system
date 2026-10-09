<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * No-op provider: certificate lifecycle is tracked manually.
 */
final class DMS_SSL_Provider_None implements DMS_SSL_Provider_Interface {
	public function issue_certificate( int $blog_id, string $domain ): bool {
		return false;
	}

	public function renew_certificate( int $blog_id, string $domain, int $days_before_expiry = 30 ): bool {
		return false;
	}
}
