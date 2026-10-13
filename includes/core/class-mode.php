<?php
/**
 * Mode detection for the dual-mode architecture.
 *
 * Phase 1 of the feature-integration plan adds a single-site mapping mode
 * alongside the existing Multisite core. This class is the single source of
 * truth for which mode is active. Mode is derived from WordPress itself
 * (is_multisite()) — there is no config switch to get out of sync.
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_Mode {
	const MODE_MULTISITE = 'multisite';
	const MODE_SINGLE    = 'single';

	/**
	 * Current mode key.
	 *
	 * @return string self::MODE_MULTISITE|self::MODE_SINGLE
	 */
	public static function current(): string {
		return is_multisite() ? self::MODE_MULTISITE : self::MODE_SINGLE;
	}

	/**
	 * Whether the install is a WordPress Multisite network.
	 *
	 * @return bool
	 */
	public static function is_multisite(): bool {
		return is_multisite();
	}

	/**
	 * Whether the install is a single-site WordPress.
	 *
	 * @return bool
	 */
	public static function is_single_site(): bool {
		return ! is_multisite();
	}
}
