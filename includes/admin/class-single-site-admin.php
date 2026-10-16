<?php
/**
 * Single-site admin screen (Phase 1 skeleton).
 *
 * In single-site installs this registers a "Domain Mappings" menu page backed
 * by the dm_mappings repository. Phase 1 ships list/add/delete; the per-domain
 * editor tabs (custom head/body code, favicon, menu, language, 404) land in
 * Phase 2 of the feature-integration plan. In multisite installs this class
 * is inert — the Network Admin screens (DMS_Settings/DMS_REST_API) remain
 * authoritative there.
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_Single_Site_Admin {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( is_multisite() ) {
			return;
		}
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_dm_add_single_mapping', array( $this, 'handle_add' ) );
		add_action( 'admin_post_dm_delete_single_mapping', array( $this, 'handle_delete' ) );
	}

	/**
	 * Registers the single-site menu page.
	 *
	 * @return void
	 */
	public function register_menu() {
		// Register the page with the primitive the capability check actually
		// enforces. Passing the custom 'manage_dms_mappings' key here hides the
		// page from everyone: that key is never granted to a role, and
		// dms_current_user_can() resolves it to a primitive instead. Deriving
		// both from dms_get_capabilities() keeps visibility and the render/handlers
		// in sync, including for sites that filter 'dms_capabilities'.
		$capabilities = dms_get_capabilities();
		if ( empty( $capabilities[ DMS_CAP_MANAGE_MAPPINGS ] ) ) {
			// Nothing maps to the capability, so no user could pass the checks in
			// render_page() and the handlers. Registering the menu would only
			// surface a link that dies with "You do not have permission".
			return;
		}

		add_menu_page(
			__( 'Domain Mappings', 'domain-mapping-system' ),
			__( 'Domain Mappings', 'domain-mapping-system' ),
			$capabilities[ DMS_CAP_MANAGE_MAPPINGS ],
			'dms-single-site',
			array( $this, 'render_page' ),
			'dashicons-admin-links',
			26
		);
	}

	/**
	 * Renders the mappings page (list + add form).
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! dms_current_user_can( DMS_CAP_MANAGE_MAPPINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to manage domain mappings.', 'domain-mapping-system' ) );
		}
		require_once dirname( __DIR__, 2 ) . '/admin/views/single-site-mappings.php';
	}

	/**
	 * Handles the add-mapping form post.
	 *
	 * @return void
	 */
	public function handle_add() {
		if ( ! dms_current_user_can( DMS_CAP_MANAGE_MAPPINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to manage domain mappings.', 'domain-mapping-system' ) );
		}
		check_admin_referer( 'dm_add_single_mapping' );

		$host   = isset( $_POST['host'] ) ? sanitize_text_field( wp_unslash( $_POST['host'] ) ) : '';
		$path   = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '';
		$result = DMS_Single_Site_Mappings::add_mapping(
			$host,
			$path,
			array( 'active' => ! empty( $_POST['active'] ) )
		);

		if ( is_wp_error( $result ) ) {
			$msg = 'error';
		} else {
			$msg = 'added';
		}
		$url = add_query_arg( 'dm_msg', $msg, menu_page_url( 'dms-single-site', false ) );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Handles the delete-mapping form post.
	 *
	 * @return void
	 */
	public function handle_delete() {
		if ( ! dms_current_user_can( DMS_CAP_MANAGE_MAPPINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to manage domain mappings.', 'domain-mapping-system' ) );
		}
		check_admin_referer( 'dm_delete_single_mapping' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( $id ) {
			DMS_Single_Site_Mappings::remove_mapping( $id );
		}

		$url = add_query_arg( 'dm_msg', 'deleted', menu_page_url( 'dms-single-site', false ) );
		wp_safe_redirect( $url );
		exit;
	}
}
