<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
