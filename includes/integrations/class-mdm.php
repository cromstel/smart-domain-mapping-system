<?php
/**
 * Multiple Domain Mapping integration guard.
 *
 * Handles compatibility with other domain mapping plugins
 * (e.g., WordPress MU Domain Mapping, Multiple Domain Mapping).
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_Integration_MDM extends DMS_Integration_Guard {

	protected $slug = 'mdm';
	protected $name = 'Multiple Domain Mapping';

	protected $detection = array(
		'classes'   => array(
			'Domain_Mapping',
			'Multiple_Domain_Mapping',
		),
		'functions' => array(
			'domain_mapping_get_domains',
		),
	);

	protected function setup_hooks() {
		// Disable conflicting functionality when other MDM plugins are active.
		add_action( 'init', array( $this, 'disable_conflicts' ), 5 );

		// Log warning about potential conflicts.
		add_action( 'admin_notices', array( $this, 'show_conflict_notice' ) );
	}

	/**
	 * Disables conflicting functionality when other MDM plugins are detected.
	 *
	 * @return void
	 */
	public function disable_conflicts() {
		// If another MDM plugin is active, we should defer to it for
		// domain resolution to avoid conflicts.

		// Disable our template_redirect handlers.
		remove_action( 'template_redirect', array( 'DMS_Global_Archive_Mapping', 'maybe_redirect_archive' ) );
		remove_action( 'template_redirect', array( 'DMS_Global_Page_Mapping', 'maybe_redirect_page' ) );
		remove_action( 'template_redirect', array( 'DMS_Global_Product_Mapping', 'maybe_redirect_product' ) );
		remove_action( 'template_redirect', array( 'DMS_Global_Domain_Mapping', 'maybe_redirect_alias' ) );

		// Disable our rewrite engine if loaded.
		remove_action( 'template_redirect', 'dms_rewrite_handle_request' );

		// Log that we're deferring to another MDM plugin.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// Use error_log only in debug mode - acceptable for development debugging.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'DMS: Multiple Domain Mapping plugin detected - deferring domain resolution to avoid conflicts.' );
		}
	}

	/**
	 * Shows admin notice about potential conflicts.
	 *
	 * @return void
	 */
	public function show_conflict_notice() {
		if ( ! current_user_can( 'manage_network' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$detected = array();
		if ( class_exists( 'Domain_Mapping' ) ) {
			$detected[] = 'WordPress MU Domain Mapping';
		}
		if ( class_exists( 'Multiple_Domain_Mapping' ) ) {
			$detected[] = 'Multiple Domain Mapping';
		}
		if ( function_exists( 'domain_mapping_get_domains' ) ) {
			$detected[] = 'Domain Mapping function detected';
		}

		if ( empty( $detected ) ) {
			return;
		}

		$list = implode( ', ', $detected );
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<?php
				printf(
					/* translators: %s: detected MDM plugins list */
					esc_html__( 'Smart Domain Mapping System has detected other domain mapping plugins active: %s. To avoid conflicts, DMS has disabled its domain resolution features. Please deactivate the other plugins if you want to use DMS for domain mapping.', 'domain-mapping-system' ),
					esc_html( $list )
				);
				?>
			</p>
		</div>
		<?php
	}
}
