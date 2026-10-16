<?php
/**
 * Global domain alias mapping resolver.
 *
 * Maps one domain to another (alias domains) using `mapping_values`
 * with `object_type=domain`. This allows a mapped domain to act as
 * an alias for another mapped domain.
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_Global_Domain_Mapping {

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
		add_action( 'template_redirect', array( $this, 'maybe_redirect_alias' ), 10 );
	}

	/**
	 * Redirects alias domain requests to the target mapped domain.
	 *
	 * @return void
	 */
	public function maybe_redirect_alias() {
		$mapping = $this->get_current_mapping();
		if ( ! $mapping ) {
			return;
		}

		// Check if this domain is an alias for another mapping.
		$mappings = DMS_Mapping_Values::get_mappings_for_object( 'domain', $mapping['id'] );
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

		// Redirect to the primary target mapping.
		$target = $this->pick_target( $mappings );
		if ( ! $target ) {
			return;
		}

		// Never redirect to the host/path we are already on: a mapping listed
		// as its own alias would otherwise bounce once per request.
		if ( $target['host'] === $current_host && $target['path'] === $current_path ) {
			return;
		}

		// Refuse to enter a cycle. Two mappings registered as each other's
		// alias (A -> B -> A), or any longer ring, would 301 back and forth
		// forever and make those hosts unreachable to every visitor. Follow the
		// chain from the target using the same selection rule as this
		// redirect; if it leads back here, serve the request where it stands
		// rather than bouncing. Detection is structural, so no marker has to
		// travel in the URL and ordinary alias redirects stay clean.
		if ( $this->alias_chain_loops( $target, (int) $mapping['id'] ) ) {
			return;
		}

		$target_url = $this->build_mapping_url( $target, $this->get_request_uri() );
		if ( $target_url ) {
			// Mapped domains are deliberately off-site, so this uses wp_redirect
			// rather than wp_safe_redirect. wp_safe_redirect() rejects any host
			// absent from the allowed list and silently falls back to
			// admin_url(), which would dump a visitor into wp-admin and can
			// bounce them straight back - see public/redirect-helpers.php,
			// which makes the same call for the same reason. The host comes
			// from a mapping validated by normalize_host() on write, and the
			// finished URL is escaped below.
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- off-site mapped host by design; host comes from a mapping validated by normalize_host() on write, URL escaped above. See public/redirect-helpers.php.
			wp_redirect( esc_url_raw( $target_url ), 301 );
			exit;
		}
	}

	/**
	 * Picks the mapping a chain hop resolves to: the primary one, or the first
	 * candidate when none is marked primary.
	 *
	 * Shared by the redirect itself and by the cycle check so both follow
	 * exactly the same route - spotting a loop along a path the redirect never
	 * takes would prove nothing.
	 *
	 * @param array $mappings Candidate mappings.
	 * @return array|null
	 */
	private function pick_target( $mappings ) {
		if ( empty( $mappings ) || ! is_array( $mappings ) ) {
			return null;
		}
		$primary = array_filter( $mappings, fn( $m ) => ! empty( $m['primary'] ) );
		return $primary ? array_values( $primary )[0] : $mappings[0];
	}

	/**
	 * Follows the alias chain from a candidate target to see whether it leads
	 * back to the mapping the request is already on.
	 *
	 * @param array $target    Candidate redirect target.
	 * @param int   $origin_id Mapping ID of the current request.
	 * @return bool True when redirecting would loop.
	 */
	private function alias_chain_loops( $target, $origin_id ) {
		$visited = array( (int) $origin_id );
		$hop     = $target;
		$steps   = 0;

		while ( $hop ) {
			if ( $steps >= 25 ) {
				// A ring this long is not a legitimate chain; treat it as
				// looping rather than risk a redirect storm.
				return true;
			}
			++$steps;

			$hop_id = isset( $hop['id'] ) ? (int) $hop['id'] : 0;
			if ( in_array( $hop_id, $visited, true ) ) {
				return true;
			}
			$visited[] = $hop_id;

			$hop = $this->pick_target(
				DMS_Mapping_Values::get_mappings_for_object( 'domain', $hop_id )
			);
		}

		// The chain ends on a canonical mapping, so the redirect terminates.
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
