<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_SSL_Manager {
	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function record_certificate( int $blog_id, string $domain, string $provider = 'none', string $status = 'pending', ?string $expires_at = null ) {
		global $wpdb;
		$table = $wpdb->base_prefix . DMS_TABLE_SSL;
		$wpdb->insert(
			$table,
			array(
				'blog_id'    => absint( $blog_id ),
				'domain'     => sanitize_text_field( $domain ),
				'provider'   => sanitize_key( $provider ),
				'status'     => sanitize_key( $status ),
				'issued_at'  => current_time( 'mysql' ),
				'expires_at' => $expires_at ? sanitize_text_field( $expires_at ) : null,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		DMS_Logging::get_instance()->log(
			$blog_id,
			$domain,
			get_current_user_id(),
			'certificate.recorded',
			wp_json_encode(
				array(
					'provider' => $provider,
					'status'   => $status,
				)
			)
		);
	}

	public function get_certificate( int $blog_id, string $domain ) {
		global $wpdb;
		$table = $wpdb->base_prefix . DMS_TABLE_SSL;
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE blog_id = %d AND domain = %s ORDER BY issued_at DESC LIMIT 1",
				absint( $blog_id ),
				sanitize_text_field( $domain )
			)
		);
		return $row ? $row : false;
	}

	public function is_near_expiry( int $blog_id, string $domain, int $days = 30 ): bool {
		$row = $this->get_certificate( $blog_id, $domain );
		if ( ! $row || empty( $row->expires_at ) ) {
			return false;
		}
		$expires = strtotime( $row->expires_at );
		return false !== $expires && ( $expires - time() ) < ( $days * DAY_IN_SECONDS );
	}
}
