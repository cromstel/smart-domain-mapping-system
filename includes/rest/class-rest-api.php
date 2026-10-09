<?php
/**
 * REST API (namespace domain-mapping/v1) plus the AJAX bridge used by the
 * Network Admin screens.
 *
 * Contract (per spec):
 *  - Success responses are bare objects/arrays (no envelope).
 *  - Errors are {code, message, data} with a proper HTTP status.
 *  - POST /mappings/{id}/verify with no token initiates a challenge and
 *    returns {domain, token, method, challenge_path}; with a token it
 *    completes the verification (external proof).
 *
 * Note: routes are registered as a numeric array of endpoint definitions.
 * The `'endpoints' => array('GET' => ...)` shape is NOT supported by core
 * (non-numeric keys are demoted to route options, producing handlers with
 * no callbacks — i.e. 404s — and a route-level permission_callback is
 * ignored, which would leave endpoints public).
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_REST_API {
	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'wp_ajax_dm_rest_list_mappings', array( $this, 'ajax_list_mappings' ) );
		add_action( 'wp_ajax_dm_rest_create_mapping', array( $this, 'ajax_create_mapping' ) );
		add_action( 'wp_ajax_dm_rest_delete_mapping', array( $this, 'ajax_delete_mapping' ) );
		add_action( 'wp_ajax_dm_rest_set_primary', array( $this, 'ajax_set_primary' ) );
		add_action( 'wp_ajax_dm_rest_patch_mapping', array( $this, 'ajax_patch_mapping' ) );
		add_action( 'wp_ajax_dm_rest_verify_mapping', array( $this, 'ajax_verify_mapping' ) );
	}

	public function register_routes() {
		register_rest_route(
			'domain-mapping/v1',
			'/mappings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_mappings' ),
					'permission_callback' => array( $this, 'can_read_all' ),
					'args'                => array(
						'site_id' => array(
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'status'  => array(
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_mapping' ),
					'permission_callback' => array( $this, 'can_create' ),
					'args'                => array(
						'site_id'      => array(
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'blog_id'      => array(
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'domain'       => array(
							'required'          => true,
							'sanitize_callback' => array( $this, 'sanitize_domain' ),
							'validate_callback' => array( $this, 'validate_domain' ),
						),
						'make_primary' => array(
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
			)
		);

		register_rest_route(
			'domain-mapping/v1',
			'/mappings/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_mapping' ),
					'permission_callback' => array( $this, 'can_access_mapping' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => 'PATCH, PUT',
					'callback'            => array( $this, 'update_mapping' ),
					'permission_callback' => array( $this, 'can_access_mapping' ),
					'args'                => array(
						'id'           => array(
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'active'       => array(
							'required'          => false,
							'default'           => null,
							'sanitize_callback' => array( $this, 'sanitize_active' ),
						),
						'make_primary' => array(
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_mapping' ),
					'permission_callback' => array( $this, 'can_access_mapping' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			'domain-mapping/v1',
			'/mappings/(?P<id>\d+)/verify',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'verify_mapping' ),
					'permission_callback' => array( $this, 'can_manage_network' ),
					'args'                => array(
						'id'     => array(
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'token'  => array(
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'method' => array(
							'required'          => false,
							'default'           => 'dns',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		register_rest_route(
			'domain-mapping/v1',
			'/mappings/(?P<id>\d+)/set-primary',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_primary' ),
					'permission_callback' => array( $this, 'can_manage_network' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			'domain-mapping/v1',
			'/sites/(?P<site_id>\d+)/mappings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_site_mappings' ),
					'permission_callback' => array( $this, 'can_access_site' ),
					'args'                => array(
						'site_id' => array(
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Permission callbacks
	 * ------------------------------------------------------------------ */

	public function can_manage_network() {
		return current_user_can( 'manage_network' );
	}

	public function can_read_all() {
		return current_user_can( 'manage_network' );
	}

	public function can_create( $request ) {
		if ( current_user_can( 'manage_network' ) ) {
			return true;
		}
		$blog_id = (int) $request->get_param( 'site_id' );
		if ( ! $blog_id ) {
			$blog_id = (int) $request->get_param( 'blog_id' );
		}
		return $blog_id ? $this->can_manage_site( $blog_id ) : false;
	}

	public function can_access_mapping( $request ) {
		$mapping = DMS_Mapping_Engine::get_mapping( (int) $request->get_param( 'id' ) );
		if ( is_wp_error( $mapping ) ) {
			return $mapping;
		}
		if ( current_user_can( 'manage_network' ) ) {
			return true;
		}
		return $this->can_manage_site( (int) $mapping['blog_id'] );
	}

	public function can_access_site( $request ) {
		if ( current_user_can( 'manage_network' ) ) {
			return true;
		}
		return $this->can_manage_site( (int) $request->get_param( 'site_id' ) );
	}

	/**
	 * Whether the current user may manage mappings for a specific site.
	 *
	 * Super admins always may. Site admins are gated by the spec filter
	 * `dm_allow_site_admin_mapping` and must hold `manage_options` on the
	 * target site — so a site admin can never touch another site's mappings.
	 *
	 * @param int $blog_id Target blog ID.
	 * @return bool
	 */
	private function can_manage_site( int $blog_id ): bool {
		if ( current_user_can( 'manage_network' ) ) {
			return true;
		}
		if ( ! $blog_id || ! get_site( $blog_id ) ) {
			return false;
		}
		if ( ! apply_filters( 'dm_allow_site_admin_mapping', false, $blog_id ) ) {
			return false;
		}
		switch_to_blog( $blog_id );
		$can = current_user_can( 'manage_options' );
		restore_current_blog();
		return $can;
	}

	/* ---------------------------------------------------------------------
	 * Argument sanitizers / validators
	 * ------------------------------------------------------------------ */

	public function sanitize_domain( $value ) {
		$normalized = DMS_Mapping_Engine::normalize_domain( $value );
		return false !== $normalized ? $normalized : sanitize_text_field( (string) $value );
	}

	public function validate_domain( $value ) {
		return false !== DMS_Mapping_Engine::normalize_domain( $value );
	}

	public function sanitize_active( $value ) {
		return null === $value ? null : rest_sanitize_boolean( $value );
	}

	/* ---------------------------------------------------------------------
	 * Endpoints
	 * ------------------------------------------------------------------ */

	/**
	 * GET /mappings — bare array of mappings (optionally filtered).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_mappings( $request ) {
		$where   = array();
		$site_id = (int) $request->get_param( 'site_id' );
		$status  = (string) $request->get_param( 'status' );
		if ( $site_id ) {
			$where['blog_id'] = $site_id;
		}
		$mappings = DMS_Mapping_Engine::list_mappings( $where );
		if ( is_wp_error( $mappings ) ) {
			return $this->error_response( $mappings );
		}
		if ( in_array( $status, array( 'active', 'inactive', 'verified', 'unverified' ), true ) ) {
			$mappings = array_values(
				array_filter(
					$mappings,
					function ( $mapping ) use ( $status ) {
						if ( 'active' === $status ) {
							return $mapping['active'];
						}
						if ( 'inactive' === $status ) {
							return ! $mapping['active'];
						}
						if ( 'verified' === $status ) {
							return $mapping['verified'];
						}
						return ! $mapping['verified'];
					}
				)
			);
		}
		return new WP_REST_Response( $mappings, 200 );
	}

	/**
	 * POST /mappings — creates a mapping. 201 + mapping object.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function create_mapping( $request ) {
		$blog_id = (int) $request->get_param( 'site_id' );
		if ( ! $blog_id ) {
			$blog_id = (int) $request->get_param( 'blog_id' );
		}
		if ( ! $blog_id ) {
			return $this->error_response(
				new WP_Error( 'dm_invalid_site', 'site_id (or blog_id) is required.', array( 'status' => 400 ) )
			);
		}
		$result = DMS_Mapping_Engine::add_mapping(
			$blog_id,
			(string) $request->get_param( 'domain' ),
			rest_sanitize_boolean( $request->get_param( 'make_primary' ) )
		);
		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}
		return new WP_REST_Response( DMS_Mapping_Engine::get_mapping( (int) $result ), 201 );
	}

	/**
	 * GET /mappings/{id} — mapping object.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_mapping( $request ) {
		$mapping = DMS_Mapping_Engine::get_mapping( (int) $request->get_param( 'id' ) );
		if ( is_wp_error( $mapping ) ) {
			return $this->error_response( $mapping );
		}
		return new WP_REST_Response( $mapping, 200 );
	}

	/**
	 * PATCH /mappings/{id} — toggles active and/or makes primary.
	 *
	 * Promoting to primary requires super admin (site address = network
	 * operation per the spec permission matrix).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_mapping( $request ) {
		$id           = (int) $request->get_param( 'id' );
		$active       = $request->get_param( 'active' );
		$make_primary = rest_sanitize_boolean( $request->get_param( 'make_primary' ) );

		if ( $make_primary && ! current_user_can( 'manage_network' ) ) {
			return $this->error_response(
				new WP_Error( 'dm_forbidden', 'Setting a primary domain requires network administrator rights.', array( 'status' => 403 ) )
			);
		}
		$result = DMS_Mapping_Engine::update_mapping( $id, $active, $make_primary );
		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * DELETE /mappings/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function delete_mapping( $request ) {
		$id     = (int) $request->get_param( 'id' );
		$result = DMS_Mapping_Engine::remove_mapping( $id );
		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}
		return new WP_REST_Response(
			array(
				'status'  => 'ok',
				'removed' => true,
				'id'      => $id,
			),
			200
		);
	}

	/**
	 * POST /mappings/{id}/verify — initiate (no token) or complete (token).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function verify_mapping( $request ) {
		$id    = (int) $request->get_param( 'id' );
		$token = (string) $request->get_param( 'token' );

		if ( '' !== $token ) {
			$result = DMS_Mapping_Engine::verify_mapping( $id, $token );
			if ( is_wp_error( $result ) ) {
				return $this->error_response( $result );
			}
			return new WP_REST_Response(
				array_merge( array( 'status' => 'ok' ), $result ),
				200
			);
		}

		$method = (string) $request->get_param( 'method' );
		$result = DMS_Mapping_Engine::generate_verification( $id, $method );
		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /mappings/{id}/set-primary — syncs wp_blogs.domain.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function set_primary( $request ) {
		$result = DMS_Mapping_Engine::set_primary( (int) $request->get_param( 'id' ) );
		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * GET /sites/{site_id}/mappings — bare array for one site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_site_mappings( $request ) {
		$site_id  = (int) $request->get_param( 'site_id' );
		$mappings = DMS_Mapping_Engine::list_mappings( array( 'blog_id' => $site_id ) );
		if ( is_wp_error( $mappings ) ) {
			return $this->error_response( $mappings );
		}
		return new WP_REST_Response( $mappings, 200 );
	}

	/* ---------------------------------------------------------------------
	 * AJAX bridge (Network Admin UI)
	 *
	 * Hand-built WP_REST_Request objects bypass the REST server, so URL
	 * params must be set explicitly — otherwise operations silently ran
	 * against id = 0.
	 * ------------------------------------------------------------------ */

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- every handler
	// below calls require_ajax(), which verifies the nonce and capability before
	// $_POST is read; PHPCS cannot follow that into the helper.
	public function ajax_list_mappings() {
		$this->require_ajax();
		$this->send_json( $this->get_mappings( new WP_REST_Request( 'GET', '/mappings' ) ) );
	}

	public function ajax_create_mapping() {
		$this->require_ajax();
		$request = new WP_REST_Request( 'POST', '/mappings' );
		$request->set_body_params( wp_unslash( $_POST ) );
		$this->send_json( $this->create_mapping( $request ) );
	}

	public function ajax_delete_mapping() {
		$this->require_ajax();
		$id      = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$request = new WP_REST_Request( 'DELETE', '/mappings/' . $id );
		$request->set_url_params( array( 'id' => $id ) );
		$this->send_json( $this->delete_mapping( $request ) );
	}

	public function ajax_set_primary() {
		$this->require_ajax();
		$id      = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$request = new WP_REST_Request( 'POST', '/mappings/' . $id . '/set-primary' );
		$request->set_url_params( array( 'id' => $id ) );
		$this->send_json( $this->set_primary( $request ) );
	}

	public function ajax_patch_mapping() {
		$this->require_ajax();
		$id      = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$request = new WP_REST_Request( 'PATCH', '/mappings/' . $id );
		$request->set_url_params( array( 'id' => $id ) );
		$request->set_body_params(
			array(
				'active' => isset( $_POST['active'] ) ? rest_sanitize_boolean( $_POST['active'] ) : null,
			)
		);
		$this->send_json( $this->update_mapping( $request ) );
	}

	public function ajax_verify_mapping() {
		$this->require_ajax();
		$id      = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$request = new WP_REST_Request( 'POST', '/mappings/' . $id . '/verify' );
		$request->set_url_params( array( 'id' => $id ) );
		$body = array();
		if ( isset( $_POST['token'] ) ) {
			$body['token'] = sanitize_text_field( wp_unslash( $_POST['token'] ) );
		}
		if ( isset( $_POST['method'] ) ) {
			$body['method'] = sanitize_key( wp_unslash( $_POST['method'] ) );
		}
		$request->set_body_params( $body );
		$this->send_json( $this->verify_mapping( $request ) );
	}

	// phpcs:enable WordPress.Security.NonceVerification.Missing

	/**
	 * Verifies nonce + capability for admin-ajax calls.
	 *
	 * @return void
	 */
	private function require_ajax() {
		if ( ! check_ajax_referer( 'dm_nonce_action', '_ajax_nonce', false ) ) {
			$this->send_json(
				new WP_Error( 'dm_invalid_nonce', 'Invalid nonce.', array( 'status' => 403 ) )
			);
		}
		if ( ! current_user_can( 'manage_network' ) ) {
			$this->send_json(
				new WP_Error( 'dm_forbidden', 'Permission denied.', array( 'status' => 403 ) )
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Response helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Renders a WP_Error in the spec's {code, message, data} shape.
	 *
	 * @param WP_Error $error Error.
	 * @return WP_REST_Response
	 */
	private function error_response( WP_Error $error ) {
		$data   = $error->get_error_data();
		$status = 400;
		if ( is_array( $data ) && isset( $data['status'] ) ) {
			$status = (int) $data['status'];
		}
		return new WP_REST_Response(
			array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'data'    => is_array( $data ) ? $data : null,
			),
			$status
		);
	}

	/**
	 * Single-encodes and emits a response. Errors use the {code, message,
	 * data} shape so clients can distinguish them by the presence of `code`.
	 *
	 * @param WP_REST_Response|WP_Error $response Response or error.
	 * @return void
	 */
	private function send_json( $response ) {
		if ( is_wp_error( $response ) ) {
			$response = $this->error_response( $response );
		}
		$body   = $response instanceof WP_REST_Response ? $response->get_data() : $response;
		$status = $response instanceof WP_REST_Response ? $response->get_status() : 200;
		wp_send_json( $body, $status );
	}
}
