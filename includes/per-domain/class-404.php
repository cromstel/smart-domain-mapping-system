<?php
/**
 * Per-domain custom 404 handler.
 *
 * When a 404 occurs on a mapped domain, redirects to the configured
 * 404 target (post ID or absolute URL) if set.
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_Per_Domain_404 {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( ! DMS_Mode::is_single_site() ) {
			return;
		}
		// Hook into template_redirect to intercept 404s.
		add_action( 'template_redirect', array( $this, 'handle_404' ), 20 );
	}

	/**
	 * Handles 404 redirects for mapped domains.
	 *
	 * @return void
	 */
	public function handle_404() {
		// Only act on actual 404s.
		if ( ! is_404() ) {
			return;
		}

		$mapping = $this->get_current_mapping();
		if ( ! $mapping || empty( $mapping['redirect_404'] ) ) {
			return;
		}

		$target = $mapping['redirect_404'];
		$url    = $this->resolve_redirect_target( $target );

		if ( $url ) {
			wp_safe_redirect( $url, 302 );
			exit;
		}
	}

	/**
	 * Resolves the redirect_404 value to a URL.
	 *
	 * @param string $target Post ID (numeric) or absolute URL.
	 * @return string|false Resolved URL or false.
	 */
	private function resolve_redirect_target( $target ) {
		$target = trim( $target );
		if ( '' === $target ) {
			return false;
		}

		// Numeric = post ID.
		if ( ctype_digit( $target ) ) {
			$post_id = (int) $target;
			$url     = get_permalink( $post_id );
			return $url ? $url : false;
		}

		// Otherwise treat as URL — validate it.
		$url = esc_url_raw( $target );
		if ( $url && $url === $target ) {
			return $url;
		}

		return false;
	}

	/**
	 * Gets the mapping for the current request host/path.
	 *
	 * @return array|null
	 */
	private function get_current_mapping() {
		if ( ! class_exists( 'DMS_Single_Site_Mappings' ) ) {
			return null;
		}

		$host = $this->get_request_host();
		$path = $this->get_request_path();

		return DMS_Single_Site_Mappings::get_mapping_for_host( $host, $path );
	}

	/**
	 * Gets the request host (normalized).
	 *
	 * @return string
	 */
	private function get_request_host() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';
		if ( false !== strpos( $host, ':' ) ) {
			$host = substr( $host, 0, strpos( $host, ':' ) );
		}
		return strtolower( $host );
	}

	/**
	 * Gets the request path (normalized, without leading/trailing slash).
	 *
	 * @return string
	 */
	private function get_request_path() {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
		if ( false !== strpos( $path, '?' ) ) {
			$path = substr( $path, 0, strpos( $path, '?' ) );
		}
		$path = trim( $path, '/' );
		return strtolower( $path );
	}
}
