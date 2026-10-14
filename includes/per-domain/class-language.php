<?php
/**
 * Per-domain language/locale switcher.
 *
 * Sets the WordPress locale based on the active mapping's `language` field.
 * Integrates with Polylang and WPML when active (runtime-guarded).
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_Per_Domain_Language {

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
		// Core locale filter — runs early in WP load.
		add_filter( 'locale', array( $this, 'filter_locale' ), 10 );

		// Polylang integration (if active).
		if ( function_exists( 'pll_current_language' ) ) {
			add_filter( 'pll_current_language', array( $this, 'filter_polylang_language' ), 20 );
		}

		// WPML integration (if active).
		if ( defined( 'ICL_LANGUAGE_CODE' ) ) {
			add_filter( 'wpml_current_language', array( $this, 'filter_wpml_language' ), 20 );
		}
	}

	/**
	 * Filters the WordPress locale for the current request.
	 *
	 * @param string $locale Current locale.
	 * @return string Modified locale.
	 */
	public function filter_locale( $locale ) {
		$mapping = $this->get_current_mapping();
		if ( ! $mapping || empty( $mapping['language'] ) ) {
			return $locale;
		}

		$lang = $mapping['language'];
		// Convert ISO 639-1 (e.g., 'en') to locale (e.g., 'en_US').
		// If already in locale format (e.g., 'en_US'), use as-is.
		$wp_locale = $this->to_wp_locale( $lang );
		if ( $wp_locale ) {
			return $wp_locale;
		}

		return $locale;
	}

	/**
	 * Filters Polylang's current language.
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
	 * Filters WPML's current language.
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
	 * Converts ISO 639-1 or locale to WordPress locale format.
	 *
	 * @param string $lang Language code (e.g., 'en', 'en_US', 'de').
	 * @return string|false WordPress locale or false.
	 */
	private function to_wp_locale( $lang ) {
		$lang = strtolower( trim( $lang ) );

		// Already a locale (e.g., en_US).
		if ( 1 === preg_match( '#^[a-z]{2}_[A-Z]{2}$#', $lang ) ) {
			return $lang;
		}

		// ISO 639-1 only (e.g., 'en') — map to default locale.
		if ( 1 === preg_match( '#^[a-z]{2}$#', $lang ) ) {
			$map = array(
				'en' => 'en_US',
				'de' => 'de_DE',
				'es' => 'es_ES',
				'fr' => 'fr_FR',
				'it' => 'it_IT',
				'pt' => 'pt_PT',
				'nl' => 'nl_NL',
				'pl' => 'pl_PL',
				'ru' => 'ru_RU',
				'ja' => 'ja',
				'zh' => 'zh_CN',
				'ko' => 'ko_KR',
			);
			return isset( $map[ $lang ] ) ? $map[ $lang ] : false;
		}

		return false;
	}

	/**
	 * Converts to Polylang language code format.
	 *
	 * @param string $lang Language code.
	 * @return string|false Polylang code or false.
	 */
	private function to_polylang_code( $lang ) {
		$lang = strtolower( trim( $lang ) );

		// Polylang uses slugs like 'en', 'en-us', 'de', 'de-de'.
		if ( 1 === preg_match( '#^[a-z]{2}(-[a-z]{2})?$#', $lang ) ) {
			return $lang;
		}

		// Convert locale (en_US) to Polylang (en-us).
		if ( 1 === preg_match( '#^[a-z]{2}_[A-Z]{2}$#', $lang ) ) {
			return strtolower( str_replace( '_', '-', $lang ) );
		}

		return false;
	}

	/**
	 * Converts to WPML language code format.
	 *
	 * @param string $lang Language code.
	 * @return string|false WPML code or false.
	 */
	private function to_wpml_code( $lang ) {
		$lang = strtolower( trim( $lang ) );

		// WPML uses codes like 'en', 'en-us', 'de', 'de-de'.
		if ( 1 === preg_match( '#^[a-z]{2}(-[a-z]{2})?$#', $lang ) ) {
			return $lang;
		}

		// Convert locale (en_US) to WPML (en).
		if ( 1 === preg_match( '#^[a-z]{2}_[A-Z]{2}$#', $lang ) ) {
			return substr( $lang, 0, 2 );
		}

		return false;
	}

	/**
	 * Checks if a Polylang language is active.
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
		foreach ( $languages as $lang ) {
			if ( isset( $lang['slug'] ) && $lang['slug'] === $code ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks if a WPML language is active.
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
		foreach ( $languages as $lang ) {
			if ( isset( $lang['language_code'] ) && $lang['language_code'] === $code ) {
				return true;
			}
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
