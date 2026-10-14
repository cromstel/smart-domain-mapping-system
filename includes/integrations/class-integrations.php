<?php
/**
 * Integration manager - initializes all integration guards.
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_Integrations {

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

		// Initialize all integration guards on plugins_loaded.
		add_action( 'plugins_loaded', array( $this, 'init_integrations' ), 20 );
	}

	/**
	 * Initializes all integration guards.
	 *
	 * @return void
	 */
	public function init_integrations() {
		$integrations = array(
			'yoast'        => 'DMS_Integration_Yoast',
			'cache'        => 'DMS_Integration_Cache',
			'multilingual' => 'DMS_Integration_Multilingual',
			'borlabs'      => 'DMS_Integration_Borlabs',
			'mdm'          => 'DMS_Integration_MDM',
		);

		foreach ( $integrations as $slug => $class ) {
			if ( class_exists( $class ) ) {
				$instance = $class::get_instance();
				if ( method_exists( $instance, 'init' ) ) {
					$instance->init();
				}
			}
		}
	}

	/**
	 * Checks if a specific integration is active.
	 *
	 * @param string $slug Integration slug.
	 * @return bool
	 */
	public function is_active( $slug ) {
		$integrations = array(
			'yoast'        => 'DMS_Integration_Yoast',
			'cache'        => 'DMS_Integration_Cache',
			'multilingual' => 'DMS_Integration_Multilingual',
			'borlabs'      => 'DMS_Integration_Borlabs',
			'mdm'          => 'DMS_Integration_MDM',
		);

		if ( ! isset( $integrations[ $slug ] ) ) {
			return false;
		}

		$class = $integrations[ $slug ];
		if ( ! class_exists( $class ) ) {
			return false;
		}

		$instance = $class::get_instance();
		return method_exists( $instance, 'is_active' ) ? $instance->is_active() : false;
	}
}
