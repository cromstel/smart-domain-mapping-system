<?php
/**
 * Global domain alias mapping resolver.
 *
 * Maps one domain to another (alias domains) using `mapping_values`
 * with `object_type=domain`. This allows a mapped domain to act as
 * an alias for another mapped domain.
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_Global_Domain_Mapping {

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
		add_action( 'template_redirect', array( $this, 'maybe_redirect_alias' ), 10 );
	}

	/**
	 * Redirects alias domain requests to the target mapped domain.
	 *
	 * @return void
	 */
	public function maybe_redirect_alias() {
		$mapping = $this->get_current_mapping();
		if ( ! $mapping ) {
			return;
		}

		// Check if this domain is an alias for another mapping.
		$mappings = DMS_Mapping_Values::get_mappings_for_object( 'domain', $mapping['id'] );
		if ( empty( $mappings ) ) {
			return;
		}

		// Find the mapping that matches the current request domain.
		$current_host = $this->get_request_host();
		$current_path = $this->get_request_path();

		foreach ( $mappings as $map ) {
			if ( $map['host'] === $current_host && $map['path'] === $current_path ) {
				return; // Already on the correct mapped domain.
			}
		}

		// Redirect to the primary target mapping.
		$primary = array_filter( $mappings, fn( $m ) => $m['primary'] );
		$target  = $primary ? array_values( $primary )[0] : $mappings[0];

		$target_url = $this->build_mapping_url( $target, $this->get_request_uri() );
		if ( $target_url ) {
			wp_safe_redirect( $target_url, 301 );
			exit;
		}
	}

	/**
	 * Builds a full URL for a mapping.
	 *
	 * @param array $mapping Mapping array with host, path.
	 * @param string $uri Request URI (path + query).
	 * @return string|false Full URL or false.
	 */
	private function build_mapping_url( $mapping, $uri ) {
		if ( empty( $mapping['host'] ) ) {
			return false;
		}
		$scheme = is_ssl() ? 'https' : 'http';
		$path   = $mapping['path'] ? '/' . ltrim( $mapping['path'], '/' ) : '';
		return $scheme . '://' . $mapping['host'] . $path . $uri;
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

	/**
	 * Gets the full request URI (path + query).
	 *
	 * @return string
	 */
	private function get_request_uri() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';
		return $uri;
	}
}
