<?php
/**
 * REST API — namespace `dms/v1` (feature-integration plan Phase 1).
 *
 * Implements the documented commercial contract (mappings, mapping values,
 * batch) as a clean-room reimplementation, on top of this plugin's own
 * storage. It is mode-aware:
 *
 *  - Multisite mode: `/mappings` delegates to the existing wp_sitemeta
 *    engine (blog-keyed). Mapping *values* (global object scopes) are not
 *    supported yet in multisite mode and return an explicit error — no
 *    silent no-op.
 *  - Single-site mode: full contract — host/path mappings, per-domain
 *    custom_html + attachment_id, and mapping values (post/page/category/
 *    tag/archive/product/cpt object scopes).
 *
 * Auth is WordPress' standard REST auth (cookie nonce or Application
 * Passwords — both enforced by core, not re-implemented here). Permission
 * callbacks gate every route; batch operations enforce a hard per-request
 * item limit and are validated item-by-item.
 *
 * Clean-room note: nothing from the commercial product's source or sample
 * credentials is used or shipped; the contract shape (route names, field
 * names) is re-implemented from public documentation.
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_REST_DMS {

	/**
	 * Hard cap on operations per batch request.
	 */
	const BATCH_LIMIT = 25;

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			'dms/v1',
			'/mappings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_mappings' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'site_id' => array(
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'blog_id' => array(
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'domain'  => array(
							'required'          => false,
							'sanitize_callback' => array( $this, 'sanitize_host' ),
						),
						'host'    => array(
							'required'          => false,
							'sanitize_callback' => array( $this, 'sanitize_host' ),
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
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'site_id'       => array(
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'blog_id'       => array(
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'domain'        => array(
							'required'          => false,
							'sanitize_callback' => array( $this, 'sanitize_host' ),
							'validate_callback' => array( $this, 'validate_host' ),
						),
						'host'          => array(
							'required'          => false,
							'sanitize_callback' => array( $this, 'sanitize_host' ),
							'validate_callback' => array( $this, 'validate_host' ),
						),
						'path'          => array(
							'required'          => false,
							'default'           => '',
							'sanitize_callback' => array( $this, 'sanitize_path' ),
							'validate_callback' => array( $this, 'validate_path' ),
						),
						'attachment_id' => array(
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'custom_html'   => array(
							'required'          => false,
							'sanitize_callback' => array( $this, 'sanitize_custom_html' ),
						),
						'active'        => array(
							'required'          => false,
							'default'           => true,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
						'make_primary'  => array(
							'required'          => false,
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
			)
		);

		register_rest_route(
			'dms/v1',
			'/mappings/batch',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'batch_mappings' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'items' => array(
							'required'          => true,
							'type'              => 'array',
							'validate_callback' => array( $this, 'validate_batch_items' ),
						),
					),
				),
			)
		);

		register_rest_route(
			'dms/v1',
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
						'id'            => array(
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'host'          => array(
							'required'          => false,
							'sanitize_callback' => array( $this, 'sanitize_host' ),
							'validate_callback' => array( $this, 'validate_host' ),
						),
						'path'          => array(
							'required'          => false,
							'sanitize_callback' => array( $this, 'sanitize_path' ),
							'validate_callback' => array( $this, 'validate_path' ),
						),
						'attachment_id' => array(
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'custom_html'   => array(
							'required'          => false,
							'sanitize_callback' => array( $this, 'sanitize_custom_html' ),
						),
						'active'        => array(
							'required'          => false,
							'sanitize_callback' => array( $this, 'sanitize_boolean_or_null' ),
						),
						'verified'      => array(
							'required'          => false,
							'sanitize_callback' => array( $this, 'sanitize_boolean_or_null' ),
						),
						'primary'       => array(
							'required'          => false,
							'sanitize_callback' => array( $this, 'sanitize_boolean_or_null' ),
						),
						'make_primary'  => array(
							'required'          => false,
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
			'dms/v1',
			'/mappings/(?P<id>\d+)/values',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_values' ),
					'permission_callback' => array( $this, 'can_access_mapping' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_value' ),
					'permission_callback' => array( $this, 'can_access_mapping' ),
					'args'                => array(
						'id'          => array(
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'object_type' => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => array( $this, 'validate_object_type' ),
						),
						'object_id'   => array(
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'primary'     => array(
							'required'          => false,
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
			)
		);

		register_rest_route(
			'dms/v1',
			'/mapping_values/(?P<value_id>\d+)',
			array(
				array(
					'methods'             => 'PUT, PATCH',
					'callback'            => array( $this, 'update_value' ),
					'permission_callback' => array( $this, 'can_access_value' ),
					'args'                => array(
						'value_id' => array(
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'primary'  => array(
							'required'          => false,
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_value' ),
					'permission_callback' => array( $this, 'can_access_value' ),
					'args'                => array(
						'value_id' => array(
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

	/**
	 * Network admin (multisite) or site admin (single-site).
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_network' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Object-level check for a single mapping by ID. Also resolves IDOR:
	 * the resolved mapping must be reachable by the current capability set.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function can_access_mapping( $request ) {
		$mapping = $this->resolve_mapping( (int) $request->get_param( 'id' ) );
		if ( is_wp_error( $mapping ) ) {
			return $mapping;
		}
		return $this->can_manage();
	}

	/**
	 * Object-level check for a mapping value.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function can_access_value( $request ) {
		if ( DMS_Mode::is_multisite() ) {
			return $this->values_unsupported_error();
		}
		$value = DMS_Mapping_Values::get_value( (int) $request->get_param( 'value_id' ) );
		if ( null === $value ) {
			return new WP_Error( 'dms_value_not_found', __( 'Mapping value not found.', 'domain-mapping-system' ), array( 'status' => 404 ) );
		}
		return $this->can_manage();
	}

	/* ---------------------------------------------------------------------
	 * Sanitizers / validators
	 * ------------------------------------------------------------------ */

	public function sanitize_host( $value ) {
		$normalized = DMS_Single_Site_Mappings::normalize_host( $value );
		return false !== $normalized ? $normalized : sanitize_text_field( (string) $value );
	}

	public function validate_host( $value ) {
		return false !== DMS_Single_Site_Mappings::normalize_host( $value );
	}

	public function sanitize_path( $value ) {
		$normalized = DMS_Single_Site_Mappings::normalize_path( $value );
		return false !== $normalized ? $normalized : '';
	}

	public function validate_path( $value ) {
		return false !== DMS_Single_Site_Mappings::normalize_path( $value );
	}

	public function sanitize_custom_html( $value ) {
		return DMS_Single_Site_Mappings::sanitize_custom_html( $value );
	}

	public function validate_object_type( $value ) {
		return DMS_Mapping_Values::is_supported_type( $value );
	}

	public function sanitize_boolean_or_null( $value ) {
		return null === $value ? null : rest_sanitize_boolean( $value );
	}

	public function validate_batch_items( $value ) {
		return is_array( $value ) && count( $value ) <= self::BATCH_LIMIT;
	}

	/* ---------------------------------------------------------------------
	 * Endpoints
	 * ------------------------------------------------------------------ */

	/**
	 * GET /dms/v1/mappings
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_mappings( $request ) {
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$host   = sanitize_text_field( (string) $request->get_param( 'host' ) );
		$domain = sanitize_text_field( (string) $request->get_param( 'domain' ) );
		$site   = (int) $request->get_param( 'site_id' );
		if ( ! $site ) {
			$site = (int) $request->get_param( 'blog_id' );
		}

		if ( DMS_Mode::is_multisite() ) {
			$where  = array();
			$needle = $domain ? $domain : $host;
			if ( $site ) {
				$where['blog_id'] = $site;
			}
			if ( $needle ) {
				$where['domain'] = $needle;
			}
			$mappings = DMS_Mapping_Engine::list_mappings( $where );
			if ( is_wp_error( $mappings ) ) {
				return $mappings;
			}
			if ( $status ) {
				$mappings = array_values(
					array_filter(
						$mappings,
						function ( $m ) use ( $status ) {
							if ( 'active' === $status ) {
								return $m['active'];
							}
							if ( 'inactive' === $status ) {
								return ! $m['active'];
							}
							if ( 'verified' === $status ) {
								return $m['verified'];
							}
							return true;
						}
					)
				);
			}
			return rest_ensure_response( $mappings );
		}

		$where = array();
		if ( $host ) {
			$where['host'] = $host;
		} elseif ( $domain ) {
			$where['host'] = $domain;
		}
		if ( in_array( $status, array( 'active', 'inactive' ), true ) ) {
			$where['status'] = $status;
		}
		if ( 'verified' === $status ) {
			$where['verified'] = 1;
		}
		$mappings = DMS_Single_Site_Mappings::list_mappings( $where );
		return rest_ensure_response( $mappings );
	}

	/**
	 * POST /dms/v1/mappings
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function create_mapping( $request ) {
		$make_primary = (bool) $request->get_param( 'make_primary' );

		if ( DMS_Mode::is_multisite() ) {
			if ( $make_primary && ! current_user_can( 'manage_network' ) ) {
				return new WP_Error( 'dm_primary_requires_network', __( 'Promoting a mapping to primary requires network admin.', 'domain-mapping-system' ), array( 'status' => 403 ) );
			}
			$blog_id = (int) $request->get_param( 'site_id' );
			if ( ! $blog_id ) {
				$blog_id = (int) $request->get_param( 'blog_id' );
			}
			if ( ! $blog_id ) {
				return new WP_Error( 'dms_blog_required', __( 'site_id/blog_id is required in multisite mode.', 'domain-mapping-system' ), array( 'status' => 400 ) );
			}
			$domain     = sanitize_text_field( (string) $request->get_param( 'domain' ) );
			$mapping_id = DMS_Mapping_Engine::add_mapping( $blog_id, $domain, $make_primary );
			if ( is_wp_error( $mapping_id ) ) {
				return $mapping_id;
			}
			$mapping = DMS_Mapping_Engine::get_mapping( $mapping_id );
			return rest_ensure_response( new WP_REST_Response( is_wp_error( $mapping ) ? array( 'id' => $mapping_id ) : $mapping, 201 ) );
		}

		$host = sanitize_text_field( (string) $request->get_param( 'host' ) );
		if ( false === DMS_Single_Site_Mappings::normalize_host( $host ) ) {
			return new WP_Error( 'dms_invalid_host', __( 'Invalid host.', 'domain-mapping-system' ), array( 'status' => 400 ) );
		}
		$mapping_id = DMS_Single_Site_Mappings::add_mapping(
			$host,
			(string) $request->get_param( 'path' ),
			array(
				'attachment_id' => (int) $request->get_param( 'attachment_id' ),
				'custom_html'   => (string) $request->get_param( 'custom_html' ),
				'active'        => (bool) $request->get_param( 'active' ),
				'primary'       => (bool) $request->get_param( 'primary' ),
			)
		);
		if ( is_wp_error( $mapping_id ) ) {
			return $mapping_id;
		}
		$this->audit_log( null, $host, 'dms.mapping.create', array( 'mapping_id' => $mapping_id ) );
		$mapping = DMS_Single_Site_Mappings::get_mapping( $mapping_id );
		return rest_ensure_response( new WP_REST_Response( $mapping, 201 ) );
	}

	/**
	 * GET /dms/v1/mappings/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_mapping( $request ) {
		$mapping = $this->resolve_mapping( (int) $request->get_param( 'id' ) );
		return rest_ensure_response( $mapping );
	}

	/**
	 * PATCH|PUT /dms/v1/mappings/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_mapping( $request ) {
		$mapping_id = (int) $request->get_param( 'id' );

		if ( DMS_Mode::is_multisite() ) {
			$make_primary = (bool) $request->get_param( 'make_primary' );
			if ( $make_primary && ! current_user_can( 'manage_network' ) ) {
				return new WP_Error( 'dm_primary_requires_network', __( 'Promoting a mapping to primary requires network admin.', 'domain-mapping-system' ), array( 'status' => 403 ) );
			}
			$active  = $request->get_param( 'active' );
			$updated = DMS_Mapping_Engine::update_mapping( $mapping_id, null === $active ? null : (bool) $active, $make_primary );
			return rest_ensure_response( $updated );
		}

		$fields = array();
		foreach ( array( 'host', 'path', 'attachment_id', 'custom_html', 'active', 'verified', 'primary' ) as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value ) {
				$fields[ $key ] = $value;
			}
		}
		$updated = DMS_Single_Site_Mappings::update_mapping( $mapping_id, $fields );
		if ( ! is_wp_error( $updated ) ) {
			$this->audit_log(
				null,
				isset( $updated['host'] ) ? $updated['host'] : '',
				'dms.mapping.update',
				array(
					'mapping_id' => $mapping_id,
					'fields'     => array_keys( $fields ),
				)
			);
		}
		return rest_ensure_response( $updated );
	}

	/**
	 * DELETE /dms/v1/mappings/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function delete_mapping( $request ) {
		$mapping_id = (int) $request->get_param( 'id' );

		if ( DMS_Mode::is_multisite() ) {
			$removed = DMS_Mapping_Engine::remove_mapping( $mapping_id );
			if ( is_wp_error( $removed ) ) {
				return $removed;
			}
			return rest_ensure_response(
				array(
					'status'  => 'ok',
					'removed' => true,
					'id'      => $mapping_id,
				)
			);
		}

		$mapping = DMS_Single_Site_Mappings::get_mapping( $mapping_id );
		if ( is_wp_error( $mapping ) ) {
			return $mapping;
		}
		$removed = DMS_Single_Site_Mappings::remove_mapping( $mapping_id );
		if ( is_wp_error( $removed ) ) {
			return $removed;
		}
		$this->audit_log( null, $mapping['host'] . ( $mapping['path'] ? '/' . $mapping['path'] : '' ), 'dms.mapping.delete', array( 'mapping_id' => $mapping_id ) );
		return rest_ensure_response(
			array(
				'status'  => 'ok',
				'removed' => true,
				'id'      => $mapping_id,
			)
		);
	}

	/**
	 * GET /dms/v1/mappings/{id}/values — single-site mode only.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_values( $request ) {
		if ( DMS_Mode::is_multisite() ) {
			return $this->values_unsupported_error();
		}
		$mapping = DMS_Single_Site_Mappings::get_mapping( (int) $request->get_param( 'id' ) );
		if ( is_wp_error( $mapping ) ) {
			return $mapping;
		}
		$values = DMS_Mapping_Values::get_values( (int) $request->get_param( 'id' ) );
		return rest_ensure_response( is_wp_error( $values ) ? $values : $values );
	}

	/**
	 * POST /dms/v1/mappings/{id}/values — single-site mode only.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function create_value( $request ) {
		if ( DMS_Mode::is_multisite() ) {
			return $this->values_unsupported_error();
		}
		$mapping_id = (int) $request->get_param( 'id' );
		$mapping    = DMS_Single_Site_Mappings::get_mapping( $mapping_id );
		if ( is_wp_error( $mapping ) ) {
			return $mapping;
		}
		$value_id = DMS_Mapping_Values::set_value(
			$mapping_id,
			sanitize_key( (string) $request->get_param( 'object_type' ) ),
			(int) $request->get_param( 'object_id' ),
			(bool) $request->get_param( 'primary' )
		);
		if ( is_wp_error( $value_id ) ) {
			return $value_id;
		}
		$value = DMS_Mapping_Values::get_value( $value_id );
		return rest_ensure_response( new WP_REST_Response( $this->decorate_value( $value ), 201 ) );
	}

	/**
	 * PUT|PATCH /dms/v1/mapping_values/{value_id} — single-site mode only.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_value( $request ) {
		if ( DMS_Mode::is_multisite() ) {
			return $this->values_unsupported_error();
		}
		$value_id = (int) $request->get_param( 'value_id' );
		$updated  = DMS_Mapping_Values::set_primary( $value_id, (bool) $request->get_param( 'primary' ) );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		return rest_ensure_response( $this->decorate_value( DMS_Mapping_Values::get_value( $value_id ) ) );
	}

	/**
	 * DELETE /dms/v1/mapping_values/{value_id} — single-site mode only.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function delete_value( $request ) {
		if ( DMS_Mode::is_multisite() ) {
			return $this->values_unsupported_error();
		}
		$value_id = (int) $request->get_param( 'value_id' );
		$removed  = DMS_Mapping_Values::remove_value( $value_id );
		if ( is_wp_error( $removed ) ) {
			return $removed;
		}
		return rest_ensure_response(
			array(
				'status'  => 'ok',
				'removed' => true,
				'id'      => $value_id,
			)
		);
	}

	/**
	 * POST /dms/v1/mappings/batch
	 *
	 * Applies up to self::BATCH_LIMIT operations. Each item is validated and
	 * applied independently; failures are reported per item, not for the whole
	 * request. Items: {op: create|update|delete, ...fields}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function batch_mappings( $request ) {
		$items = (array) $request->get_param( 'items' );
		if ( count( $items ) > self::BATCH_LIMIT ) {
			return new WP_Error(
				'dms_batch_limit',
				sprintf(
				// translators: %d = maximum operations per batch.
					__( 'Batch requests are limited to %d operations.', 'domain-mapping-system' ),
					self::BATCH_LIMIT
				),
				array( 'status' => 400 )
			);
		}

		$results = array();
		foreach ( $items as $index => $item ) {
			$op   = isset( $item['op'] ) ? sanitize_key( (string) $item['op'] ) : '';
			$info = array(
				'index' => $index,
				'op'    => $op,
			);

			switch ( $op ) {
				case 'create':
					$result = $this->batch_create( $item );
					break;
				case 'update':
					$result = $this->batch_update( $item );
					break;
				case 'delete':
					$result = $this->batch_delete( $item );
					break;
				default:
					$result = new WP_Error( 'dms_batch_invalid_op', __( 'Unsupported batch operation.', 'domain-mapping-system' ), array( 'status' => 400 ) );
			}

			if ( is_wp_error( $result ) ) {
				$info['error'] = array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				);
			} else {
				$info['mapping'] = $result;
			}
			$results[] = $info;
		}
		return rest_ensure_response( $results );
	}

	/* ---------------------------------------------------------------------
	 * Internal helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Resolves a mapping by ID in the active mode.
	 *
	 * @param int $mapping_id Mapping ID.
	 * @return array|WP_Error
	 */
	private function resolve_mapping( int $mapping_id ) {
		if ( DMS_Mode::is_multisite() ) {
			return DMS_Mapping_Engine::get_mapping( $mapping_id );
		}
		return DMS_Single_Site_Mappings::get_mapping( $mapping_id );
	}

	/**
	 * Single batch create item.
	 *
	 * @param array $item Item payload.
	 * @return array|WP_Error
	 */
	private function batch_create( array $item ) {
		if ( DMS_Mode::is_multisite() ) {
			$blog_id = isset( $item['blog_id'] ) ? absint( $item['blog_id'] ) : ( isset( $item['site_id'] ) ? absint( $item['site_id'] ) : 0 );
			if ( ! $blog_id ) {
				return new WP_Error( 'dms_blog_required', __( 'site_id/blog_id is required in multisite mode.', 'domain-mapping-system' ) );
			}
			$domain     = isset( $item['domain'] ) ? $item['domain'] : '';
			$mapping_id = DMS_Mapping_Engine::add_mapping( $blog_id, $domain, ! empty( $item['make_primary'] ) );
			if ( is_wp_error( $mapping_id ) ) {
				return $mapping_id;
			}
			return DMS_Mapping_Engine::get_mapping( $mapping_id );
		}

		if ( empty( $item['host'] ) ) {
			return new WP_Error( 'dms_invalid_host', __( 'Invalid host.', 'domain-mapping-system' ) );
		}
		$mapping_id = DMS_Single_Site_Mappings::add_mapping(
			(string) $item['host'],
			isset( $item['path'] ) ? (string) $item['path'] : '',
			array(
				'attachment_id' => isset( $item['attachment_id'] ) ? absint( $item['attachment_id'] ) : null,
				'custom_html'   => isset( $item['custom_html'] ) ? (string) $item['custom_html'] : '',
				'active'        => isset( $item['active'] ) ? (bool) $item['active'] : true,
				'primary'       => isset( $item['primary'] ) ? (bool) $item['primary'] : false,
			)
		);
		if ( is_wp_error( $mapping_id ) ) {
			return $mapping_id;
		}
		return DMS_Single_Site_Mappings::get_mapping( $mapping_id );
	}

	/**
	 * Single batch update item.
	 *
	 * @param array $item Item payload.
	 * @return array|WP_Error
	 */
	private function batch_update( array $item ) {
		$mapping_id = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
		if ( ! $mapping_id ) {
			return new WP_Error( 'dms_invalid_id', __( 'An id is required for update operations.', 'domain-mapping-system' ) );
		}
		if ( DMS_Mode::is_multisite() ) {
			$active       = isset( $item['active'] ) ? rest_sanitize_boolean( $item['active'] ) : null;
			$make_primary = ! empty( $item['make_primary'] );
			if ( $make_primary && ! current_user_can( 'manage_network' ) ) {
				return new WP_Error( 'dm_primary_requires_network', __( 'Promoting a mapping to primary requires network admin.', 'domain-mapping-system' ), array( 'status' => 403 ) );
			}
			return DMS_Mapping_Engine::update_mapping( $mapping_id, $active, $make_primary );
		}

		$fields = array();
		foreach ( array( 'host', 'path', 'attachment_id', 'custom_html', 'active', 'verified', 'primary' ) as $key ) {
			if ( array_key_exists( $key, $item ) ) {
				$fields[ $key ] = $item[ $key ];
			}
		}
		return DMS_Single_Site_Mappings::update_mapping( $mapping_id, $fields );
	}

	/**
	 * Single batch delete item.
	 *
	 * @param array $item Item payload.
	 * @return array|WP_Error
	 */
	private function batch_delete( array $item ) {
		$mapping_id = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
		if ( ! $mapping_id ) {
			return new WP_Error( 'dms_invalid_id', __( 'An id is required for delete operations.', 'domain-mapping-system' ) );
		}
		if ( DMS_Mode::is_multisite() ) {
			$removed = DMS_Mapping_Engine::remove_mapping( $mapping_id );
			if ( is_wp_error( $removed ) ) {
				return $removed;
			}
			return array(
				'id'      => $mapping_id,
				'removed' => true,
			);
		}
		$removed = DMS_Single_Site_Mappings::remove_mapping( $mapping_id );
		if ( is_wp_error( $removed ) ) {
			return $removed;
		}
		return array(
			'id'      => $mapping_id,
			'removed' => true,
		);
	}

	/**
	 * Consistent "values not supported in multisite mode" error.
	 *
	 * @return WP_Error
	 */
	private function values_unsupported_error() {
		return new WP_Error(
			'dms_values_multisite_unsupported',
			__( 'Mapping values (global object scopes) are not available in multisite mode yet; they require a single-site install.', 'domain-mapping-system' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Decorates a raw value row for API responses.
	 *
	 * @param array|null $value Raw row.
	 * @return array
	 */
	private function decorate_value( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array(
			'id'          => (int) $value['id'],
			'mapping_id'  => (int) $value['mapping_id'],
			'object_type' => $value['object_type'],
			'object_id'   => (int) $value['object_id'],
			'primary'     => (bool) $value['primary'],
			'created_at'  => $value['created_at'],
		);
	}

	/**
	 * Writes an audit-log entry (single-site mapping events).
	 *
	 * @param int|null $blog_id Blog ID (null in single-site mode).
	 * @param string   $domain  Host/path label.
	 * @param string   $action  Log action key.
	 * @param array    $context Context payload.
	 * @return void
	 */
	private function audit_log( $blog_id, string $domain, string $action, array $context ) {
		if ( ! class_exists( 'DMS_Logging' ) ) {
			return;
		}
		DMS_Logging::get_instance()->log( $blog_id, $domain, get_current_user_id(), $action, wp_json_encode( $context ) );
	}
}
