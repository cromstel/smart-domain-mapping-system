<?php
/**
 * Multilingual plugin integration guard (Polylang, WPML).
 *
 * Handles:
 * - Language per domain sync.
 * - URL rewriting for translated content.
 * - Hreflang updates for mapped domains.
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_Integration_Multilingual extends DMS_Integration_Guard {

	protected $slug = 'multilingual';
	protected $name = 'Multilingual Plugins (Polylang, WPML)';

	protected $detection = array(
		'classes'   => array(
			'Polylang',          // Polylang
			'SitePress',         // WPML
		),
		'functions' => array(
			'pll_current_language', // Polylang
			'icl_get_languages',    // WPML
		),
	);

	protected function setup_hooks() {
		// Polylang: filter language negotiation.
		if ( function_exists( 'pll_current_language' ) ) {
			add_filter( 'pll_current_language', array( $this, 'filter_polylang_language' ), 20 );
			add_filter( 'pll_get_flag', array( $this, 'filter_polylang_flag' ), 20 );
			add_filter( 'pll_home_url', array( $this, 'filter_polylang_home_url' ), 20 );
		}

		// WPML: filter language and URLs.
		if ( defined( 'ICL_LANGUAGE_CODE' ) ) {
			add_filter( 'wpml_current_language', array( $this, 'filter_wpml_language' ), 20 );
			add_filter( 'wpml_home_url', array( $this, 'filter_wpml_home_url' ), 20 );
			add_filter( 'wpml_element_link', array( $this, 'filter_wpml_element_link' ), 20, 3 );
		}

		// Hreflang updates for both.
		add_filter( 'wpseo_hreflang', array( $this, 'filter_hreflang' ), 20, 2 );
	}

	// ==================== Polylang ====================

	/**
	 * Filters Polylang current language based on domain.
	 *
	 * @param string $lang Current language code.
	 * @return string Modified language code.
	 */
	public function filter_polylang_language( $lang ) {
		$mapping = $this->get_current_mapping();
		if ( ! $mapping || empty( $mapping['language'] ) ) {
			return $lang;
		}

		$pll_lang = $this->to_polylang_code( $mapping['language'] );
		if ( $pll_lang && $this->is_polylang_language_active( $pll_lang ) ) {
			return $pll_lang;
		}

		return $lang;
	}

	/**
	 * Filters Polylang flag for mapped domain.
	 *
	 * @param string $flag Flag HTML.
	 * @return string Modified flag HTML.
	 */
	public function filter_polylang_flag( $flag ) {
		// Could add domain-specific flag logic here.
		return $flag;
	}

	/**
	 * Filters Polylang home URL for mapped domain.
	 *
	 * @param string $url Home URL.
	 * @return string Modified URL.
	 */
	public function filter_polylang_home_url( $url ) {
		$mapping = $this->get_current_mapping();
		if ( ! $mapping ) {
			return $url;
		}

		return $this->rewrite_url_for_mapping( $url, $mapping );
	}

	// ==================== WPML ====================

	/**
	 * Filters WPML current language based on domain.
	 *
	 * @param string $lang Current language code.
	 * @return string Modified language code.
	 */
	public function filter_wpml_language( $lang ) {
		$mapping = $this->get_current_mapping();
		if ( ! $mapping || empty( $mapping['language'] ) ) {
			return $lang;
		}

		$wpml_lang = $this->to_wpml_code( $mapping['language'] );
		if ( $wpml_lang && $this->is_wpml_language_active( $wpml_lang ) ) {
			return $wpml_lang;
		}

		return $lang;
	}

	/**
	 * Filters WPML home URL for mapped domain.
	 *
	 * @param string $url Home URL.
	 * @return string Modified URL.
	 */
	public function filter_wpml_home_url( $url ) {
		$mapping = $this->get_current_mapping();
		if ( ! $mapping ) {
			return $url;
		}

		return $this->rewrite_url_for_mapping( $url, $mapping );
	}

	/**
	 * Filters WPML element link for mapped domain.
	 *
	 * @param string $url Element URL.
	 * @param int $element_id Element ID.
	 * @param string $element_type Element type.
	 * @return string Modified URL.
	 */
	public function filter_wpml_element_link( $url, $element_id, $element_type ) {
		$mapping = $this->get_current_mapping();
		if ( ! $mapping ) {
			return $url;
		}

		return $this->rewrite_url_for_mapping( $url, $mapping );
	}

	// ==================== Shared: Hreflang ====================

	/**
	 * Filters hreflang URLs for mapped domains.
	 *
	 * @param string $url Hreflang URL.
	 * @param string $lang Language code.
	 * @return string Modified URL.
	 */
	public function filter_hreflang( $url, $lang ) {
		$mapping = $this->get_current_mapping();
		if ( ! $mapping ) {
			return $url;
		}

		return $this->rewrite_url_for_mapping( $url, $mapping );
	}

	// ==================== Helpers ====================

	/**
	 * Converts language code to Polylang format.
	 *
	 * @param string $lang Language code.
	 * @return string|false Polylang code or false.
	 */
	private function to_polylang_code( $lang ) {
		$lang = strtolower( trim( $lang ) );

		if ( 1 === preg_match( '#^[a-z]{2}(-[a-z]{2})?$#', $lang ) ) {
			return $lang;
		}

		if ( 1 === preg_match( '#^[a-z]{2}_[A-Z]{2}$#', $lang ) ) {
			return strtolower( str_replace( '_', '-', $lang ) );
		}

		return false;
	}

	/**
	 * Converts language code to WPML format.
	 *
	 * @param string $lang Language code.
	 * @return string|false WPML code or false.
	 */
	private function to_wpml_code( $lang ) {
		$lang = strtolower( trim( $lang ) );

		if ( 1 === preg_match( '#^[a-z]{2}(-[a-z]{2})?$#', $lang ) ) {
			return $lang;
		}

		if ( 1 === preg_match( '#^[a-z]{2}_[A-Z]{2}$#', $lang ) ) {
			return substr( $lang, 0, 2 );
		}

		return false;
	}

	/**
	 * Checks if Polylang language is active.
	 *
	 * @param string $code Language code.
	 * @return bool
	 */
	private function is_polylang_language_active( $code ) {
		if ( ! function_exists( 'pll_the_languages' ) ) {
			return false;
		}
		$languages = pll_the_languages( array( 'raw' => 1 ) );
		if ( ! is_array( $languages ) ) {
			return false;
		}
		foreach ( $languages as $l ) {
			if ( isset( $l['slug'] ) && $l['slug'] === $code ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks if WPML language is active.
	 *
	 * @param string $code Language code.
	 * @return bool
	 */
	private function is_wpml_language_active( $code ) {
		if ( ! defined( 'ICL_LANGUAGE_CODE' ) || ! function_exists( 'icl_get_languages' ) ) {
			return false;
		}
		$languages = icl_get_languages( 'skip_missing=1' );
		if ( ! is_array( $languages ) ) {
			return false;
		}
		foreach ( $languages as $l ) {
			if ( isset( $l['language_code'] ) && $l['language_code'] === $code ) {
				return true;
			}
		}
		return false;
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
