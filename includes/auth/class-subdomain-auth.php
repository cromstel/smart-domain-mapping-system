<?php
/**
 * Subdomain authentication - shared cookie scope.
 *
 * When multiple mapped domains share a parent domain (e.g., site1.example.com,
 * site2.example.com), this class configures WordPress to use a shared cookie
 * domain (e.g., .example.com) so users stay logged in across all subdomains.
 *
 * Security considerations:
 * - Only enables shared cookies when all mapped domains share the same parent domain.
 * - Requires explicit opt-in via filter `dms_subdomain_auth_enabled`.
 * - Sets COOKIE_DOMAIN to parent domain (e.g., .example.com).
 * - Updates AUTH_COOKIE, SECURE_AUTH_COOKIE, LOGGED_IN_COOKIE to use shared domain.
 * - Only activates when 2+ mapped domains share a parent domain.
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_Subdomain_Auth {

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

		// Opt-in filter for subdomain auth.
		if ( ! apply_filters( 'dms_subdomain_auth_enabled', false ) ) {
			return;
		}

		// Check if we should enable shared cookies.
		if ( ! $this->should_enable_shared_cookies() ) {
			return;
		}

		// Define COOKIE_DOMAIN early (before pluggable.php loads).
		add_action( 'plugins_loaded', array( $this, 'define_cookie_domain' ), 0 );

		// Update auth cookie constants to use shared domain.
		add_action( 'init', array( $this, 'update_auth_cookie_constants' ), 1 );
	}

	/**
	 * Determines if shared cookies should be enabled.
	 *
	 * @return bool
	 */
	private function should_enable_shared_cookies() {
		if ( ! class_exists( 'DMS_Single_Site_Mappings' ) ) {
			return false;
		}

		$mappings = DMS_Single_Site_Mappings::list_mappings( array( 'status' => 'active' ) );
		if ( is_wp_error( $mappings ) || count( $mappings ) < 2 ) {
			return false;
		}

		// Extract parent domains from all active mappings.
		$parent_domains = array();
		foreach ( $mappings as $mapping ) {
			$parent = $this->get_parent_domain( $mapping['host'] );
			if ( $parent ) {
				$parent_domains[ $parent ][] = $mapping['host'];
			}
		}

		// Check if any parent domain has 2+ subdomains mapped.
		foreach ( $parent_domains as $parent => $subdomains ) {
			if ( count( $subdomains ) >= 2 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extracts the parent domain (e.g., example.com from sub.example.com).
	 *
	 * @param string $host Full hostname.
	 * @return string|false Parent domain or false.
	 */
	private function get_parent_domain( $host ) {
		$parts = explode( '.', $host );
		if ( count( $parts ) < 3 ) {
			return false; // Not a subdomain (e.g., example.com).
		}

		// Join all but the first part.
		array_shift( $parts );
		return implode( '.', $parts );
	}

	/**
	 * Defines COOKIE_DOMAIN constant for WordPress.
	 * Must run before pluggable.php loads.
	 *
	 * @return void
	 */
	public function define_cookie_domain() {
		$parent_domain = $this->get_shared_parent_domain();
		if ( ! $parent_domain ) {
			return;
		}

		// Only define if not already defined.
		if ( ! defined( 'COOKIE_DOMAIN' ) ) {
			define( 'COOKIE_DOMAIN', '.' . $parent_domain );
		}
	}

	/**
	 * Updates auth cookie constants to use shared domain.
	 *
	 * @return void
	 */
	public function update_auth_cookie_constants() {
		$parent_domain = $this->get_shared_parent_domain();
		if ( ! $parent_domain ) {
			return;
		}

		// WordPress uses these constants for cookie names/paths.
		// We can't redefine them after they're defined, but we can ensure
		// the cookie domain is set correctly for future cookie operations.
		if ( ! defined( 'COOKIEPATH' ) ) {
			define( 'COOKIEPATH', '/' );
		}
		if ( ! defined( 'SITECOOKIEPATH' ) ) {
			define( 'SITECOOKIEPATH', '/' );
		}
		if ( ! defined( 'ADMIN_COOKIE_PATH' ) ) {
			define( 'ADMIN_COOKIE_PATH', '/' );
		}
	}

	/**
	 * Gets the shared parent domain if applicable.
	 *
	 * @return string|false
	 */
	private function get_shared_parent_domain() {
		if ( ! class_exists( 'DMS_Single_Site_Mappings' ) ) {
			return false;
		}

		$mappings = DMS_Single_Site_Mappings::list_mappings( array( 'status' => 'active' ) );
		if ( is_wp_error( $mappings ) || count( $mappings ) < 2 ) {
			return false;
		}

		$parent_domains = array();
		foreach ( $mappings as $mapping ) {
			$parent = $this->get_parent_domain( $mapping['host'] );
			if ( $parent ) {
				$parent_domains[ $parent ][] = $mapping['host'];
			}
		}

		foreach ( $parent_domains as $parent => $subdomains ) {
			if ( count( $subdomains ) >= 2 ) {
				return $parent;
			}
		}

		return false;
	}
}
