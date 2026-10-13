<?php
/**
 * Mapping values repository (dm_mapping_values table).
 *
 * A "mapping value" attaches an object (post, page, category, tag, archive,
 * product, custom post type) to a single-site mapping. This is the storage
 * behind the documented *global mapping* scopes (archive/category mapping,
 * parent page mapping, WooCommerce product mapping) — the object kinds are
 * validated against an allow-list, never interpolated raw.
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_Mapping_Values {

	/**
	 * Supported object kinds (commercial contract: post/page/archive/…).
	 *
	 * @var array
	 */
	const OBJECT_TYPES = array( 'post', 'page', 'category', 'tag', 'archive', 'product', 'cpt' );

	/**
	 * Cache group (shared).
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'dm';

	/**
	 * Current table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->base_prefix . DMS_TABLE_VALUES;
	}

	/**
	 * Whether an object type is supported.
	 *
	 * @param string $type Object type.
	 * @return bool
	 */
	public static function is_supported_type( $type ) {
		return is_string( $type ) && in_array( $type, self::OBJECT_TYPES, true );
	}

	/**
	 * Gets a single value row.
	 *
	 * @param int $value_id Value ID.
	 * @return array|null
	 */
	public static function get_value( int $value_id ) {
		global $wpdb;
		$row = wp_cache_get( 'ss_val_' . $value_id, self::CACHE_GROUP );
		if ( is_array( $row ) ) {
			return $row;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $value_id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted table name.
			ARRAY_A
		);
		if ( $row ) {
			wp_cache_set( 'ss_val_' . $value_id, $row, self::CACHE_GROUP );
		}
		return $row ? $row : null;
	}

	/**
	 * Sets (upserts) a mapping value.
	 *
	 * @param int    $mapping_id  Mapping ID (must exist).
	 * @param string $object_type One of self::OBJECT_TYPES.
	 * @param int    $object_id   Object ID.
	 * @param bool   $primary     Whether it is the primary mapping for that object.
	 * @return int|WP_Error Value ID.
	 */
	public static function set_value( int $mapping_id, string $object_type, int $object_id, bool $primary = false ) {
		global $wpdb;

		if ( ! self::is_supported_type( $object_type ) ) {
			return new WP_Error( 'dms_invalid_object_type', __( 'Unsupported object type.', 'domain-mapping-system' ), array( 'status' => 400 ) );
		}
		if ( $object_id < 1 ) {
			return new WP_Error( 'dms_invalid_object_id', __( 'Invalid object ID.', 'domain-mapping-system' ), array( 'status' => 400 ) );
		}
		$mapping = DMS_Single_Site_Mappings::get_mapping( $mapping_id );
		if ( is_wp_error( $mapping ) ) {
			return new WP_Error( 'dms_mapping_not_found', __( 'Mapping not found.', 'domain-mapping-system' ), array( 'status' => 404 ) );
		}

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::table() . ' WHERE mapping_id = %d AND object_type = %s AND object_id = %d LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted table name.
				$mapping_id,
				$object_type,
				$object_id
			)
		);

		if ( $existing ) {
			$updated = $wpdb->update(
				self::table(),
				array( 'primary' => (int) $primary ),
				array( 'id' => (int) $existing ),
				array( '%d' ),
				array( '%d' )
			);
			if ( false === $updated ) {
				return new WP_Error( 'dms_value_update_failed', __( 'Could not update the mapping value.', 'domain-mapping-system' ), array( 'status' => 500 ) );
			}
			wp_cache_delete( 'ss_val_' . (int) $existing, self::CACHE_GROUP );
			return (int) $existing;
		}

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'mapping_id'  => $mapping_id,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'primary'     => (int) $primary,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%d', '%d', '%s' )
		);
		if ( false === $inserted || ! $wpdb->insert_id ) {
			return new WP_Error( 'dms_value_insert_failed', __( 'Could not store the mapping value.', 'domain-mapping-system' ), array( 'status' => 500 ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Flips the primary flag of an existing value.
	 *
	 * @param int  $value_id Value ID.
	 * @param bool $primary  New primary flag.
	 * @return bool|WP_Error
	 */
	public static function set_primary( int $value_id, bool $primary ) {
		global $wpdb;
		if ( null === self::get_value( $value_id ) ) {
			return new WP_Error( 'dms_value_not_found', __( 'Mapping value not found.', 'domain-mapping-system' ), array( 'status' => 404 ) );
		}
		$updated = $wpdb->update(
			self::table(),
			array( 'primary' => (int) $primary ),
			array( 'id' => $value_id ),
			array( '%d' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			return new WP_Error( 'dms_value_update_failed', __( 'Could not update the mapping value.', 'domain-mapping-system' ), array( 'status' => 500 ) );
		}
		wp_cache_delete( 'ss_val_' . $value_id, self::CACHE_GROUP );
		return true;
	}

	/**
	 * Removes a value by ID.
	 *
	 * @param int $value_id Value ID.
	 * @return bool|WP_Error
	 */
	public static function remove_value( int $value_id ) {
		global $wpdb;
		if ( null === self::get_value( $value_id ) ) {
			return new WP_Error( 'dms_value_not_found', __( 'Mapping value not found.', 'domain-mapping-system' ), array( 'status' => 404 ) );
		}
		$deleted = $wpdb->delete(
			self::table(),
			array( 'id' => $value_id ),
			array( '%d' )
		);
		if ( false === $deleted ) {
			return new WP_Error( 'dms_value_delete_failed', __( 'Could not remove the mapping value.', 'domain-mapping-system' ), array( 'status' => 500 ) );
		}
		wp_cache_delete( 'ss_val_' . $value_id, self::CACHE_GROUP );
		return true;
	}

	/**
	 * Lists values for a mapping.
	 *
	 * @param int $mapping_id Mapping ID.
	 * @return array|WP_Error
	 */
	public static function get_values( int $mapping_id ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, mapping_id, object_type, object_id, primary, created_at FROM ' . self::table() . ' WHERE mapping_id = %d ORDER BY id ASC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted table name.
				$mapping_id
			),
			ARRAY_A
		);
		if ( $wpdb->last_error ) {
			return new WP_Error( 'dms_query_failed', __( 'Mapping value query failed.', 'domain-mapping-system' ), array( 'status' => 500 ) );
		}
		$values = array();
		foreach ( (array) $rows as $row ) {
			$values[] = array(
				'id'          => (int) $row['id'],
				'mapping_id'  => (int) $row['mapping_id'],
				'object_type' => $row['object_type'],
				'object_id'   => (int) $row['object_id'],
				'primary'     => (bool) $row['primary'],
				'created_at'  => $row['created_at'],
			);
		}
		return $values;
	}

	/**
	 * Resolves which mappings target a given object (used by the future
	 * redirect/rewrite layer for global archive/page/product scopes).
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return array|WP_Error List of mapping arrays.
	 */
	public static function get_mappings_for_object( string $object_type, int $object_id ) {
		global $wpdb;
		if ( ! self::is_supported_type( $object_type ) ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT m.* FROM ' . self::table() . ' v INNER JOIN ' . DMS_Single_Site_Mappings::table() . ' m ON m.id = v.mapping_id WHERE v.object_type = %s AND v.object_id = %d ORDER BY v.primary DESC, m.id ASC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted table names.
				$object_type,
				$object_id
			),
			ARRAY_A
		);
		if ( $wpdb->last_error ) {
			return new WP_Error( 'dms_query_failed', __( 'Mapping value query failed.', 'domain-mapping-system' ), array( 'status' => 500 ) );
		}
		$mappings = array();
		foreach ( (array) $rows as $row ) {
			$mappings[] = array(
				'id'      => (int) $row['id'],
				'host'    => $row['host'],
				'path'    => $row['path'],
				'primary' => (bool) $row['primary'],
			);
		}
		return $mappings;
	}

	/**
	 * Removes every value attached to a mapping (cascade on delete).
	 *
	 * @param int $mapping_id Mapping ID.
	 * @return bool
	 */
	public static function remove_all_for_mapping( int $mapping_id ) {
		global $wpdb;
		$wpdb->delete(
			self::table(),
			array( 'mapping_id' => $mapping_id ),
			array( '%d' )
		);
		return true;
	}
}
