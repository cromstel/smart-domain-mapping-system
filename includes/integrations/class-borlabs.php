<?php
/**
 * Borlabs Cookie integration guard.
 *
 * Handles cookie consent domain configuration for mapped domains.
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_Integration_Borlabs extends DMS_Integration_Guard {

	protected $slug = 'borlabs';
	protected $name = 'Borlabs Cookie';

	protected $detection = array(
		'classes'   => array( 'BorlabsCookie' ),
		'functions' => array( 'borlabs_cookie' ),
	);

	protected function setup_hooks() {
		// Filter Borlabs cookie domain to include all mapped domains.
		add_filter( 'borlabs_cookie_domain', array( $this, 'filter_cookie_domain' ), 20 );

		// Add mapped domains to allowed cookie domains.
		add_filter( 'borlabs_cookie_allowed_domains', array( $this, 'filter_allowed_domains' ), 20 );
	}

	/**
	 * Filters the cookie domain for Borlabs.
	 *
	 * @param string $domain Current cookie domain.
	 * @return string Modified cookie domain.
	 */
	public function filter_cookie_domain( $domain ) {
		if ( ! DMS_Mode::is_single_site() ) {
			return $domain;
		}

		if ( ! class_exists( 'DMS_Single_Site_Mappings' ) ) {
			return $domain;
		}

		$mappings = DMS_Single_Site_Mappings::list_mappings( array( 'status' => 'active' ) );
		if ( is_wp_error( $mappings ) || empty( $mappings ) ) {
			return $domain;
		}

		// If multiple subdomains share a parent, use parent domain.
		$parent_domains = array();
		foreach ( $mappings as $mapping ) {
			$parent = $this->get_parent_domain( $mapping['host'] );
			if ( $parent ) {
				$parent_domains[ $parent ][] = $mapping['host'];
			}
		}

		foreach ( $parent_domains as $parent => $subdomains ) {
			if ( count( $subdomains ) >= 2 ) {
				return '.' . $parent;
			}
		}

		// Single domain - return original.
		return $domain;
	}

	/**
	 * Filters allowed cookie domains for Borlabs.
	 *
	 * @param array $domains Allowed domains.
	 * @return array Modified domains.
	 */
	public function filter_allowed_domains( $domains ) {
		if ( ! DMS_Mode::is_single_site() ) {
			return $domains;
		}

		if ( ! class_exists( 'DMS_Single_Site_Mappings' ) ) {
			return $domains;
		}

		$mappings = DMS_Single_Site_Mappings::list_mappings( array( 'status' => 'active' ) );
		if ( is_wp_error( $mappings ) || empty( $mappings ) ) {
			return $domains;
		}

		foreach ( $mappings as $mapping ) {
			$domains[] = $mapping['host'];
		}

		return array_unique( $domains );
	}

	/**
	 * Extracts parent domain from host.
	 *
	 * @param string $host Hostname.
	 * @return string|false Parent domain or false.
	 */
	private function get_parent_domain( $host ) {
		$parts = explode( '.', $host );
		if ( count( $parts ) < 3 ) {
			return false;
		}

		array_shift( $parts );
		return implode( '.', $parts );
	}
}
