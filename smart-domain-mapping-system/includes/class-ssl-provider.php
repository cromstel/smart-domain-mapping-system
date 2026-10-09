<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface DMS_SSL_Provider_Interface {
	public function issue_certificate( int $blog_id, string $domain ): bool;
	public function renew_certificate( int $blog_id, string $domain, int $days_before_expiry = 30 ): bool;
}

final class DMS_SSL_Provider_None implements DMS_SSL_Provider_Interface {
	public function issue_certificate( int $blog_id, string $domain ): bool {
		return false;
	}
	public function renew_certificate( int $blog_id, string $domain, int $days_before_expiry = 30 ): bool {
		return false;
	}
}

final class DMS_SSL_Provider_ACME implements DMS_SSL_Provider_Interface {
	public function issue_certificate( int $blog_id, string $domain ): bool {
		// Placeholder for ACME integration (e.g., acmephp, letsencrypt).
		DMS_SSL_Manager::get_instance()->record_certificate( $blog_id, $domain, 'letsencrypt', 'pending' );
		return true;
	}
	public function renew_certificate( int $blog_id, string $domain, int $days_before_expiry = 30 ): bool {
		DMS_SSL_Manager::get_instance()->record_certificate( $blog_id, $domain, 'letsencrypt', 'issued' );
		return true;
	}
}

/**
 * Provider factory wired to the `ssl_provider` site option.
 *
 * Other integrations register via the `dm_ssl_provider` filter
 * (spec: "Hooks allow registration of other providers").
 */
final class DMS_SSL_Providers {
	public static function get(): DMS_SSL_Provider_Interface {
		$options = get_site_option( 'dm_options', array() );
		$key     = isset( $options['ssl_provider'] ) ? sanitize_key( $options['ssl_provider'] ) : 'none';

		switch ( $key ) {
			case 'acme':
				$provider = new DMS_SSL_Provider_ACME();
				break;
			default:
				$provider = new DMS_SSL_Provider_None();
		}

		$provider = apply_filters( 'dm_ssl_provider', $provider, $key );
		return $provider instanceof DMS_SSL_Provider_Interface ? $provider : new DMS_SSL_Provider_None();
	}
}
