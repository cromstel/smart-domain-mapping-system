<?php
/**
 * Single-site mapping repository (dm_mappings table).
 *
 * Phase 1 of the feature-integration plan: a host/path-keyed mapping store
 * for single-site WordPress installs, mirroring the commercial `dms/v1`
 * contract (host, path, attachment_id, custom_html). In Multisite mode the
 * existing wp_sitemeta engine (DMS_Mapping_Engine) remains authoritative;
 * this table is used by the single-site mode only.
 *
 * Security notes:
 *  - `custom_html` (per-domain head/body code) is stored through a strict
 *    wp_kses allow-list. `<script>` is deliberately NOT allowed — defence in
 *    depth on top of the capability gate; admins needing scripts should use
 *    the WordPress Customizer or a dedicated header/footer plugin.
 *  - The host is validated with the same normalizer as the Multisite engine
 *    (no paths, no localhost, no invalid TLDs).
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_Single_Site_Mappings {

	/**
	 * wp_kses allow-list for per-domain head/body code.
	 *
	 * Deliberately excludes script/iframe/object/embed/form/input — anything
	 * that can execute or phish. Meta/link/style/base cover the documented
	 * "custom head code" use cases; phrasing/block tags cover body snippets.
	 *
	 * @var array
	 */
	const ALLOWED_HTML = array(
		'a'          => array(
			'href'   => true,
			'target' => true,
			'rel'    => true,
			'class'  => true,
			'id'     => true,
			'title'  => true,
		),
		'img'        => array(
			'src'     => true,
			'alt'     => true,
			'width'   => true,
			'height'  => true,
			'class'   => true,
			'id'      => true,
			'loading' => true,
		),
		'meta'       => array(
			'charset'    => true,
			'name'       => true,
			'content'    => true,
			'http-equiv' => true,
			'property'   => true,
		),
		'link'       => array(
			'rel'         => true,
			'href'        => true,
			'type'        => true,
			'media'       => true,
			'as'          => true,
			'crossorigin' => true,
			'integrity'   => true,
		),
		'style'      => array(
			'type'  => true,
			'media' => true,
			'class' => true,
			'id'    => true,
		),
		'base'       => array(
			'href'   => true,
			'target' => true,
		),
		'noscript'   => array(),
		'div'        => array(
			'class' => true,
			'id'    => true,
		),
		'p'          => array(
			'class' => true,
			'id'    => true,
		),
		'span'       => array(
			'class' => true,
			'id'    => true,
		),
		'section'    => array(
			'class' => true,
			'id'    => true,
		),
		'br'         => array(),
		'strong'     => array(),
		'em'         => array(),
		'ul'         => array( 'class' => true ),
		'ol'         => array( 'class' => true ),
		'li'         => array( 'class' => true ),
		'blockquote' => array(
			'class' => true,
			'cite'  => true,
		),
	);

	/**
	 * Cache group (shared with the Multisite engine so one flush clears both).
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'dm';

	/**
	 * Current table name (base prefix, network-wide like the other dm_* tables).
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->base_prefix . DMS_TABLE_MAPPINGS;
	}

	/**
	 * Validates a host with the engine's normalizer.
	 *
	 * @param string $host Raw host.
	 * @return string|false Normalized lower-case host, or false.
	 */
	public static function normalize_host( $host ) {
		if ( ! is_string( $host ) || '' === trim( $host ) ) {
			return false;
		}
		return DMS_Mapping_Engine::normalize_domain( $host );
	}

	/**
	 * Validates and normalizes a subdirectory path.
	 *
	 * Empty string = site root. Segments are [a-z0-9._-]+ separated by single
	 * slashes; no leading/trailing slash, no '..', no '\\', no query/fragment.
	 *
	 * @param mixed $path Raw path.
	 * @return string|false Normalized path ('' for root), or false on invalid.
	 */
	public static function normalize_path( $path ) {
		if ( null === $path || '' === $path ) {
			return '';
		}
		$path = strtolower( (string) $path );
		$path = trim( $path, '/' );
		if ( '' === $path ) {
			return '';
		}
		if ( 1 === preg_match( '#^[a-z0-9._-]+(?:/[a-z0-9._-]+)*$#', $path ) ) {
			return $path;
		}
		return false;
	}

	/**
	 * Sanitizes custom head/body code through the allow-list.
	 *
	 * @param mixed $html Raw HTML snippet.
	 * @return string Sanitized HTML.
	 */
	public static function sanitize_custom_html( $html ) {
		if ( ! is_string( $html ) ) {
			return '';
		}
		return wp_kses( $html, self::ALLOWED_HTML );
	}

	/**
	 * Validates a language code (ISO 639-1 or locale format).
	 *
	 * @param mixed $lang Raw language code.
	 * @return string|false Validated code (lowercase), or false.
	 */
	public static function validate_language( $lang ) {
		if ( ! is_string( $lang ) || '' === trim( $lang ) ) {
			return false;
		}
		$lang = strtolower( trim( $lang ) );
		// Accept ISO 639-1 (2 letters) or locale (e.g., en_US, de_DE).
		if ( 1 === preg_match( '#^[a-z]{2}(_[A-Z]{2})?$#', $lang ) ) {
			return $lang;
		}
		return false;
	}

	/**
	 * Sanitizes redirect_404 (post ID or URL).
	 *
	 * @param mixed $value Raw value.
	 * @return string Sanitized value.
	 */
	public static function sanitize_redirect_404( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		// If numeric, treat as post ID.
		if ( ctype_digit( $value ) ) {
			return (string) absint( $value );
		}
		// Otherwise validate as URL.
		$url = esc_url_raw( $value );
		return $url === $value ? $url : '';
	}

	/**
	 * Sanitizes rewrite rules JSON.
	 *
	 * @param mixed $value Raw JSON string or array.
	 * @return string|null JSON string or null.
	 */
	public static function sanitize_rewrite_rules( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( is_array( $value ) ) {
			$json = wp_json_encode( $value );
			return false !== $json ? $json : null;
		}
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			return ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) ? $value : null;
		}
		return null;
	}

	/**
	 * Sanitizes query string rules JSON.
	 *
	 * @param mixed $value Raw JSON string or array.
	 * @return string|null JSON string or null.
	 */
	public static function sanitize_query_string_rules( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( is_array( $value ) ) {
			$json = wp_json_encode( $value );
			return false !== $json ? $json : null;
		}
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			return ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) ? $value : null;
		}
		return null;
	}

	/**
	 * Adds a mapping. Host is required; path defaults to root.
	 *
	 * @param string $host Normalized host (validated here again).
	 * @param string $path Subdirectory path ('' = root).
	 * @param array  $args attachment_id, custom_html, menu_id, language, redirect_404,
	 *                    rewrite_rules, query_string_rules, active, verified, primary.
	 * @return int|WP_Error Mapping ID on success.
	 */
	public static function add_mapping( string $host, string $path = '', array $args = array() ) {
		global $wpdb;

		$host = self::normalize_host( $host );
		if ( false === $host ) {
			return new WP_Error( 'dms_invalid_host', __( 'Invalid host. Use a real domain name (no protocol or path).', 'domain-mapping-system' ), array( 'status' => 400 ) );
		}
		$path = self::normalize_path( $path );
		if ( false === $path ) {
			return new WP_Error( 'dms_invalid_path', __( 'Invalid path. Use lowercase URL-safe segments.', 'domain-mapping-system' ), array( 'status' => 400 ) );
		}
		if ( null !== self::get_mapping_for_host( $host, $path ) ) {
			return new WP_Error( 'dms_host_exists', __( 'That host/path combination is already mapped.', 'domain-mapping-system' ), array( 'status' => 409 ) );
		}

		$now         = current_time( 'mysql' );
		$table       = self::table();
		$attachment  = isset( $args['attachment_id'] ) ? absint( $args['attachment_id'] ) : null;
		$menu_id     = isset( $args['menu_id'] ) ? absint( $args['menu_id'] ) : null;
		$language    = isset( $args['language'] ) ? self::validate_language( $args['language'] ) : null;
		$redirect404 = isset( $args['redirect_404'] ) ? self::sanitize_redirect_404( $args['redirect_404'] ) : '';
		$rewrite     = isset( $args['rewrite_rules'] ) ? self::sanitize_rewrite_rules( $args['rewrite_rules'] ) : null;
		$query       = isset( $args['query_string_rules'] ) ? self::sanitize_query_string_rules( $args['query_string_rules'] ) : null;

		$inserted = $wpdb->insert(
			$table,
			array(
				'host'               => $host,
				'path'               => $path,
				'attachment_id'      => $attachment,
				'custom_html'        => self::sanitize_custom_html( isset( $args['custom_html'] ) ? $args['custom_html'] : '' ),
				'menu_id'            => $menu_id,
				'language'           => $language,
				'redirect_404'       => $redirect404,
				'rewrite_rules'      => $rewrite,
				'query_string_rules' => $query,
				'active'             => isset( $args['active'] ) ? (int) (bool) $args['active'] : 1,
				'verified'           => isset( $args['verified'] ) ? (int) (bool) $args['verified'] : 0,
				'primary'            => isset( $args['primary'] ) ? (int) (bool) $args['primary'] : 0,
				'created_at'         => $now,
				'updated_at'         => $now,
			),
			array( '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
		);
		if ( false === $inserted || ! $wpdb->insert_id ) {
			return new WP_Error( 'dms_insert_failed', __( 'Could not store the mapping.', 'domain-mapping-system' ), array( 'status' => 500 ) );
		}

		$mapping_id = (int) $wpdb->insert_id;
		self::cache_mapping( self::get_mapping_row( $mapping_id ) );

		// Pre-load rewrite rules for all active hosts.
		if ( class_exists( 'DMS_Rewrite_Engine' ) ) {
			DMS_Rewrite_Engine::preload_all_rules();
		}

		return $mapping_id;
	}

	/**
	 * Fetches a raw row by ID. Internal; returns null when missing.
	 *
	 * @param int $mapping_id Mapping ID.
	 * @return array|null
	 */
	private static function get_mapping_row( int $mapping_id ) {
		global $wpdb;
		$row = wp_cache_get( 'ss_id_' . $mapping_id, self::CACHE_GROUP );
		if ( is_array( $row ) ) {
			return $row;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $mapping_id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted table name.
			ARRAY_A
		);
		if ( $row ) {
			wp_cache_set( 'ss_id_' . $mapping_id, $row, self::CACHE_GROUP );
		}
		return $row ? $row : null;
	}

	/**
	 * Fetches one mapping by ID.
	 *
	 * @param int $mapping_id Mapping ID.
	 * @return array|WP_Error Decorated mapping.
	 */
	public static function get_mapping( int $mapping_id ) {
		$row = self::get_mapping_row( $mapping_id );
		if ( null === $row ) {
			return new WP_Error( 'dms_not_found', __( 'Mapping not found.', 'domain-mapping-system' ), array( 'status' => 404 ) );
		}
		return self::decorate( $row );
	}

	/**
	 * Finds a mapping by host/path.
	 *
	 * @param string $host Host.
	 * @param string $path Path ('' = root).
	 * @return array|null Decorated mapping or null.
	 */
	public static function get_mapping_for_host( string $host, string $path = '' ) {
		$host = self::normalize_host( $host );
		if ( false === $host ) {
			return null;
		}
		$path = self::normalize_path( $path );
		if ( false === $path ) {
			return null;
		}
		$key = 'ss_host_' . md5( $host . '|' . $path );
		$row = wp_cache_get( $key, self::CACHE_GROUP );
		if ( is_array( $row ) ) {
			return self::decorate( $row );
		}
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE host = %s AND path = %s LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted table name.
				$host,
				$path
			),
			ARRAY_A
		);
		if ( $row ) {
			wp_cache_set( $key, $row, self::CACHE_GROUP );
			wp_cache_set( 'ss_id_' . (int) $row['id'], $row, self::CACHE_GROUP );
			return self::decorate( $row );
		}
		return null;
	}

	/**
	 * Lists mappings.
	 *
	 * @param array $where Optional filters: status (active|inactive), verified
	 *                     (0|1), host (exact), limit (default 100, max 500),
	 *                     offset (default 0).
	 * @return array|WP_Error
	 */
	public static function list_mappings( array $where = array() ) {
		global $wpdb;

		$sql    = 'SELECT * FROM ' . self::table() . ' WHERE 1=1'; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted table name.
		$params = array();

		if ( ! empty( $where['host'] ) ) {
			$host = self::normalize_host( $where['host'] );
			if ( false === $host ) {
				return array();
			}
			$sql     .= ' AND host = %s';
			$params[] = $host;
		}
		if ( isset( $where['status'] ) && 'active' === $where['status'] ) {
			$sql .= ' AND active = 1';
		} elseif ( isset( $where['status'] ) && 'inactive' === $where['status'] ) {
			$sql .= ' AND active = 0';
		}
		if ( isset( $where['verified'] ) ) {
			$sql     .= ' AND verified = %d';
			$params[] = (int) (bool) $where['verified'];
		}

		$limit  = isset( $where['limit'] ) ? min( absint( $where['limit'] ), 500 ) : 100;
		$offset = isset( $where['offset'] ) ? max( absint( $where['offset'] ), 0 ) : 0;
		if ( $limit < 1 ) {
			$limit = 100;
		}
		$sql     .= ' ORDER BY id ASC LIMIT %d OFFSET %d';
		$params[] = $limit;
		$params[] = $offset;

		// $sql is assembled from fixed fragments; values are bound via prepare().
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fragment assembly, values bound.
		if ( $wpdb->last_error ) {
			return new WP_Error( 'dms_query_failed', __( 'Mapping query failed.', 'domain-mapping-system' ), array( 'status' => 500 ) );
		}

		$mappings = array();
		foreach ( (array) $rows as $row ) {
			$mappings[] = self::decorate( $row );
		}
		return $mappings;
	}

	/**
	 * Counts mappings for pagination.
	 *
	 * @param array $where Optional filters: status (active|inactive), verified (0|1), host (exact).
	 * @return int|WP_Error Total count.
	 */
	public static function count_mappings( array $where = array() ) {
		global $wpdb;

		$sql    = 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE 1=1'; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted table name.
		$params = array();

		if ( ! empty( $where['host'] ) ) {
			$host = self::normalize_host( $where['host'] );
			if ( false === $host ) {
				return 0;
			}
			$sql     .= ' AND host = %s';
			$params[] = $host;
		}
		if ( isset( $where['status'] ) && 'active' === $where['status'] ) {
			$sql .= ' AND active = 1';
		} elseif ( isset( $where['status'] ) && 'inactive' === $where['status'] ) {
			$sql .= ' AND active = 0';
		}
		if ( isset( $where['verified'] ) ) {
			$sql     .= ' AND verified = %d';
			$params[] = (int) (bool) $where['verified'];
		}

		// $sql is assembled from fixed fragments; values are bound via prepare().
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fragment assembly, values bound.
		$count = $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) );
		if ( $wpdb->last_error ) {
			return new WP_Error( 'dms_query_failed', __( 'Mapping count query failed.', 'domain-mapping-system' ), array( 'status' => 500 ) );
		}
		return (int) $count;
	}

	/**
	 * Updates fields of an existing mapping. PATCH semantics: absent fields
	 * are left untouched. Host/path changes are validated + uniqueness-checked.
	 *
	 * @param int   $mapping_id Mapping ID.
	 * @param array $fields     host, path, attachment_id, custom_html, menu_id,
	 *                          language, redirect_404, rewrite_rules, query_string_rules,
	 *                          active, verified, primary.
	 * @return array|WP_Error Updated mapping.
	 */
	public static function update_mapping( int $mapping_id, array $fields ) {
		global $wpdb;

		$row = self::get_mapping_row( $mapping_id );
		if ( null === $row ) {
			return new WP_Error( 'dms_not_found', __( 'Mapping not found.', 'domain-mapping-system' ), array( 'status' => 404 ) );
		}

		$data = array();
		$fmt  = array();

		if ( array_key_exists( 'host', $fields ) ) {
			$host = self::normalize_host( $fields['host'] );
			if ( false === $host ) {
				return new WP_Error( 'dms_invalid_host', __( 'Invalid host.', 'domain-mapping-system' ), array( 'status' => 400 ) );
			}
			$data['host'] = $host;
			$fmt[]        = '%s';
		}
		if ( array_key_exists( 'path', $fields ) ) {
			$path = self::normalize_path( $fields['path'] );
			if ( false === $path ) {
				return new WP_Error( 'dms_invalid_path', __( 'Invalid path.', 'domain-mapping-system' ), array( 'status' => 400 ) );
			}
			$data['path'] = $path;
			$fmt[]        = '%s';
		}
		if ( array_key_exists( 'attachment_id', $fields ) ) {
			$data['attachment_id'] = absint( $fields['attachment_id'] );
			$fmt[]                 = '%d';
		}
		if ( array_key_exists( 'custom_html', $fields ) ) {
			$data['custom_html'] = self::sanitize_custom_html( $fields['custom_html'] );
			$fmt[]               = '%s';
		}
		if ( array_key_exists( 'menu_id', $fields ) ) {
			$data['menu_id'] = absint( $fields['menu_id'] );
			$fmt[]           = '%d';
		}
		if ( array_key_exists( 'language', $fields ) ) {
			$lang = self::validate_language( $fields['language'] );
			if ( false === $lang && '' !== $fields['language'] ) {
				return new WP_Error( 'dms_invalid_language', __( 'Invalid language code. Use ISO 639-1 (e.g., en, de) or locale (e.g., en_US).', 'domain-mapping-system' ), array( 'status' => 400 ) );
			}
			$data['language'] = $lang;
			$fmt[]            = '%s';
		}
		if ( array_key_exists( 'redirect_404', $fields ) ) {
			$data['redirect_404'] = self::sanitize_redirect_404( $fields['redirect_404'] );
			$fmt[]                = '%s';
		}
		if ( array_key_exists( 'rewrite_rules', $fields ) ) {
			$data['rewrite_rules'] = self::sanitize_rewrite_rules( $fields['rewrite_rules'] );
			$fmt[]                 = '%s';
		}
		if ( array_key_exists( 'query_string_rules', $fields ) ) {
			$data['query_string_rules'] = self::sanitize_query_string_rules( $fields['query_string_rules'] );
			$fmt[]                      = '%s';
		}
		foreach ( array( 'active', 'verified', 'primary' ) as $flag ) {
			if ( array_key_exists( $flag, $fields ) ) {
				$data[ $flag ] = (int) (bool) $fields[ $flag ];
				$fmt[]         = '%d';
			}
		}
		if ( empty( $data ) ) {
			return self::get_mapping( $mapping_id );
		}

		// Uniqueness on host/path change, excluding self.
		if ( isset( $data['host'], $data['path'] ) || isset( $data['host'] ) || isset( $data['path'] ) ) {
			$new_host = isset( $data['host'] ) ? $data['host'] : $row['host'];
			$new_path = isset( $data['path'] ) ? $data['path'] : $row['path'];
			$clash    = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM ' . self::table() . ' WHERE host = %s AND path = %s AND id <> %d LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted table name.
					$new_host,
					$new_path,
					$mapping_id
				)
			);
			if ( $clash ) {
				return new WP_Error( 'dms_host_exists', __( 'That host/path combination is already mapped.', 'domain-mapping-system' ), array( 'status' => 409 ) );
			}
		}

		$data['updated_at'] = current_time( 'mysql' );
		$fmt[]              = '%s';

		$updated = $wpdb->update(
			self::table(),
			$data,
			array( 'id' => $mapping_id ),
			$fmt,
			array( '%d' )
		);
		if ( false === $updated ) {
			return new WP_Error( 'dms_update_failed', __( 'Could not update the mapping.', 'domain-mapping-system' ), array( 'status' => 500 ) );
		}

		self::cache_mapping( self::get_mapping_row( $mapping_id ) );

		// Pre-load rewrite rules for all active hosts.
		if ( class_exists( 'DMS_Rewrite_Engine' ) ) {
			DMS_Rewrite_Engine::preload_all_rules();
		}

		return self::get_mapping( $mapping_id );
	}

	/**
	 * Removes a mapping and any mapping values attached to it.
	 *
	 * @param int $mapping_id Mapping ID.
	 * @return bool|WP_Error
	 */
	public static function remove_mapping( int $mapping_id ) {
		global $wpdb;

		$row = self::get_mapping_row( $mapping_id );
		if ( null === $row ) {
			return new WP_Error( 'dms_not_found', __( 'Mapping not found.', 'domain-mapping-system' ), array( 'status' => 404 ) );
		}

		if ( class_exists( 'DMS_Mapping_Values' ) ) {
			DMS_Mapping_Values::remove_all_for_mapping( $mapping_id );
		}

		$deleted = $wpdb->delete(
			self::table(),
			array( 'id' => $mapping_id ),
			array( '%d' )
		);
		if ( false === $deleted ) {
			return new WP_Error( 'dms_delete_failed', __( 'Could not remove the mapping.', 'domain-mapping-system' ), array( 'status' => 500 ) );
		}

		wp_cache_delete( 'ss_id_' . $mapping_id, self::CACHE_GROUP );
		wp_cache_delete( 'ss_host_' . md5( $row['host'] . '|' . $row['path'] ), self::CACHE_GROUP );

		// Clear rewrite engine cache for this host.
		if ( class_exists( 'DMS_Rewrite_Engine' ) ) {
			DMS_Rewrite_Engine::clear_cache( $row['host'] );
		}

		return true;
	}

	/**
	 * Rebuilds cache entries for a raw row (must not be null).
	 *
	 * @param array $row Raw DB row.
	 * @return void
	 */
	private static function cache_mapping( $row ) {
		if ( ! is_array( $row ) || empty( $row['id'] ) ) {
			return;
		}
		wp_cache_set( 'ss_id_' . (int) $row['id'], $row, self::CACHE_GROUP );
		wp_cache_set( 'ss_host_' . md5( $row['host'] . '|' . $row['path'] ), $row, self::CACHE_GROUP );
	}

	/**
	 * Normalizes a DB row into the public mapping shape.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	private static function decorate( $row ) {
		return array(
			'id'                 => (int) $row['id'],
			'host'               => $row['host'],
			'path'               => $row['path'],
			'attachment_id'      => $row['attachment_id'] ? (int) $row['attachment_id'] : null,
			'custom_html'        => $row['custom_html'],
			'menu_id'            => $row['menu_id'] ? (int) $row['menu_id'] : null,
			'language'           => $row['language'],
			'redirect_404'       => $row['redirect_404'],
			'rewrite_rules'      => $row['rewrite_rules'] ? json_decode( $row['rewrite_rules'], true ) : null,
			'query_string_rules' => $row['query_string_rules'] ? json_decode( $row['query_string_rules'], true ) : null,
			'active'             => (bool) $row['active'],
			'verified'           => (bool) $row['verified'],
			'primary'            => (bool) $row['primary'],
			'created_at'         => $row['created_at'],
			'updated_at'         => $row['updated_at'],
		);
	}
}
