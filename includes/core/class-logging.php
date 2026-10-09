<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_Logging {
	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function log( ?int $blog_id, ?string $domain, int $user_id, string $action, string $context = '' ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->base_prefix . DMS_TABLE_LOGS,
			array(
				'blog_id'    => $blog_id ? absint( $blog_id ) : null,
				'domain'     => sanitize_text_field( $domain ),
				'user_id'    => absint( $user_id ),
				'action'     => sanitize_text_field( $action ),
				'context'    => sanitize_textarea_field( $context ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s' )
		);
	}
}
