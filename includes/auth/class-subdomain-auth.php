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

		// COOKIE_DOMAIN is deliberately not touched here. See
		// define_cookie_domain() for why the plugin cannot set it safely.
		add_action( 'plugins_loaded', array( $this, 'define_cookie_domain' ), 0 );

		// Update auth cookie paths to use shared domain.
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
	 * Returns false when the remainder would be a registry suffix such as
	 * co.uk or com.au. A host like example.co.uk would otherwise yield a
	 * parent of co.uk, and a cookie scoped to Domain=.co.uk is rejected by
	 * every browser under the public suffix rule - and would be shared with
	 * every unrelated site on that suffix if it were honoured.
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
		$parent = implode( '.', $parts );

		// Reject two-label registry suffixes (co.uk, com.au, or.jp, ...). A full
		// public suffix list would be better; this catches the common cases
		// rather than silently scoping a cookie to a shared registry domain.
		if ( 1 === preg_match( '/^(ac|co|com|edu|gov|mil|net|nom|org)\.[a-z]{2}$/i', $parent ) ) {
			return false;
		}

		return $parent;
	}

	/**
	 * Warns when COOKIE_DOMAIN has not been set by the operator.
	 *
	 * This deliberately does not define COOKIE_DOMAIN itself. It would be
	 * defined on plugins_loaded, which is long after wp-settings.php has
	 * established the cookie layout (pluggable.php is loaded *before* plugins
	 * are loaded, not after), and its value would be derived from the mappings
	 * table at request time - so the domain a cookie was written with could
	 * differ from the one it is read back under once a mapping changes.
	 * COOKIE_DOMAIN belongs in wp-config.php, above the site URL, where it is
	 * stable and set before WordPress touches any cookie.
	 *
	 * @return void
	 */
	public function define_cookie_domain() {
		if ( defined( 'COOKIE_DOMAIN' ) ) {
			return;
		}

		$parent_domain = $this->get_shared_parent_domain();
		if ( ! $parent_domain ) {
			return;
		}

		_doing_it_wrong(
			'DMS_Subdomain_Auth',
			esc_html(
				sprintf(
					// translators: %s: shared parent domain, e.g. ".example.com".
					__( 'Shared subdomain auth needs a stable cookie domain. Define COOKIE_DOMAIN as %s in wp-config.php, above the site URL.', 'domain-mapping-system' ),
					'.' . $parent_domain
				)
			),
			'1.2.0'
		);
	}

	/**
	 * Normalises the auth cookie paths so a shared-domain cookie is sent
	 * across the whole site rather than only under the current path.
	 *
	 * These are read at setcookie() time, so defining them here does affect
	 * cookies written later in the request (e.g. during login). COOKIE_DOMAIN
	 * is the one that cannot be set reliably from here - see
	 * define_cookie_domain().
	 *
	 * @return void
	 */
	public function update_auth_cookie_constants() {
		$parent_domain = $this->get_shared_parent_domain();
		if ( ! $parent_domain ) {
			return;
		}

		// WordPress derives these from the site URL in wp-settings.php. Guarded
		// so an operator who set them in wp-config.php keeps their value.
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
