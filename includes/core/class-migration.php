<?php
/**
 * Data migrations: legacy mapping tables and Mercator sitemeta keys.
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_Migration {

	/**
	 * Migrates rows from a legacy mapping table (spec: `wp_domain_mappings`,
	 * plus the historical `dm_domain_mappings` name) into wp_sitemeta.
	 */
	public static function migrate_legacy_tables() {
		global $wpdb;

		$candidates = array_unique(
			array(
				$wpdb->base_prefix . 'domain_mappings',
				$wpdb->base_prefix . 'dm_domain_mappings',
			)
		);

		foreach ( $candidates as $legacy_table ) {
			// Table name comes from base_prefix (trusted, not user input).
			if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$legacy_table}'" ) ) {
				continue;
			}
			$rows = $wpdb->get_results( "SELECT * FROM {$legacy_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static table name.
			foreach ( (array) $rows as $row ) {
				self::import_legacy_row( $row );
			}
		}
	}

	/**
	 * Imports one legacy row.
	 *
	 * @param object $row Legacy row (blog_id, domain, is_primary, active).
	 */
	private static function import_legacy_row( $row ) {
		global $wpdb;

		$blog_id = isset( $row->blog_id ) ? absint( $row->blog_id ) : 0;
		$domain  = isset( $row->domain ) ? DMS_Mapping_Engine::normalize_domain( $row->domain ) : false;
		if ( ! $blog_id || false === $domain || ! get_site( $blog_id ) ) {
			return;
		}

		$network_id = get_current_network_id();
		$key        = DMS_Mapping_Engine::META_PREFIX . $domain;
		$existing   = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->sitemeta} WHERE meta_key = %s AND site_id = %d",
				$key,
				$network_id
			)
		);

		if ( ! $existing ) {
			$wpdb->insert(
				$wpdb->sitemeta,
				array(
					'site_id'    => $network_id,
					'meta_key'   => $key,
					'meta_value' => (string) $blog_id,
				),
				array( '%d', '%s', '%s' )
			);
		}

		// Preserve legacy active flag; default is active.
		$active = ! isset( $row->active ) || ! in_array( (string) $row->active, array( '0', '' ), true );
		update_site_meta(
			$network_id,
			DMS_Mapping_Engine::ACTIVE_PREFIX . $domain,
			$active ? '1' : '0'
		);

		// Promote to primary when the legacy row said so.
		if ( isset( $row->is_primary ) && in_array( (string) $row->is_primary, array( '1', 'true' ), true ) ) {
			$mapping_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_id FROM {$wpdb->sitemeta} WHERE meta_key = %s AND site_id = %d",
					$key,
					$network_id
				)
			);
			if ( $mapping_id ) {
				DMS_Mapping_Engine::set_primary( (int) $mapping_id );
			}
		}

		DMS_Logging::get_instance()->log(
			$blog_id,
			$domain,
			0,
			'migrate.mapping',
			wp_json_encode( array( 'old_table' => 'domain_mappings' ) )
		);
	}

	/**
	 * Migrates Mercator sitemeta mappings into dm_domain_* keys.
	 *
	 * Mercator keys are left in place (spec allows "delete or ignore";
	 * ignoring is non-destructive).
	 */
	public static function migrate_mercator() {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key LIKE %s",
				get_current_network_id(),
				$wpdb->esc_like( 'mercator_' ) . '%'
			)
		);
		if ( empty( $rows ) ) {
			return;
		}

		$network_id = get_current_network_id();
		foreach ( $rows as $row ) {
			$meta    = maybe_unserialize( $row->meta_value );
			$blog_id = 0;
			$domain  = false;
			$active  = true;

			if ( is_array( $meta ) ) {
				if ( isset( $meta['domain'] ) ) {
					$domain = DMS_Mapping_Engine::normalize_domain( $meta['domain'] );
				}
				if ( isset( $meta['blog_id'] ) ) {
					$blog_id = absint( $meta['blog_id'] );
				}
				if ( isset( $meta['active'] ) ) {
					$active = (bool) $meta['active'];
				}
			} elseif ( is_string( $meta ) ) {
				$domain = DMS_Mapping_Engine::normalize_domain( $meta );
			}

			if ( false === $domain ) {
				continue;
			}
			if ( ! $blog_id ) {
				// Fall back to the numeric suffix of the legacy key
				// (mercator_<blog_id>).
				$suffix  = substr( $row->meta_key, strlen( 'mercator_' ) );
				$blog_id = absint( $suffix );
			}
			if ( ! $blog_id || ! get_site( $blog_id ) ) {
				continue;
			}

			$key    = DMS_Mapping_Engine::META_PREFIX . $domain;
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_value FROM {$wpdb->sitemeta} WHERE meta_key = %s AND site_id = %d",
					$key,
					$network_id
				)
			);
			if ( ! $exists ) {
				$wpdb->insert(
					$wpdb->sitemeta,
					array(
						'site_id'    => $network_id,
						'meta_key'   => $key,
						'meta_value' => (string) $blog_id,
					),
					array( '%d', '%s', '%s' )
				);
			}
			update_site_meta(
				$network_id,
				DMS_Mapping_Engine::ACTIVE_PREFIX . $domain,
				$active ? '1' : '0'
			);

			DMS_Logging::get_instance()->log(
				$blog_id,
				$domain,
				0,
				'migrate.mercator',
				wp_json_encode( array( 'old_key' => $row->meta_key ) )
			);
		}
	}
}
