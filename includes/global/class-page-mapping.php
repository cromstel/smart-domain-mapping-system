<?php
/**
 * Global parent page mapping resolver.
 *
 * Maps all child pages of a parent page to a specific mapped domain.
 * Uses `mapping_values` with `object_type=page` and the parent page ID.
 * Recursively finds all descendants.
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_Global_Page_Mapping {

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
		add_action( 'template_redirect', array( $this, 'maybe_redirect_page' ), 15 );
		add_filter( 'page_link', array( $this, 'filter_page_link' ), 20, 2 );
	}

	/**
	 * Redirects child page requests to the mapped domain if configured.
	 *
	 * @return void
	 */
	public function maybe_redirect_page() {
		if ( ! is_singular( 'page' ) ) {
			return;
		}

		$mapping = $this->get_current_mapping();
		if ( ! $mapping ) {
			return;
		}

		$page_id = get_queried_object_id();
		if ( ! $page_id ) {
			return;
		}

		// Find if this page is a descendant of a mapped parent.
		$mapped_parent = $this->find_mapped_parent( $page_id );
		if ( ! $mapped_parent ) {
			return;
		}

		list( $parent_id, $target_mappings ) = $mapped_parent;

		// Find the mapping that matches the current request domain.
		$current_host = $this->get_request_host();
		$current_path = $this->get_request_path();

		foreach ( $target_mappings as $map ) {
			if ( $map['host'] === $current_host && $map['path'] === $current_path ) {
				return; // Already on the correct mapped domain.
			}
		}

		// Redirect to the primary mapping for this parent.
		$primary = array_filter( $target_mappings, fn( $m ) => $m['primary'] );
		$target  = $primary ? array_values( $primary )[0] : $target_mappings[0];

		$target_url = $this->build_mapping_url( $target, $this->get_request_uri() );
		if ( $target_url ) {
			wp_safe_redirect( $target_url, 301 );
			exit;
		}
	}

	/**
	 * Filters page links to use mapped domains for child pages.
	 *
	 * @param string $link    Page link.
	 * @param int    $page_id Page ID.
	 * @return string Modified link.
	 */
	public function filter_page_link( $link, $page_id ) {
		if ( ! DMS_Mode::is_single_site() ) {
			return $link;
		}

		$mapped_parent = $this->find_mapped_parent( $page_id );
		if ( ! $mapped_parent ) {
			return $link;
		}

		list( $parent_id, $target_mappings ) = $mapped_parent;
		$primary                             = array_filter( $target_mappings, fn( $m ) => $m['primary'] );
		$target                              = $primary ? array_values( $primary )[0] : $target_mappings[0];

		$parsed = wp_parse_url( $link );
		if ( ! $parsed || ! isset( $parsed['path'] ) ) {
			return $link;
		}

		$query   = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';
		$new_url = $this->build_mapping_url( $target, $parsed['path'] . $query );
		return $new_url ? $new_url : $link;
	}

	/**
	 * Finds the nearest mapped parent for a page.
	 *
	 * @param int $page_id Page ID.
	 * @return array|false [parent_id, mappings] or false.
	 */
	private function find_mapped_parent( int $page_id ) {
		$page = get_post( $page_id );
		if ( ! $page || 'page' !== $page->post_type ) {
			return false;
		}

		$current_id = $page_id;
		while ( $current_id ) {
			$mappings = DMS_Mapping_Values::get_mappings_for_object( 'page', $current_id );
			if ( ! empty( $mappings ) ) {
				return array( $current_id, $mappings );
			}

			$parent = get_post( $current_id );
			if ( ! $parent || 0 === $parent->post_parent ) {
				break;
			}
			$current_id = $parent->post_parent;
		}

		return false;
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
