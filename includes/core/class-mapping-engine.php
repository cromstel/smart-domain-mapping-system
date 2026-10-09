<?php
/**
 * Domain mapping service layer: wp_sitemeta CRUD, primary-domain sync and
 * lookup helpers.
 *
 * Architecture note — how mapped domains actually resolve:
 * WordPress resolves the incoming domain during `wp-includes/ms-settings.php`
 * (required from `wp-settings.php` before mu-plugins and regular plugins are
 * loaded). No plugin-level hook exists at that point: the `ms_not_installed`
 * filter does not exist in core, and `sunrise.php` is the only supported
 * early interception point (deliberately not used per spec).
 *
 * Resolution is therefore implemented through core's own mechanism:
 * `set_primary()` writes the mapped domain into `wp_blogs.domain` via
 * `wp_update_site()` — the same thing the Network Admin "Site Address" field
 * does — and core's `get_site_by_path()` then resolves it natively.
 *
 * Only the primary mapping of a site can be served by WordPress itself.
 * Additional (non-primary) mappings are managed/verified data; pointing them
 * at the site is hosting-layer work (parked domain / rewrite), which the
 * spec explicitly leaves to the hosting environment.
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_Mapping_Engine {
	/**
	 * Object cache group for domain lookups.
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'dm';

	/**
	 * sitemeta key prefix for mappings.
	 *
	 * @var string
	 */
	const META_PREFIX = 'dm_domain_';

	/**
	 * sitemeta key prefix for the active/inactive state.
	 *
	 * @var string
	 */
	const ACTIVE_PREFIX = 'dm_active_';

	/**
	 * sitemeta key prefix storing a blog's pre-mapping domain.
	 *
	 * @var string
	 */
	const ORIGINAL_PREFIX = 'dm_original_';

	/**
	 * Domain validation pattern (optional `*.` wildcard prefix allowed).
	 *
	 * @var string
	 */
	const DOMAIN_PATTERN = '/^(\*\.)?([a-z0-9]([a-z0-9\-]*[a-z0-9])?\.)+[a-z]{2,}$/';

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		// Serve pending HTTP-01 style challenges for domains that resolve to
		// this site (i.e. the site's primary mapped domain).
		add_action( 'template_redirect', array( $this, 'serve_http_challenge' ), 1 );
		// Canonical redirect to the site's primary mapped domain.
		if ( function_exists( 'dm_canonical_redirect' ) ) {
			add_action( 'template_redirect', 'dm_canonical_redirect', 2 );
		}
	}

	/**
	 * Serves a pending verification challenge file over HTTP-01.
	 *
	 * The URL segment is the plain token; the body echoes it. Only pending or
	 * failed, unexpired challenges are served.
	 */
	public function serve_http_challenge() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$uri  = wp_unslash( $_SERVER['REQUEST_URI'] );
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			return;
		}
		$prefix = '/.well-known/acme-challenge/';
		if ( ! str_starts_with( $path, $prefix ) ) {
			return;
		}
		$token = sanitize_text_field( substr( $path, strlen( $prefix ) ) );
		if ( '' === $token ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->base_prefix . DMS_TABLE_VERIFICATIONS;
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE token_hash = %s AND status IN ( 'pending', 'failed' ) AND ( expires_at IS NULL OR expires_at > %s ) ORDER BY id DESC LIMIT 1",
				hash_hmac( 'sha256', $token, AUTH_KEY ),
				current_time( 'mysql' )
			)
		);
		if ( ! $row ) {
			return;
		}
		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( $token );
		exit;
	}

	/**
	 * Normalizes a domain per spec: trims, lowercases, strips protocol,
	 * strips a leading `www.` and a trailing dot/slash.
	 *
	 * @param mixed $domain Raw domain input.
	 * @return string|false Normalized domain or false when invalid.
	 */
	public static function normalize_domain( $domain ) {
		if ( ! is_string( $domain ) ) {
			return false;
		}
		$domain = strtolower( trim( $domain ) );
		$domain = preg_replace( '#^[a-z][a-z0-9+.\-]*://#', '', $domain );
		$domain = preg_replace( '#^www\.#', '', $domain );
		$domain = rtrim( $domain, './' );
		if ( '' === $domain || strlen( $domain ) > 253 ) {
			return false;
		}
		if ( ! preg_match( self::DOMAIN_PATTERN, $domain ) ) {
			return false;
		}
		return $domain;
	}

	/**
	 * Builds the canonical sitemeta key for a domain.
	 *
	 * @param string $domain Domain (raw or normalized).
	 * @return string|null Key, or null when the domain is invalid.
	 */
	public static function meta_key_for( $domain ) {
		$normalized = self::normalize_domain( $domain );
		if ( false === $normalized ) {
			return null;
		}
		return self::META_PREFIX . $normalized;
	}

	/**
	 * Looks up a mapping by request domain (exact match, then wildcard parents).
	 *
	 * @param string $domain Request host.
	 * @return array|null {id, domain, blog_id} or null when not mapped.
	 */
	public static function get_mapping_for_domain( $domain ) {
		$normalized = self::normalize_domain( $domain );
		if ( false === $normalized ) {
			return null;
		}
		$cache_key = self::META_PREFIX . $normalized;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$mapping = self::query_mapping_by_key( $cache_key );

		// Wildcard fallback: *.example.com covers a.b.example.com etc.
		if ( null === $mapping && ! str_starts_with( $normalized, '*.' ) ) {
			$parts = explode( '.', $normalized );
			$total = count( $parts );
			for ( $i = 1; $i < $total - 1; $i++ ) {
				$candidate = '*.' . implode( '.', array_slice( $parts, $i ) );
				$mapping   = self::query_mapping_by_key( self::META_PREFIX . $candidate );
				if ( null !== $mapping ) {
					break;
				}
			}
		}

		if ( null !== $mapping ) {
			wp_cache_set( $cache_key, $mapping, self::CACHE_GROUP, HOUR_IN_SECONDS );
		}
		return $mapping;
	}

	/**
	 * Adds a mapping.
	 *
	 * @param int  $blog_id      Target blog ID.
	 * @param string $domain     Domain to map.
	 * @param bool  $make_primary Whether to promote to primary immediately.
	 * @return int|WP_Error New mapping (meta) ID on success.
	 */
	public static function add_mapping( int $blog_id, string $domain, bool $make_primary = false ) {
		global $wpdb;
		$normalized = self::normalize_domain( $domain );
		if ( false === $normalized ) {
			return new WP_Error( 'dm_invalid_domain', 'Invalid domain format.', array( 'status' => 400 ) );
		}
		if ( ! get_site( $blog_id ) ) {
			return new WP_Error( 'dm_invalid_site', 'Site not found.', array( 'status' => 404 ) );
		}
		$network_id = get_current_network_id();
		$key        = self::META_PREFIX . $normalized;
		$existing   = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key = %s LIMIT 1",
				$network_id,
				$key
			)
		);
		if ( null !== $existing ) {
			return new WP_Error( 'dm_domain_exists', 'Domain is already mapped.', array( 'status' => 409 ) );
		}

		$wpdb->insert(
			$wpdb->sitemeta,
			array(
				'site_id'    => $network_id,
				'meta_key'   => $key,
				'meta_value' => (string) $blog_id,
			),
			array( '%d', '%s', '%s' )
		);
		if ( $wpdb->last_error || ! $wpdb->insert_id ) {
			return new WP_Error( 'dm_insert_failed', 'Failed to insert mapping: ' . sanitize_text_field( $wpdb->last_error ), array( 'status' => 500 ) );
		}
		$mapping_id = (int) $wpdb->insert_id;

		update_site_meta( $network_id, self::ACTIVE_PREFIX . $normalized, '1' );

		if ( $make_primary ) {
			$primary = self::set_primary( $mapping_id );
			if ( is_wp_error( $primary ) ) {
				// Roll the half-created mapping back so create stays atomic.
				$wpdb->delete(
					$wpdb->sitemeta,
					array(
						'meta_id'  => $mapping_id,
						'meta_key' => $key,
					),
					array( '%d', '%s' )
				);
				delete_site_meta( $network_id, self::ACTIVE_PREFIX . $normalized );
				wp_cache_delete( $key, self::CACHE_GROUP );
				return $primary;
			}
		}

		DMS_Logging::get_instance()->log(
			$blog_id,
			$normalized,
			get_current_user_id(),
			'mapping.create',
			wp_json_encode(
				array(
					'blog_id'      => $blog_id,
					'make_primary' => $make_primary,
				)
			)
		);
		return $mapping_id;
	}

	/**
	 * Fetches a single mapping by meta ID (scoped to mapping keys).
	 *
	 * @param int $mapping_id sitemeta meta_id.
	 * @return array|WP_Error Mapping with active/verified/primary flags.
	 */
	public static function get_mapping( int $mapping_id ) {
		$row = self::query_mapping_by_id( $mapping_id );
		if ( null === $row ) {
			return new WP_Error( 'dm_not_found', 'Mapping not found.', array( 'status' => 404 ) );
		}
		return self::decorate( $row );
	}

	/**
	 * Lists mappings.
	 *
	 * @param array $where Optional filters: blog_id, domain.
	 * @return array|WP_Error List of mapping arrays.
	 */
	public static function list_mappings( array $where = array() ) {
		global $wpdb;

		$sql    = "SELECT meta_id, meta_key, meta_value FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key LIKE %s";
		$params = array(
			get_current_network_id(),
			$wpdb->esc_like( self::META_PREFIX ) . '%',
		);

		if ( ! empty( $where['blog_id'] ) ) {
			$sql     .= ' AND meta_value = %s';
			$params[] = (string) absint( $where['blog_id'] );
		}
		if ( ! empty( $where['domain'] ) ) {
			$key = self::meta_key_for( $where['domain'] );
			if ( null === $key ) {
				return array();
			}
			$sql     .= ' AND meta_key = %s';
			$params[] = $key;
		}
		$sql .= ' ORDER BY meta_id ASC';

		// $sql is assembled from fixed fragments; all values are bound via prepare().
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
		if ( $wpdb->last_error ) {
			return new WP_Error( 'dm_query_failed', 'Mapping query failed.', array( 'status' => 500 ) );
		}
		if ( empty( $rows ) ) {
			return array();
		}

		$mappings = array();
		foreach ( $rows as $row ) {
			$mappings[] = array(
				'id'       => (int) $row->meta_id,
				'domain'   => substr( $row->meta_key, strlen( self::META_PREFIX ) ),
				'blog_id'  => (int) $row->meta_value,
				'active'   => true,
				'verified' => false,
				'primary'  => false,
			);
		}

		// Batched enrichment: avoid per-row queries (no N+1).
		$active_map   = self::fetch_active_states();
		$verified_map = self::fetch_verified_domains( wp_list_pluck( $mappings, 'domain' ) );
		$blog_domains = array();
		foreach ( array_unique( wp_list_pluck( $mappings, 'blog_id' ) ) as $blog_id ) {
			$site = get_site( $blog_id );
			if ( $site ) {
				$blog_domains[ $blog_id ] = self::normalize_domain( $site->domain );
			}
		}

		foreach ( $mappings as &$mapping ) {
			$mapping['active']   = isset( $active_map[ $mapping['domain'] ] ) ? $active_map[ $mapping['domain'] ] : true;
			$mapping['verified'] = isset( $verified_map[ $mapping['domain'] ] );
			$mapping['primary']  = isset( $blog_domains[ $mapping['blog_id'] ] )
				&& false !== $blog_domains[ $mapping['blog_id'] ]
				&& $blog_domains[ $mapping['blog_id'] ] === $mapping['domain'];
		}
		unset( $mapping );

		return $mappings;
	}

	/**
	 * Updates a mapping's active state and/or promotes it to primary.
	 *
	 * @param int        $mapping_id  Mapping (meta) ID.
	 * @param bool|null  $active      True/false to enable/disable, null to skip.
	 * @param bool       $make_primary Whether to promote to primary.
	 * @return array|WP_Error Updated mapping.
	 */
	public static function update_mapping( int $mapping_id, $active = null, bool $make_primary = false ) {
		$row = self::query_mapping_by_id( $mapping_id );
		if ( null === $row ) {
			return new WP_Error( 'dm_not_found', 'Mapping not found.', array( 'status' => 404 ) );
		}

		if ( null !== $active ) {
			$result = $active
				? self::enable_mapping( $mapping_id )
				: self::disable_mapping( $mapping_id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( $make_primary ) {
			$result = self::set_primary( $mapping_id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return self::get_mapping( $mapping_id );
	}

	/**
	 * Enables a mapping.
	 *
	 * @param int $mapping_id Mapping (meta) ID.
	 * @return array|WP_Error Updated mapping.
	 */
	public static function enable_mapping( int $mapping_id ) {
		$row = self::query_mapping_by_id( $mapping_id );
		if ( null === $row ) {
			return new WP_Error( 'dm_not_found', 'Mapping not found.', array( 'status' => 404 ) );
		}
		$domain = substr( $row->meta_key, strlen( self::META_PREFIX ) );
		$gate   = self::can_activate( $domain );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		update_site_meta( get_current_network_id(), self::ACTIVE_PREFIX . $domain, '1' );
		DMS_Logging::get_instance()->log( (int) $row->meta_value, $domain, get_current_user_id(), 'mapping.enable', '' );
		return self::get_mapping( $mapping_id );
	}

	/**
	 * Disables a mapping. A primary mapping is demoted back to the blog's
	 * pre-mapping domain first, so a disabled domain stops serving.
	 *
	 * @param int $mapping_id Mapping (meta) ID.
	 * @return array|WP_Error Updated mapping.
	 */
	public static function disable_mapping( int $mapping_id ) {
		$row = self::query_mapping_by_id( $mapping_id );
		if ( null === $row ) {
			return new WP_Error( 'dm_not_found', 'Mapping not found.', array( 'status' => 404 ) );
		}
		$domain  = substr( $row->meta_key, strlen( self::META_PREFIX ) );
		$blog_id = (int) $row->meta_value;
		update_site_meta( get_current_network_id(), self::ACTIVE_PREFIX . $domain, '0' );
		self::maybe_restore_primary( $blog_id, $domain );
		DMS_Logging::get_instance()->log( $blog_id, $domain, get_current_user_id(), 'mapping.disable', '' );
		return self::get_mapping( $mapping_id );
	}

	/**
	 * Removes a mapping (scoped to mapping keys). A primary mapping is
	 * demoted back to the blog's pre-mapping domain first.
	 *
	 * @param int $mapping_id Mapping (meta) ID.
	 * @return true|WP_Error
	 */
	public static function remove_mapping( int $mapping_id ) {
		global $wpdb;

		$row = self::query_mapping_by_id( $mapping_id );
		if ( null === $row ) {
			return new WP_Error( 'dm_not_found', 'Mapping not found.', array( 'status' => 404 ) );
		}
		$domain  = substr( $row->meta_key, strlen( self::META_PREFIX ) );
		$blog_id = (int) $row->meta_value;

		self::maybe_restore_primary( $blog_id, $domain );

		$deleted = $wpdb->delete(
			$wpdb->sitemeta,
			array(
				'meta_id'  => $mapping_id,
				'meta_key' => $row->meta_key,
			),
			array( '%d', '%s' )
		);
		if ( false === $deleted ) {
			return new WP_Error( 'dm_delete_failed', 'Failed to delete mapping.', array( 'status' => 500 ) );
		}

		delete_site_meta( get_current_network_id(), self::ACTIVE_PREFIX . $domain );
		wp_cache_delete( self::META_PREFIX . $domain, self::CACHE_GROUP );

		DMS_Logging::get_instance()->log( $blog_id, $domain, get_current_user_id(), 'mapping.remove', '' );
		return true;
	}

	/**
	 * Promotes a mapping to the site's primary domain by syncing
	 * `wp_blogs.domain` through `wp_update_site()` (core's own mechanism).
	 *
	 * The blog's pre-mapping domain is stored once so disabling/removing the
	 * primary mapping can restore it.
	 *
	 * @param int $mapping_id Mapping (meta) ID.
	 * @return array|WP_Error Updated mapping with primary = true.
	 */
	public static function set_primary( int $mapping_id ) {
		global $wpdb;

		$row = self::query_mapping_by_id( $mapping_id );
		if ( null === $row ) {
			return new WP_Error( 'dm_not_found', 'Mapping not found.', array( 'status' => 404 ) );
		}
		$domain  = substr( $row->meta_key, strlen( self::META_PREFIX ) );
		$blog_id = (int) $row->meta_value;

		if ( str_starts_with( $domain, '*.' ) ) {
			return new WP_Error( 'dm_invalid_primary', 'Wildcard domains cannot be set as primary.', array( 'status' => 400 ) );
		}
		if ( ! self::is_active( $domain ) ) {
			return new WP_Error( 'dm_mapping_inactive', 'Enable the mapping before making it primary.', array( 'status' => 403 ) );
		}
		$gate = self::can_activate( $domain );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$site = get_site( $blog_id );
		if ( ! $site ) {
			return new WP_Error( 'dm_invalid_site', 'Site not found.', array( 'status' => 404 ) );
		}
		if ( self::normalize_domain( $site->domain ) === $domain ) {
			return self::get_mapping( $mapping_id );
		}

		$network_id      = get_current_network_id();
		$stored_original = get_site_meta( $network_id, self::ORIGINAL_PREFIX . $blog_id, true );

		// Savepoints nest safely inside any outer transaction (e.g. the
		// PHPUnit test suite's), unlike START TRANSACTION which would
		// implicitly commit it.
		$wpdb->query( 'SAVEPOINT dm_set_primary' );
		if ( ! $stored_original ) {
			update_site_meta( $network_id, self::ORIGINAL_PREFIX . $blog_id, $site->domain );
		}
		$updated = wp_update_site( $blog_id, array( 'domain' => $domain ) );
		if ( is_wp_error( $updated ) ) {
			$wpdb->query( 'ROLLBACK TO SAVEPOINT dm_set_primary' );
			$wpdb->query( 'RELEASE SAVEPOINT dm_set_primary' );
			if ( ! $stored_original ) {
				delete_site_meta( $network_id, self::ORIGINAL_PREFIX . $blog_id );
			}
			return new WP_Error( 'dm_primary_failed', $updated->get_error_message(), array( 'status' => 500 ) );
		}
		$wpdb->query( 'RELEASE SAVEPOINT dm_set_primary' );

		DMS_Logging::get_instance()->log( $blog_id, $domain, get_current_user_id(), 'mapping.primary', '' );
		return self::get_mapping( $mapping_id );
	}

	/**
	 * Creates (initiates) a domain ownership challenge.
	 *
	 * @param int    $mapping_id Mapping (meta) ID.
	 * @param string $method     Verification method: dns|http.
	 * @return array|WP_Error Challenge details (domain, token, method, challenge_path).
	 */
	public static function generate_verification( int $mapping_id, string $method = 'dns' ) {
		$row = self::query_mapping_by_id( $mapping_id );
		if ( null === $row ) {
			return new WP_Error( 'dm_not_found', 'Mapping not found.', array( 'status' => 404 ) );
		}
		$domain = substr( $row->meta_key, strlen( self::META_PREFIX ) );
		return DMS_DNS_Verification::get_instance()->generate_challenge(
			(int) $row->meta_value,
			$domain,
			$method
		);
	}

	/**
	 * Completes a domain ownership challenge by checking the published token
	 * against the domain (HTTP fetch or DNS TXT lookup).
	 *
	 * @param int    $mapping_id Mapping (meta) ID.
	 * @param string $token      Plaintext token the user published.
	 * @return array|WP_Error Verification result.
	 */
	public static function verify_mapping( int $mapping_id, string $token ) {
		$row = self::query_mapping_by_id( $mapping_id );
		if ( null === $row ) {
			return new WP_Error( 'dm_not_found', 'Mapping not found.', array( 'status' => 404 ) );
		}
		$domain = substr( $row->meta_key, strlen( self::META_PREFIX ) );
		return DMS_DNS_Verification::get_instance()->verify_challenge(
			(int) $row->meta_value,
			$domain,
			$token
		);
	}

	/**
	 * Raw lookup by exact sitemeta key.
	 *
	 * @param string $key Full sitemeta key.
	 * @return array|null {id, domain, blog_id} or null.
	 */
	private static function query_mapping_by_key( $key ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT meta_id, meta_key, meta_value FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key = %s LIMIT 1",
				get_current_network_id(),
				$key
			)
		);
		if ( ! $row ) {
			return null;
		}
		return array(
			'id'      => (int) $row->meta_id,
			'domain'  => substr( $row->meta_key, strlen( self::META_PREFIX ) ),
			'blog_id' => (int) $row->meta_value,
		);
	}

	/**
	 * Raw lookup by meta ID, scoped to mapping keys so an ID from another
	 * sitemeta row can never be read or mutated.
	 *
	 * @param int $mapping_id sitemeta meta_id.
	 * @return object|null DB row or null.
	 */
	private static function query_mapping_by_id( int $mapping_id ) {
		global $wpdb;
		$like = $wpdb->esc_like( self::META_PREFIX ) . '%';
		$row  = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT meta_id, meta_key, meta_value FROM {$wpdb->sitemeta} WHERE meta_id = %d AND site_id = %d AND meta_key LIKE %s LIMIT 1",
				$mapping_id,
				get_current_network_id(),
				$like
			)
		);
		return $row ? $row : null;
	}

	/**
	 * Adds state flags to a raw mapping row.
	 *
	 * @param object $row sitemeta row (meta_id, meta_key, meta_value).
	 * @return array
	 */
	private static function decorate( $row ) {
		$domain = substr( $row->meta_key, strlen( self::META_PREFIX ) );
		return array(
			'id'       => (int) $row->meta_id,
			'domain'   => $domain,
			'blog_id'  => (int) $row->meta_value,
			'active'   => self::is_active( $domain ),
			'verified' => self::is_verified( $domain ),
			'primary'  => self::is_primary( (int) $row->meta_value, $domain ),
		);
	}

	/**
	 * Whether the mapping is active (absent flag = active for legacy rows).
	 *
	 * @param string $domain Normalized domain.
	 * @return bool
	 */
	private static function is_active( $domain ) {
		$value = get_site_meta( get_current_network_id(), self::ACTIVE_PREFIX . $domain, true );
		return '' === $value || '1' === (string) $value;
	}

	/**
	 * Whether the domain has at least one completed verification.
	 *
	 * @param string $domain Normalized domain.
	 * @return bool
	 */
	private static function is_verified( $domain ) {
		global $wpdb;
		$table = $wpdb->base_prefix . DMS_TABLE_VERIFICATIONS;
		$id    = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE domain = %s AND status = 'verified' ORDER BY id DESC LIMIT 1",
				$domain
			)
		);
		return null !== $id;
	}

	/**
	 * Whether the mapping is the blog's current primary domain.
	 *
	 * @param int    $blog_id Blog ID.
	 * @param string $domain  Normalized mapping domain.
	 * @return bool
	 */
	private static function is_primary( $blog_id, $domain ) {
		$site = get_site( $blog_id );
		if ( ! $site ) {
			return false;
		}
		$site_domain = self::normalize_domain( $site->domain );
		return false !== $site_domain && $site_domain === $domain;
	}

	/**
	 * Activation gate: a domain whose verification was requested but not
	 * completed cannot be activated or promoted. Domains that never went
	 * through verification (verification is optional per spec) are allowed.
	 *
	 * @param string $domain Normalized domain.
	 * @return null|WP_Error
	 */
	private static function can_activate( $domain ) {
		global $wpdb;
		$table = $wpdb->base_prefix . DMS_TABLE_VERIFICATIONS;

		$verified = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE domain = %s AND status = 'verified' ORDER BY id DESC LIMIT 1",
				$domain
			)
		);
		if ( null !== $verified ) {
			return null;
		}
		$attempted = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE domain = %s ORDER BY id DESC LIMIT 1",
				$domain
			)
		);
		if ( null === $attempted ) {
			return null;
		}
		return new WP_Error(
			'dm_requires_verification',
			'Domain verification was requested but has not completed.',
			array( 'status' => 403 )
		);
	}

	/**
	 * Restores the blog's pre-mapping domain when the given mapping is the
	 * blog's current primary.
	 *
	 * @param int    $blog_id Blog ID.
	 * @param string $domain  Normalized domain being demoted/removed.
	 */
	private static function maybe_restore_primary( $blog_id, $domain ) {
		if ( ! self::is_primary( $blog_id, $domain ) ) {
			return;
		}
		$network_id = get_current_network_id();
		$original   = get_site_meta( $network_id, self::ORIGINAL_PREFIX . $blog_id, true );
		if ( ! $original ) {
			return;
		}
		$result = wp_update_site( $blog_id, array( 'domain' => $original ) );
		if ( ! is_wp_error( $result ) ) {
			delete_site_meta( $network_id, self::ORIGINAL_PREFIX . $blog_id );
		}
	}

	/**
	 * Batch-loads dm_active_* flags.
	 *
	 * @return array domain => bool (only explicitly flagged rows).
	 */
	private static function fetch_active_states() {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key LIKE %s",
				get_current_network_id(),
				$wpdb->esc_like( self::ACTIVE_PREFIX ) . '%'
			)
		);
		$map  = array();
		foreach ( (array) $rows as $row ) {
			$map[ substr( $row->meta_key, strlen( self::ACTIVE_PREFIX ) ) ] = '1' === (string) $row->meta_value;
		}
		return $map;
	}

	/**
	 * Batch-loads verified domains.
	 *
	 * @param array $domains Domains to check.
	 * @return array domain => true for verified domains.
	 */
	private static function fetch_verified_domains( array $domains ) {
		global $wpdb;
		$map     = array();
		$domains = array_values( array_unique( array_filter( $domains ) ) );
		if ( empty( $domains ) ) {
			return $map;
		}
		$table = $wpdb->base_prefix . DMS_TABLE_VERIFICATIONS;
		$in    = implode( ',', array_fill( 0, count( $domains ), '%s' ) );
		$sql   = "SELECT DISTINCT domain FROM {$table} WHERE status = 'verified' AND domain IN ( {$in} )";
		// $sql is assembled from fixed fragments; every domain is bound via prepare().
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$domains ) );
		foreach ( (array) $rows as $row ) {
			$map[ $row->domain ] = true;
		}
		return $map;
	}
}
