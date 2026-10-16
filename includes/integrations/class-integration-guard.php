<?php
/**
 * Integration guard base class.
 *
 * Provides common functionality for all integration guards:
 * - Runtime detection via class_exists / function_exists / defined.
 * - Feature flag via filter `dms_integration_{slug}_enabled`.
 * - Activation/deactivation hooks.
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class DMS_Integration_Guard {

	/**
	 * Shared guard instances, keyed by concrete class name.
	 *
	 * @var array<string, DMS_Integration_Guard>
	 */
	private static $instances = array();

	/**
	 * Returns the shared instance of the called guard class.
	 *
	 * DMS_Integrations resolves every guard through this factory, so the
	 * guards must be instantiable without arguments. Each subclass is a
	 * stateless singleton with its configuration in property defaults.
	 *
	 * @return static
	 */
	public static function get_instance() {
		$class = static::class;
		if ( ! isset( self::$instances[ $class ] ) ) {
			self::$instances[ $class ] = new static();
		}
		return self::$instances[ $class ];
	}

	/**
	 * Integration slug (lowercase, hyphenated).
	 *
	 * @var string
	 */
	protected $slug = '';

	/**
	 * Human-readable name.
	 *
	 * @var string
	 */
	protected $name = '';

	/**
	 * Detection criteria (classes, functions, constants).
	 *
	 * @var array
	 */
	protected $detection = array(
		'classes'   => array(),
		'functions' => array(),
		'constants' => array(),
	);

	/**
	 * Whether this integration is currently active.
	 *
	 * @var bool
	 */
	private $is_active = null;

	/**
	 * Checks if the integration is available and enabled.
	 *
	 * @return bool
	 */
	public function is_active() {
		if ( null !== $this->is_active ) {
			return $this->is_active;
		}

		$this->is_active = $this->detect() && $this->is_enabled();
		return $this->is_active;
	}

	/**
	 * Detects if the third-party plugin is active.
	 *
	 * @return bool
	 */
	protected function detect() {
		foreach ( $this->detection['classes'] as $class ) {
			if ( class_exists( $class ) ) {
				return true;
			}
		}
		foreach ( $this->detection['functions'] as $function ) {
			if ( function_exists( $function ) ) {
				return true;
			}
		}
		foreach ( $this->detection['constants'] as $constant ) {
			if ( defined( $constant ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks if the integration is enabled via filter.
	 *
	 * @return bool
	 */
	protected function is_enabled() {
		return apply_filters( 'dms_integration_' . $this->slug . '_enabled', true );
	}

	/**
	 * Initializes the integration if active.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! $this->is_active() ) {
			return;
		}
		$this->setup_hooks();
	}

	/**
	 * Sets up WordPress hooks for the integration.
	 * Must be implemented by subclasses.
	 *
	 * @return void
	 */
	abstract protected function setup_hooks();

	/**
	 * Gets the integration slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return $this->slug;
	}

	/**
	 * Gets the integration name.
	 *
	 * @return string
	 */
	public function get_name() {
		return $this->name;
	}
}
