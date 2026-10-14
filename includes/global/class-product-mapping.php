<?php
/**
 * Global WooCommerce product mapping resolver.
 *
 * Maps WooCommerce products to specific mapped domains.
 * Uses `mapping_values` with `object_type=product`.
 * Runtime-guarded: only loads if WooCommerce is active.
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_Global_Product_Mapping {

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
		// Guard: only load if WooCommerce is active.
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		add_action( 'template_redirect', array( $this, 'maybe_redirect_product' ), 15 );
		add_filter( 'woocommerce_product_permalink', array( $this, 'filter_product_link' ), 20, 2 );
		add_filter( 'woocommerce_cart_item_permalink', array( $this, 'filter_cart_item_link' ), 20, 2 );
	}

	/**
	 * Redirects product requests to the mapped domain if configured.
	 *
	 * @return void
	 */
	public function maybe_redirect_product() {
		if ( ! is_singular( 'product' ) ) {
			return;
		}

		$mapping = $this->get_current_mapping();
		if ( ! $mapping ) {
			return;
		}

		$product_id = get_queried_object_id();
		if ( ! $product_id ) {
			return;
		}

		$mappings = DMS_Mapping_Values::get_mappings_for_object( 'product', $product_id );
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

		// Redirect to the primary mapping for this product.
		$primary = array_filter( $mappings, fn( $m ) => $m['primary'] );
		$target  = $primary ? array_values( $primary )[0] : $mappings[0];

		$target_url = $this->build_mapping_url( $target, $this->get_request_uri() );
		if ( $target_url ) {
			wp_safe_redirect( $target_url, 301 );
			exit;
		}
	}

	/**
	 * Filters product permalinks to use mapped domains.
	 *
	 * @param string $permalink Product permalink.
	 * @param WC_Product $product Product object.
	 * @return string Modified permalink.
	 */
	public function filter_product_link( $permalink, $product ) {
		if ( ! DMS_Mode::is_single_site() ) {
			return $permalink;
		}
		$product_id = $product->get_id();
		return $this->rewrite_link_for_product( $product_id, $permalink );
	}

	/**
	 * Filters cart item permalinks to use mapped domains.
	 *
	 * @param string $permalink Cart item permalink.
	 * @param array $cart_item Cart item data.
	 * @return string Modified permalink.
	 */
	public function filter_cart_item_link( $permalink, $cart_item ) {
		if ( ! DMS_Mode::is_single_site() ) {
			return $permalink;
		}
		if ( ! isset( $cart_item['product_id'] ) ) {
			return $permalink;
		}
		return $this->rewrite_link_for_product( $cart_item['product_id'], $permalink );
	}

	/**
	 * Rewrites a product link to point to the mapped domain.
	 *
	 * @param int $product_id Product ID.
	 * @param string $original_link Original link.
	 * @return string Rewritten link.
	 */
	private function rewrite_link_for_product( $product_id, $original_link ) {
		$mappings = DMS_Mapping_Values::get_mappings_for_object( 'product', $product_id );
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
