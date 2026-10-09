<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_Plugin {
	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'init' ) );
	}

	public function init() {
		DMS_Mapping_Engine::get_instance()->init();
		DMS_Settings::get_instance()->init();
	}
}
