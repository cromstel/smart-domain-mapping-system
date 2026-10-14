<?php
/**
 * Per-domain custom head/body code renderer.
 *
 * Outputs the `custom_html` field from the active mapping into `wp_head`
 * and `wp_body_open` (for code that needs to be in <body>).
 *
 * The HTML is pre-sanitized on save via DMS_Single_Site_Mappings::sanitize_custom_html()
 * using a strict wp_kses allow-list. This class only outputs what's already stored.
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_Per_Domain_Custom_Code {

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
		add_action( 'wp_head', array( $this, 'output_head_code' ), 20 );
		add_action( 'wp_body_open', array( $this, 'output_body_code' ), 20 );
	}

	/**
	 * Determines if we're on a mapped domain and outputs the custom head code.
	 *
	 * @return void
	 */
	public function output_head_code() {
		$mapping = $this->get_current_mapping();
		if ( ! $mapping || empty( $mapping['custom_html'] ) ) {
			return;
		}

		// Only output in <head> — the field may contain both head and body snippets.
		// We assume the admin knows to put <meta>, <link>, <style>, <base> in the field.
		echo wp_kses( $mapping['custom_html'], DMS_Single_Site_Mappings::ALLOWED_HTML );
	}

	/**
	 * Outputs custom body-open code (for snippets that must be right after <body>).
	 *
	 * @return void
	 */
	public function output_body_code() {
		$mapping = $this->get_current_mapping();
		if ( ! $mapping || empty( $mapping['custom_html'] ) ) {
			return;
		}

		// For body-open, we output the same field. Admins can use conditional
		// comments or data attributes to target specific locations if needed.
		// A more advanced implementation could split head/body into separate fields.
		echo wp_kses( $mapping['custom_html'], DMS_Single_Site_Mappings::ALLOWED_HTML );
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
		// Remove port if present.
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
		// Strip query string.
		if ( false !== strpos( $path, '?' ) ) {
			$path = substr( $path, 0, strpos( $path, '?' ) );
		}
		$path = trim( $path, '/' );
		return strtolower( $path );
	}
}
