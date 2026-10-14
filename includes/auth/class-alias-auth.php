<?php
/**
 * Alias domain authentication - cross-domain session sync.
 *
 * When a user logs in on one mapped domain, this syncs the authentication
 * cookie to other mapped domains (alias domains) so the user stays logged in
 * across all mapped domains.
 *
 * Security considerations:
 * - Only syncs for mapped domains that belong to the same site (verified via mapping_values).
 * - Uses secure, httponly, samesite=lax cookies.
 * - Short TTL (1 hour) for sync cookies.
 * - Requires explicit opt-in via filter `dms_alias_auth_enabled`.
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_Alias_Auth {

	private static $instance = null;

	/**
	 * Cookie name prefix for alias auth sync.
	 */
	const COOKIE_PREFIX = 'dms_alias_auth_';

	/**
	 * Cookie TTL in seconds (1 hour).
	 */
	const COOKIE_TTL = HOUR_IN_SECONDS;

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

		// Opt-in filter for alias auth.
		if ( ! apply_filters( 'dms_alias_auth_enabled', false ) ) {
			return;
		}

		// Sync auth cookie on login.
		add_action( 'wp_login', array( $this, 'sync_on_login' ), 10, 2 );

		// Sync auth cookie on logout.
		add_action( 'wp_logout', array( $this, 'sync_on_logout' ) );

		// Validate incoming alias auth cookie.
		add_action( 'init', array( $this, 'validate_alias_cookie' ), 5 );
	}

	/**
	 * Syncs authentication cookie to all alias domains on login.
	 *
	 * @param string $user_login User login.
	 * @param WP_User $user User object.
	 * @return void
	 */
	public function sync_on_login( $user_login, $user ) {
		$alias_domains = $this->get_alias_domains();
		if ( empty( $alias_domains ) ) {
			return;
		}

		$auth_cookie = wp_generate_auth_cookie( $user->ID, self::COOKIE_TTL, 'logged_in', $user->user_login );
		if ( ! $auth_cookie ) {
			return;
		}

		foreach ( $alias_domains as $domain ) {
			$this->set_alias_cookie( $domain, $auth_cookie );
		}
	}

	/**
	 * Clears authentication cookie from all alias domains on logout.
	 *
	 * @return void
	 */
	public function sync_on_logout() {
		$alias_domains = $this->get_alias_domains();
		if ( empty( $alias_domains ) ) {
			return;
		}

		foreach ( $alias_domains as $domain ) {
			$this->clear_alias_cookie( $domain );
		}
	}

	/**
	 * Validates incoming alias auth cookie and logs user in if valid.
	 *
	 * @return void
	 */
	public function validate_alias_cookie() {
		if ( is_user_logged_in() ) {
			return;
		}

		$host        = $this->get_request_host();
		$cookie_name = self::COOKIE_PREFIX . md5( $host );

		if ( empty( $_COOKIE[ $cookie_name ] ) ) {
			return;
		}

		$cookie_value = sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
		if ( ! $cookie_value ) {
			return;
		}

		// Parse cookie: user_id|expiration|hash
		$parts = explode( '|', $cookie_value );
		if ( count( $parts ) !== 3 ) {
			return;
		}

		list( $user_id, $expiration, $hash ) = $parts;

		if ( time() > (int) $expiration ) {
			// Expired - clear it.
			$this->clear_alias_cookie( $host );
			return;
		}

		// Verify hash.
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return;
		}

		$expected_hash = wp_hash( $user_id . '|' . $expiration . '|' . $user->user_login . '|' . $user->user_pass );
		if ( ! hash_equals( $expected_hash, $hash ) ) {
			return;
		}

		// Valid cookie - log user in.
		wp_set_current_user( (int) $user_id );
		wp_set_auth_cookie( (int) $user_id, true );

		// Clean up the alias cookie after use.
		$this->clear_alias_cookie( $host );
	}

	/**
	 * Gets all alias domains for the current site.
	 *
	 * @return array Array of domain hostnames.
	 */
	private function get_alias_domains() {
		if ( ! class_exists( 'DMS_Mapping_Values' ) ) {
			return array();
		}

		$current_mapping = $this->get_current_mapping();
		if ( ! $current_mapping ) {
			return array();
		}

		// Find domains that have this domain as an alias (object_type=domain).
		$mappings = DMS_Mapping_Values::get_mappings_for_object( 'domain', $current_mapping['id'] );
		if ( empty( $mappings ) ) {
			return array();
		}

		$domains = array();
		foreach ( $mappings as $map ) {
			if ( $map['host'] !== $this->get_request_host() ) {
				$domains[] = $map['host'];
			}
		}

		return $domains;
	}

	/**
	 * Sets the alias auth cookie for a domain.
	 *
	 * @param string $domain Target domain.
	 * @param string $auth_cookie WordPress auth cookie value.
	 * @return void
	 */
	private function set_alias_cookie( $domain, $auth_cookie ) {
		$cookie_name = self::COOKIE_PREFIX . md5( $domain );
		$expiration  = time() + self::COOKIE_TTL;

		// Create a hash for validation.
		$hash = wp_hash( $auth_cookie . '|' . $expiration . '|' . $domain );

		$cookie_value = $auth_cookie . '|' . $expiration . '|' . $hash;

		// Set cookie with secure flags.
		setcookie(
			$cookie_name,
			$cookie_value,
			$expiration,
			'/',
			$domain,
			is_ssl(),
			true,  // httponly
			false, // samesite - use default (lax)
			'Lax'
		);
	}

	/**
	 * Clears the alias auth cookie for a domain.
	 *
	 * @param string $domain Target domain.
	 * @return void
	 */
	private function clear_alias_cookie( $domain ) {
		$cookie_name = self::COOKIE_PREFIX . md5( $domain );

		setcookie(
			$cookie_name,
			'',
			time() - YEAR_IN_SECONDS,
			'/',
			$domain,
			is_ssl(),
			true,
			false,
			'Lax'
		);
	}

	/**
	 * Gets the current request host.
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
	 * Gets the request path (normalized).
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
