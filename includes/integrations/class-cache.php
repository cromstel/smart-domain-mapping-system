<?php
/**
 * Caching plugin integration guard (W3 Total Cache, WP Rocket).
 *
 * Handles cache purging when mappings are updated.
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_Integration_Cache extends DMS_Integration_Guard {

	protected $slug = 'cache';
	protected $name = 'Caching Plugins (W3TC, WP Rocket)';

	protected $detection = array(
		'classes'   => array(
			'W3_Plugin',           // W3 Total Cache
			'Rocket_Logger',       // WP Rocket
			'WP_Super_Cache',      // WP Super Cache
		),
		'functions' => array(
			'w3tc_purge_post',     // W3TC
			'rocket_clean_domain', // WP Rocket
			'wp_cache_flush',      // WP Super Cache
		),
	);

	protected function setup_hooks() {
		// Purge cache when mapping is updated.
		add_action( 'dms_mapping_updated', array( $this, 'purge_cache' ), 10, 2 );

		// Purge cache when mapping is deleted.
		add_action( 'dms_mapping_deleted', array( $this, 'purge_cache' ), 10, 2 );

		// Purge cache when mapping value is updated.
		add_action( 'dms_mapping_value_updated', array( $this, 'purge_cache' ), 10, 2 );
	}

	/**
	 * Purges all supported caches.
	 *
	 * @param int $mapping_id Mapping ID.
	 * @param string $host Host.
	 * @return void
	 */
	public function purge_cache( $mapping_id, $host ) {
		// W3 Total Cache
		if ( class_exists( 'W3_Plugin' ) ) {
			$this->purge_w3tc( $host );
		}

		// WP Rocket
		if ( function_exists( 'rocket_clean_domain' ) ) {
			$this->purge_wp_rocket( $host );
		}

		// WP Super Cache
		if ( function_exists( 'wp_cache_flush' ) ) {
			$this->purge_wp_super_cache( $host );
		}

		// Generic object cache flush.
		wp_cache_flush();
	}

	/**
	 * Purges W3 Total Cache.
	 *
	 * @param string $host Host to purge.
	 * @return void
	 */
	private function purge_w3tc( $host ) {
		if ( ! class_exists( 'W3_Plugin' ) ) {
			return;
		}

		$w3tc = W3_Plugin::get_instance();
		if ( ! $w3tc ) {
			return;
		}

		// Purge page cache for the domain.
		if ( method_exists( $w3tc, 'flush_pgcache' ) ) {
			$w3tc->flush_pgcache();
		}

		// Purge object cache.
		if ( method_exists( $w3tc, 'flush_objectcache' ) ) {
			$w3tc->flush_objectcache();
		}

		// Purge database cache.
		if ( method_exists( $w3tc, 'flush_dbcache' ) ) {
			$w3tc->flush_dbcache();
		}

		// Purge minify cache.
		if ( method_exists( $w3tc, 'flush_minify' ) ) {
			$w3tc->flush_minify();
		}
	}

	/**
	 * Purges WP Rocket cache.
	 *
	 * @param string $host Host to purge.
	 * @return void
	 */
	private function purge_wp_rocket( $host ) {
		if ( ! function_exists( 'rocket_clean_domain' ) ) {
			return;
		}

		// WP Rocket's rocket_clean_domain purges all cache for a domain.
		rocket_clean_domain( $host );
	}

	/**
	 * Purges WP Super Cache.
	 *
	 * @param string $host Host to purge.
	 * @return void
	 */
	private function purge_wp_super_cache( $host ) {
		if ( ! function_exists( 'wp_cache_flush' ) ) {
			return;
		}

		// WP Super Cache flushes entire cache - no granular domain purge.
		wp_cache_flush();
	}
}
