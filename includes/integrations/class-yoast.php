<?php
/**
 * Yoast SEO integration guard.
 *
 * Handles:
 * - Canonical URL updates for mapped domains.
 * - Sitemap inclusion for mapped domains.
 * - Open Graph / Twitter card domain updates.
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_Integration_Yoast extends DMS_Integration_Guard {

	protected $slug = 'yoast';
	protected $name = 'Yoast SEO';

	protected $detection = array(
		'classes'   => array( 'WPSEO_Frontend', 'WPSEO_Options' ),
		'functions' => array( 'wpseo_replace_vars' ),
	);

	protected function setup_hooks() {
		// Filter canonical URL for mapped domains.
		add_filter( 'wpseo_canonical', array( $this, 'filter_canonical' ), 20 );

		// Filter Open Graph URL.
		add_filter( 'wpseo_opengraph_url', array( $this, 'filter_og_url' ), 20 );

		// Filter Twitter card URL.
		add_filter( 'wpseo_twitter_url', array( $this, 'filter_twitter_url' ), 20 );

		// Include mapped domains in sitemap.
		add_filter( 'wpseo_sitemap_urlimages', array( $this, 'filter_sitemap_urls' ), 20 );
	}

	/**
	 * Filters canonical URL to use mapped domain.
	 *
	 * @param string $canonical Canonical URL.
	 * @return string Modified canonical URL.
	 */
	public function filter_canonical( $canonical ) {
		$mapping = $this->get_current_mapping();
		if ( ! $mapping ) {
			return $canonical;
		}

		return $this->rewrite_url_for_mapping( $canonical, $mapping );
	}

	/**
	 * Filters Open Graph URL to use mapped domain.
	 *
	 * @param string $url Open Graph URL.
	 * @return string Modified URL.
	 */
	public function filter_og_url( $url ) {
		$mapping = $this->get_current_mapping();
		if ( ! $mapping ) {
			return $url;
		}

		return $this->rewrite_url_for_mapping( $url, $mapping );
	}

	/**
	 * Filters Twitter card URL to use mapped domain.
	 *
	 * @param string $url Twitter card URL.
	 * @return string Modified URL.
	 */
	public function filter_twitter_url( $url ) {
		$mapping = $this->get_current_mapping();
		if ( ! $mapping ) {
			return $url;
		}

		return $this->rewrite_url_for_mapping( $url, $mapping );
	}

	/**
	 * Filters sitemap URLs to include mapped domains.
	 *
	 * @param array $urls Sitemap URLs.
	 * @return array Modified URLs.
	 */
	public function filter_sitemap_urls( $urls ) {
		if ( ! DMS_Mode::is_single_site() ) {
			return $urls;
		}

		if ( ! class_exists( 'DMS_Single_Site_Mappings' ) ) {
			return $urls;
		}

		$mappings = DMS_Single_Site_Mappings::list_mappings( array( 'status' => 'active' ) );
		if ( is_wp_error( $mappings ) || empty( $mappings ) ) {
			return $urls;
		}

		$new_urls = array();
		foreach ( $urls as $url ) {
			$new_urls[] = $url;
			foreach ( $mappings as $mapping ) {
				$mapped_url = $this->rewrite_url_for_mapping( $url, $mapping );
				if ( $mapped_url !== $url ) {
					$new_urls[] = $mapped_url;
				}
			}
		}

		return $new_urls;
	}

	/**
	 * Rewrites a URL to use the mapped domain.
	 *
	 * @param string $url Original URL.
	 * @param array $mapping Mapping array.
	 * @return string Rewritten URL.
	 */
	private function rewrite_url_for_mapping( $url, $mapping ) {
		$parsed = wp_parse_url( $url );
		if ( ! $parsed || ! isset( $parsed['host'] ) ) {
			return $url;
		}

		// Only rewrite if the URL's host matches the site's original domain.
		$site_url    = get_site_url();
		$site_parsed = wp_parse_url( $site_url );
		if ( ! $site_parsed || ! isset( $site_parsed['host'] ) ) {
			return $url;
		}

		if ( strtolower( $parsed['host'] ) !== strtolower( $site_parsed['host'] ) ) {
			return $url;
		}

		$scheme = isset( $parsed['scheme'] ) ? $parsed['scheme'] : 'https';
		$path   = isset( $parsed['path'] ) ? $parsed['path'] : '/';
		$query  = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';

		$base_path = $mapping['path'] ? '/' . ltrim( $mapping['path'], '/' ) : '';

		return $scheme . '://' . $mapping['host'] . $base_path . $path . $query;
	}

	/**
	 * Gets the mapping for the current request host/path.
	 *
	 * @return array|null
	 */
	private function get_current_mapping() {
		if ( ! DMS_Mode::is_single_site() || ! class_exists( 'DMS_Single_Site_Mappings' ) ) {
			return null;
		}

		$host = $this->get_request_host();
		$path = $this->get_request_path();

		return DMS_Single_Site_Mappings::get_mapping_for_host( $host, $path );
	}

	/**
	 * Gets the request host.
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
	 * Gets the request path.
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
