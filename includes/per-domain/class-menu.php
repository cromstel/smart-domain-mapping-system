<?php
/**
 * Per-domain menu swapper.
 *
 * Filters `wp_nav_menu_args` to replace the requested menu with the
 * per-domain menu (menu_id) when viewing a mapped domain.
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_Per_Domain_Menu {

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
		add_filter( 'wp_nav_menu_args', array( $this, 'filter_menu_args' ), 20, 1 );
	}

	/**
	 * Filters menu arguments to inject the per-domain menu.
	 *
	 * @param array $args Menu arguments passed to wp_nav_menu().
	 * @return array Modified arguments.
	 */
	public function filter_menu_args( $args ) {
		$mapping = $this->get_current_mapping();
		if ( ! $mapping || empty( $mapping['menu_id'] ) ) {
			return $args;
		}

		$menu_id = (int) $mapping['menu_id'];
		$menu    = wp_get_nav_menu_object( $menu_id );
		if ( ! $menu ) {
			return $args;
		}

		// Override the menu location/menu parameter.
		$args['menu'] = $menu_id;

		return $args;
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
