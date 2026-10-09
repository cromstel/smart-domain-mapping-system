<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_Settings {
	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		add_action( 'network_admin_menu', array( $this, 'add_menu' ) );
		add_action( 'network_admin_edit_dm_settings', array( $this, 'save_settings' ) );
		add_action( 'network_admin_edit_dm_add_mapping', array( $this, 'add_mapping' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	public function enqueue_admin_assets( $hook_suffix ) {
		if ( strpos( $hook_suffix, 'dm_' ) === false ) {
			return;
		}
		$plugin_url = plugin_dir_url( DMS_PLUGIN_FILE );
		wp_enqueue_style( 'dm-admin', $plugin_url . 'admin/css/admin.css', array(), DMS_VERSION );
		wp_enqueue_script( 'dm-admin', $plugin_url . 'admin/js/admin.js', array( 'jquery' ), DMS_VERSION, true );
		wp_localize_script(
			'dm-admin',
			'dmAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'dm_nonce_action' ),
			)
		);
	}

	public function register_settings() {
		register_setting(
			'dm_group',
			'dm_options',
			array(
				'sanitize_callback' => array( $this, 'sanitize_options' ),
				'default' => array(
					'default_redirect' => '301',
					'ssl_provider'     => 'none',
				),
			)
		);

		add_settings_section(
			'dm_main',
			esc_html__( 'Domain Mapping Policies', 'domain-mapping-system' ),
			array( $this, 'section_text' ),
			'dm_settings_page'
		);

		add_settings_field(
			'dm_default_redirect',
			esc_html__( 'Default Redirect', 'domain-mapping-system' ),
			array( $this, 'field_select' ),
			'dm_settings_page',
			'dm_main',
			array( 'option' => 'default_redirect', 'choices' => array( '301' => '301', '302' => '302' ) )
		);

		add_settings_field(
			'dm_ssl_provider',
			esc_html__( 'SSL Provider', 'domain-mapping-system' ),
			array( $this, 'field_select' ),
			'dm_settings_page',
			'dm_main',
			array( 'option' => 'ssl_provider', 'choices' => array( 'none' => 'None', 'acme' => 'ACME / Let\'s Encrypt' ) )
		);
	}

	public function sanitize_options( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}
		$clean = array();
		$clean['default_redirect'] = isset( $input['default_redirect'] ) && ( '301' === $input['default_redirect'] || '302' === $input['default_redirect'] ) ? sanitize_key( $input['default_redirect'] ) : '301';
		$clean['ssl_provider']     = isset( $input['ssl_provider'] ) && in_array( $input['ssl_provider'], array( 'none', 'acme' ), true ) ? sanitize_key( $input['ssl_provider'] ) : 'none';
		return $clean;
	}

	public function section_text() {
		echo '<p>' . esc_html__( 'Configure network-wide domain mapping behavior.', 'domain-mapping-system' ) . '</p>';
	}

	public function field_select( $args ) {
		$options = get_site_option( 'dm_options', array() );
		$default = 'default_redirect' === $args['option'] ? '301' : 'none';
		$current = isset( $options[ $args['option'] ] ) ? $options[ $args['option'] ] : $default;
		printf( '<select name="dm_options[%1$s]" id="%1$s">', esc_attr( $args['option'] ) );
		foreach ( $args['choices'] as $value => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	public function add_menu() {
		add_menu_page(
			esc_html__( 'Domain Mapping', 'domain-mapping-system' ),
			esc_html__( 'Domain Mapping', 'domain-mapping-system' ),
			'manage_network',
			'dm_settings_page',
			array( $this, 'render_settings' ),
			'dashicons-networking',
			30
		);
		add_submenu_page(
			'dm_settings_page',
			esc_html__( 'Audit Logs', 'domain-mapping-system' ),
			esc_html__( 'Audit Logs', 'domain-mapping-system' ),
			'manage_network',
			'dm_logs',
			function() {
				require_once __DIR__ . '/../admin/views/logs.php';
				dm_render_logs_page();
			}
		);
		add_submenu_page(
			'dm_settings_page',
			esc_html__( 'Domain Mappings', 'domain-mapping-system' ),
			esc_html__( 'Domain Mappings', 'domain-mapping-system' ),
			'manage_network',
			'dm_mappings_page',
			function() {
				require_once __DIR__ . '/../admin/views/network-mappings.php';
				dm_render_mappings_page();
			}
		);
	}

	public function save_settings() {
		if ( ! current_user_can( 'manage_network' ) || ! check_admin_referer( 'dm_group-options', '_wpnonce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'domain-mapping-system' ) );
		}
		$input = isset( $_POST['dm_options'] ) ? wp_unslash( $_POST['dm_options'] ) : array();
		update_site_option( 'dm_options', $this->sanitize_options( $input ) );
		wp_safe_redirect( network_admin_url( 'admin.php?page=dm_settings_page' ) );
		exit;
	}

	/**
	 * No-JS fallback handler for the "Add Mapping" form on the network
	 * mappings screen (the JS submit handler intercepts it when available).
	 */
	public function add_mapping() {
		if ( ! current_user_can( 'manage_network' ) || ! check_admin_referer( 'dm_mapping', 'dm_nonce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'domain-mapping-system' ) );
		}
		$blog_id      = isset( $_POST['blog_id'] ) ? absint( $_POST['blog_id'] ) : 0;
		$domain       = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
		$make_primary = ! empty( $_POST['make_primary'] );

		$result = DMS_Mapping_Engine::add_mapping( $blog_id, $domain, $make_primary );
		if ( is_wp_error( $result ) ) {
			wp_die(
				esc_html( $result->get_error_message() ),
				esc_html__( 'Could not add mapping', 'domain-mapping-system' ),
				array(
					'response' => 400,
					'back_link' => true,
				)
			);
		}
		wp_safe_redirect( network_admin_url( 'admin.php?page=dm_mappings_page' ) );
		exit;
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_network' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'domain-mapping-system' ) );
		}
		require_once __DIR__ . '/../admin/views/settings.php';
		dm_render_settings_page();
	}
}
DMS_Settings::get_instance();
