<?php
/**
 * Global archive/category mapping resolver.
 *
 * Resolves which mapped domain should serve a given archive (post type archive)
 * or category (taxonomy term) based on `mapping_values` entries.
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_Global_Archive_Mapping {

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
		// Template redirect to handle archive/category requests on mapped domains.
		add_action( 'template_redirect', array( $this, 'maybe_redirect_archive' ), 15 );
		// Modify archive links to point to mapped domains.
		add_filter( 'post_type_archive_link', array( $this, 'filter_archive_link' ), 20, 2 );
		add_filter( 'term_link', array( $this, 'filter_term_link' ), 20, 3 );
	}

	/**
	 * Redirects archive/category requests to the mapped domain if configured.
	 *
	 * @return void
	 */
	public function maybe_redirect_archive() {
		if ( ! is_archive() && ! is_category() && ! is_tag() && ! is_tax() ) {
			return;
		}

		$mapping = $this->get_current_mapping();
		if ( ! $mapping ) {
			return;
		}

		$object_id   = 0;
		$object_type = '';

		if ( is_post_type_archive() ) {
			$post_type = get_query_var( 'post_type' );
			if ( $post_type ) {
				$object_type = 'archive';
				$object_id   = $this->get_post_type_object_id( $post_type );
			}
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term && isset( $term->term_id ) ) {
				$object_type = is_category() ? 'category' : ( is_tag() ? 'tag' : 'term' );
				$object_id   = (int) $term->term_id;
			}
		}

		if ( ! $object_type || ! $object_id ) {
			return;
		}

		$mappings = DMS_Mapping_Values::get_mappings_for_object( $object_type, $object_id );
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

		// Redirect to the primary mapping for this object.
		$primary = array_filter( $mappings, fn( $m ) => $m['primary'] );
		$target  = $primary ? array_values( $primary )[0] : $mappings[0];

		$target_url = $this->build_mapping_url( $target, $this->get_request_uri() );
		if ( $target_url ) {
			wp_safe_redirect( $target_url, 301 );
			exit;
		}
	}

	/**
	 * Filters post type archive links to use mapped domains.
	 *
	 * @param string $link Archive link.
	 * @param string $post_type Post type.
	 * @return string Modified link.
	 */
	public function filter_archive_link( $link, $post_type ) {
		if ( ! DMS_Mode::is_single_site() ) {
			return $link;
		}
		$object_id = $this->get_post_type_object_id( $post_type );
		if ( ! $object_id ) {
			return $link;
		}
		return $this->rewrite_link_for_object( 'archive', $object_id, $link );
	}

	/**
	 * Filters taxonomy term links to use mapped domains.
	 *
	 * @param string $link Term link.
	 * @param WP_Term $term Term object.
	 * @param string $taxonomy Taxonomy name.
	 * @return string Modified link.
	 */
	public function filter_term_link( $link, $term, $taxonomy ) {
		if ( ! DMS_Mode::is_single_site() ) {
			return $link;
		}
		$object_type = 'category' === $taxonomy ? 'category' : ( 'post_tag' === $taxonomy ? 'tag' : 'term' );
		$object_id   = (int) $term->term_id;
		return $this->rewrite_link_for_object( $object_type, $object_id, $link );
	}

	/**
	 * Rewrites a link to point to the mapped domain for an object.
	 *
	 * @param string $object_type Object type (archive, category, tag, term).
	 * @param int $object_id Object ID.
	 * @param string $original_link Original link.
	 * @return string Rewritten link.
	 */
	private function rewrite_link_for_object( $object_type, $object_id, $original_link ) {
		$mappings = DMS_Mapping_Values::get_mappings_for_object( $object_type, $object_id );
		if ( empty( $mappings ) ) {
			return $original_link;
		}

		$primary = array_filter( $mappings, fn( $m ) => $m['primary'] );
		$target  = $primary ? array_values( $primary )[0] : $mappings[0];

		$parsed = wp_parse_url( $original_link );
		if ( ! $parsed || ! isset( $parsed['path'] ) ) {
			return $original_link;
		}

		$query   = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';
		$new_url = $this->build_mapping_url( $target, $parsed['path'] . $query );
		return $new_url ? $new_url : $original_link;
	}

	/**
	 * Gets the post type object ID for archive mapping.
	 *
	 * @param string $post_type Post type name.
	 * @return int Object ID (uses a hash of the post type name for consistency).
	 */
	private function get_post_type_object_id( $post_type ) {
		// Use a stable hash of the post type name as the object ID.
		return abs( crc32( 'post_type_' . $post_type ) );
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
