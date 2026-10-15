<?php
/**
 * Rewrite engine core.
 *
 * Parses and applies rewrite rules and query string rules from mapped domains.
 * Used by the mu-plugins drop-in for request-time processing.
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_Rewrite_Engine {

	/**
	 * Cache group for rewrite rules.
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'dm_rewrite';

	/**
	 * Cache key for rewrite rules.
	 *
	 * @var string
	 */
	const CACHE_KEY = 'dms_rewrite_rules';

	/**
	 * Gets cached rewrite rules for a host.
	 *
	 * @param string $host Normalized host.
	 * @return array
	 */
	public static function get_rules_for_host( $host ) {
		$cache = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP );
		if ( false !== $cache && isset( $cache[ $host ] ) ) {
			return $cache[ $host ];
		}

		$rules = self::fetch_rules_for_host( $host );
		self::prime_cache( $host, $rules );
		return $rules;
	}

	/**
	 * Fetches rewrite rules from the database for a host.
	 *
	 * @param string $host Normalized host.
	 * @return array
	 */
	private static function fetch_rules_for_host( $host ) {
		if ( ! class_exists( 'DMS_Single_Site_Mappings' ) ) {
			return array();
		}

		$mappings = DMS_Single_Site_Mappings::list_mappings(
			array(
				'host'   => $host,
				'status' => 'active',
			)
		);
		if ( is_wp_error( $mappings ) || empty( $mappings ) ) {
			return array();
		}

		$rules = array();
		foreach ( $mappings as $mapping ) {
			// Path rewrite rules.
			if ( ! empty( $mapping['rewrite_rules'] ) && is_array( $mapping['rewrite_rules'] ) ) {
				foreach ( $mapping['rewrite_rules'] as $rule ) {
					$rule = self::normalize_rewrite_rule( $rule, $mapping );
					if ( $rule ) {
						$rules['rewrite'][] = $rule;
					}
				}
			}

			// Query string rules.
			if ( ! empty( $mapping['query_string_rules'] ) && is_array( $mapping['query_string_rules'] ) ) {
				foreach ( $mapping['query_string_rules'] as $rule ) {
					$rule = self::normalize_query_rule( $rule, $mapping );
					if ( $rule ) {
						$rules['query'][] = $rule;
					}
				}
			}
		}

		// Sort by priority: exact match > regex > wildcard.
		usort( $rules['rewrite'] ?? array(), array( __CLASS__, 'sort_rules' ) );
		usort( $rules['query'] ?? array(), array( __CLASS__, 'sort_rules' ) );

		return $rules;
	}

	/**
	 * Normalizes a rewrite rule array.
	 *
	 * @param array  $rule    Raw rule.
	 * @param array  $mapping Parent mapping.
	 * @return array|null Normalized rule or null if invalid.
	 */
	private static function normalize_rewrite_rule( $rule, $mapping ) {
		if ( ! isset( $rule['source'] ) ) {
			return null;
		}

		$normalized = array(
			'mapping_id'    => $mapping['id'],
			'source'        => $rule['source'],
			'target'        => $rule['target'] ?? '',
			'regex'         => isset( $rule['regex'] ) ? (bool) $rule['regex'] : false,
			'redirect'      => isset( $rule['redirect'] ) ? (bool) $rule['redirect'] : false,
			'redirect_code' => isset( $rule['redirect_code'] ) ? absint( $rule['redirect_code'] ) : 301,
			'priority'      => isset( $rule['priority'] ) ? absint( $rule['priority'] ) : 10,
		);

		// Compile regex if needed.
		if ( $normalized['regex'] ) {
			$normalized['compiled'] = @preg_match( $normalized['source'], '' ) !== false;
			if ( ! $normalized['compiled'] ) {
				return null;
			}
		}

		return $normalized;
	}

	/**
	 * Normalizes a query string rule array.
	 *
	 * @param array  $rule    Raw rule.
	 * @param array  $mapping Parent mapping.
	 * @return array|null Normalized rule or null if invalid.
	 */
	private static function normalize_query_rule( $rule, $mapping ) {
		if ( ! isset( $rule['action'] ) || ! isset( $rule['key'] ) ) {
			return null;
		}

		$action = strtolower( $rule['action'] );
		if ( ! in_array( $action, array( 'add', 'remove', 'replace', 'set' ), true ) ) {
			return null;
		}

		return array(
			'mapping_id' => $mapping['id'],
			'action'     => $action,
			'key'        => sanitize_key( $rule['key'] ),
			'value'      => isset( $rule['value'] ) ? $rule['value'] : '',
			'priority'   => isset( $rule['priority'] ) ? absint( $rule['priority'] ) : 10,
		);
	}

	/**
	 * Sorts rules by priority (highest first) then specificity.
	 *
	 * @param array $a Rule A.
	 * @param array $b Rule B.
	 * @return int
	 */
	private static function sort_rules( $a, $b ) {
		if ( $a['priority'] !== $b['priority'] ) {
			return $b['priority'] <=> $a['priority'];
		}
		// Exact match (no regex, no wildcards) > regex > wildcard.
		$a_exact = empty( $a['regex'] ) && false === strpos( $a['source'], '*' );
		$b_exact = empty( $b['regex'] ) && false === strpos( $b['source'], '*' );
		if ( $a_exact !== $b_exact ) {
			return $a_exact ? -1 : 1;
		}
		// Longer source = more specific.
		return strlen( $b['source'] ) <=> strlen( $a['source'] );
	}

	/**
	 * Primes the cache for a host.
	 *
	 * @param string $host  Normalized host.
	 * @param array  $rules Rules array.
	 * @return void
	 */
	private static function prime_cache( $host, $rules ) {
		$cache = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP );
		if ( false === $cache ) {
			$cache = array();
		}
		$cache[ $host ] = $rules;
		wp_cache_set( self::CACHE_KEY, $cache, self::CACHE_GROUP, HOUR_IN_SECONDS );
	}

	/**
	 * Clears rewrite cache for a host (or all hosts).
	 *
	 * @param string|null $host Host to clear, or null for all.
	 * @return void
	 */
	public static function clear_cache( $host = null ) {
		if ( $host ) {
			$cache = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP );
			if ( false !== $cache && isset( $cache[ $host ] ) ) {
				unset( $cache[ $host ] );
				wp_cache_set( self::CACHE_KEY, $cache, self::CACHE_GROUP, HOUR_IN_SECONDS );
			}
		} else {
			wp_cache_delete( self::CACHE_KEY, self::CACHE_GROUP );
		}
	}

	/**
	 * Matches a request path against rewrite rules.
	 *
	 * @param string $path Request path (normalized, no leading slash).
	 * @param string $host Request host.
	 * @return array|null Matched rule with 'target' and 'redirect' keys, or null.
	 */
	public static function match_path( $path, $host ) {
		$rules = self::get_rules_for_host( $host );
		if ( empty( $rules['rewrite'] ) ) {
			return null;
		}

		foreach ( $rules['rewrite'] as $rule ) {
			if ( self::path_matches( $path, $rule ) ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Checks if a path matches a rewrite rule.
	 *
	 * @param string $path  Request path.
	 * @param array  $rule  Normalized rule.
	 * @return bool
	 */
	private static function path_matches( $path, $rule ) {
		if ( $rule['regex'] ) {
			return (bool) @preg_match( $rule['source'], $path );
		}

		// Wildcard support: * matches any segment.
		$pattern = preg_quote( $rule['source'], '#' );
		$pattern = str_replace( '\*', '([^/]+)', $pattern );
		$pattern = '#^' . $pattern . '$#';

		return (bool) @preg_match( $pattern, $path );
	}

	/**
	 * Applies query string rules to the current request.
	 *
	 * Modifies the global `$_GET` and `$_SERVER['QUERY_STRING']`.
	 *
	 * @param string $host Request host.
	 * @return void
	 */
	public static function apply_query_rules( $host ) {
		$rules = self::get_rules_for_host( $host );
		if ( empty( $rules['query'] ) ) {
			return;
		}

		$query = $_GET;

		foreach ( $rules['query'] as $rule ) {
			switch ( $rule['action'] ) {
				case 'add':
					if ( ! isset( $query[ $rule['key'] ] ) ) {
						$query[ $rule['key'] ] = $rule['value'];
					}
					break;
				case 'remove':
					unset( $query[ $rule['key'] ] );
					break;
				case 'replace':
					if ( isset( $query[ $rule['key'] ] ) ) {
						$query[ $rule['key'] ] = $rule['value'];
					}
					break;
				case 'set':
					$query[ $rule['key'] ] = $rule['value'];
					break;
			}
		}

		$_GET                    = $query;
		$_SERVER['QUERY_STRING'] = http_build_query( $query );
	}

	/**
	 * Pre-loads rewrite rules for all active hosts.
	 * Called when mappings are saved/updated to warm the cache.
	 *
	 * @return void
	 */
	public static function preload_all_rules(): void {
		if ( ! class_exists( 'DMS_Single_Site_Mappings' ) ) {
			return;
		}

		$mappings = DMS_Single_Site_Mappings::list_mappings( array( 'status' => 'active' ) );
		if ( is_wp_error( $mappings ) || empty( $mappings ) ) {
			return;
		}

		$cache = array();
		foreach ( $mappings as $mapping ) {
			$rules = array();

			// Path rewrite rules.
			if ( ! empty( $mapping['rewrite_rules'] ) && is_array( $mapping['rewrite_rules'] ) ) {
				foreach ( $mapping['rewrite_rules'] as $rule ) {
					$rule = self::normalize_rewrite_rule( $rule, $mapping );
					if ( $rule ) {
						$rules['rewrite'][] = $rule;
					}
				}
			}

			// Query string rules.
			if ( ! empty( $mapping['query_string_rules'] ) && is_array( $mapping['query_string_rules'] ) ) {
				foreach ( $mapping['query_string_rules'] as $rule ) {
					$rule = self::normalize_query_rule( $rule, $mapping );
					if ( $rule ) {
						$rules['query'][] = $rule;
					}
				}
			}

			// Sort by priority.
			usort( $rules['rewrite'] ?? array(), array( __CLASS__, 'sort_rules' ) );
			usort( $rules['query'] ?? array(), array( __CLASS__, 'sort_rules' ) );

			$cache[ $mapping['host'] ] = $rules;
		}

		wp_cache_set( self::CACHE_KEY, $cache, self::CACHE_GROUP, HOUR_IN_SECONDS );
	}
}
