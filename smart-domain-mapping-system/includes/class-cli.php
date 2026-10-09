<?php
/**
 * WP-CLI commands (`wp dm ...`) implementing the spec contract:
 *
 *   wp dm mapping add <domain> --site=<id> [--primary] [--porcelain]
 *   wp dm mapping list [--site=<id>] [--format=table|json|csv]
 *   wp dm mapping enable <id>
 *   wp dm mapping disable <id>
 *   wp dm mapping remove <id>
 *   wp dm mapping verify <id> [--method=dns|http] [--token=<t>] [--force]
 *   wp dm mapping set-primary <id>
 *   wp dm cert issue <domain> [--site=<id>]
 *   wp dm cert renew <domain>
 *   wp dm audit tail [--site=<id>]
 *   wp dm migrate --source=<legacy|mercator>
 *
 * NOTE: the command class MUST be declared before WP_CLI::add_command()
 * — registration resolves the class name immediately.
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	class DMS_CLI_Command extends WP_CLI_Command {

		/**
		 * WP-CLI frequently runs without a user context (cron-like shells);
		 * shell access is treated as trusted in that case. When a user IS
		 * loaded (e.g. --user=), network admin rights are enforced.
		 */
		private function check_permission() {
			if ( ! get_current_user_id() ) {
				return;
			}
			if ( ! current_user_can( 'manage_network' ) && ! is_super_admin() ) {
				WP_CLI::error( 'Permission denied. You must be a network administrator.' );
			}
		}

		/**
		 * Manages domain mappings.
		 *
		 * ## OPTIONS
		 *
		 * <subcommand>
		 * : One of add, list, enable, disable, remove, verify, set-primary.
		 *
		 * [<domain>]
		 * : Domain (required for add).
		 *
		 * [--site=<id>]
		 * : Site (blog) ID. Required for add.
		 *
		 * [--id=<id>]
		 * : Mapping ID (alternative to positional argument for subcommands
		 * that operate on an existing mapping).
		 *
		 * [--primary]
		 * : Make the mapping primary on add.
		 *
		 * [--porcelain]
		 * : Output only the new mapping ID (add).
		 *
		 * [--format=<format>]
		 * : Output format for list: table, json, csv. Default: table.
		 *
		 * [--method=<method>]
		 * : Verification method for verify: dns or http. Default: dns.
		 *
		 * [--token=<token>]
		 * : Complete verification with a published token instead of
		 * generating a challenge.
		 *
		 * [--force]
		 * : Regenerate the challenge even if one is pending (verify).
		 *
		 * ## EXAMPLES
		 *
		 *     wp dm mapping add example.org --site=3 --porcelain
		 *     wp dm mapping list --format=json
		 *     wp dm mapping verify 7 --method=dns
		 *     wp dm mapping set-primary 7
		 *
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 */
		public function mapping( $args, $assoc_args ) {
			$this->check_permission();

			$subcommand = isset( $args[0] ) ? sanitize_key( $args[0] ) : 'list';

			switch ( $subcommand ) {
				case 'add':
					$this->cmd_add( $args, $assoc_args );
					break;
				case 'list':
					$this->cmd_list( $assoc_args );
					break;
				case 'enable':
					$this->cmd_set_active( $args, $assoc_args, true );
					break;
				case 'disable':
					$this->cmd_set_active( $args, $assoc_args, false );
					break;
				case 'remove':
					$this->cmd_delete( $args, $assoc_args );
					break;
				case 'verify':
					$this->cmd_verify( $args, $assoc_args );
					break;
				case 'set-primary':
					$this->cmd_set_primary( $args, $assoc_args );
					break;
				default:
					WP_CLI::error( sprintf( 'Unknown mapping subcommand "%s".', $subcommand ) );
			}
		}

		private function cmd_add( $args, $assoc_args ) {
			$domain = isset( $args[1] ) ? $args[1] : '';
			if ( '' === $domain ) {
				WP_CLI::error( 'Domain required. Usage: wp dm mapping add <domain> --site=<id>' );
			}
			$site_id = isset( $assoc_args['site'] ) ? absint( $assoc_args['site'] ) : 0;
			if ( ! $site_id ) {
				WP_CLI::error( '--site=<id> required.' );
			}
			$result = DMS_Mapping_Engine::add_mapping(
				$site_id,
				$domain,
				! empty( $assoc_args['primary'] )
			);
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
			if ( isset( $assoc_args['porcelain'] ) ) {
				WP_CLI::line( (string) $result );
				return;
			}
			WP_CLI::success( sprintf( 'Added mapping #%d: %s (site %d).', $result, $domain, $site_id ) );
		}

		private function cmd_list( $assoc_args ) {
			$where = array();
			if ( isset( $assoc_args['site'] ) ) {
				$where['blog_id'] = absint( $assoc_args['site'] );
			}
			$mappings = DMS_Mapping_Engine::list_mappings( $where );
			if ( is_wp_error( $mappings ) ) {
				WP_CLI::error( $mappings->get_error_message() );
			}
			$format = isset( $assoc_args['format'] ) ? sanitize_key( $assoc_args['format'] ) : 'table';
			if ( ! in_array( $format, array( 'table', 'json', 'csv' ), true ) ) {
				WP_CLI::error( 'Invalid --format. Use table, json or csv.' );
			}
			$fields = array( 'id', 'domain', 'blog_id', 'active', 'verified', 'primary' );
			foreach ( $mappings as &$mapping ) {
				foreach ( array( 'active', 'verified', 'primary' ) as $flag ) {
					$mapping[ $flag ] = $mapping[ $flag ] ? '1' : '0';
				}
			}
			unset( $mapping );
			WP_CLI\Utils\format_items( $format, $mappings, $fields );
		}

		private function cmd_set_active( $args, $assoc_args, $active ) {
			$mapping_id = $this->resolve_id( $args, $assoc_args );
			$result     = $active
				? DMS_Mapping_Engine::enable_mapping( $mapping_id )
				: DMS_Mapping_Engine::disable_mapping( $mapping_id );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
			WP_CLI::success(
				sprintf(
					'%s mapping #%d (%s).',
					$active ? 'Enabled' : 'Disabled',
					$mapping_id,
					$result['domain']
				)
			);
		}

		private function cmd_delete( $args, $assoc_args ) {
			$mapping_id = $this->resolve_id( $args, $assoc_args );
			$result     = DMS_Mapping_Engine::remove_mapping( $mapping_id );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
			WP_CLI::success( sprintf( 'Removed mapping #%d.', $mapping_id ) );
		}

		private function cmd_verify( $args, $assoc_args ) {
			$mapping_id = $this->resolve_id( $args, $assoc_args );
			$method     = isset( $assoc_args['method'] ) ? sanitize_key( $assoc_args['method'] ) : 'dns';

			if ( isset( $assoc_args['token'] ) ) {
				$result = DMS_Mapping_Engine::verify_mapping( $mapping_id, (string) $assoc_args['token'] );
				if ( is_wp_error( $result ) ) {
					WP_CLI::error( $result->get_error_message() );
				}
				WP_CLI::success( sprintf( 'Domain %s verified.', $result['domain'] ) );
				return;
			}

			$result = DMS_Mapping_Engine::generate_verification( $mapping_id, $method );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
			if ( 'http' === $result['method'] ) {
				WP_CLI::line( 'Publish this file on the mapped domain:' );
				WP_CLI::line( '  URL:      http://' . $result['domain'] . $result['challenge_path'] );
				WP_CLI::line( '  Content:  ' . $result['token'] );
			} else {
				WP_CLI::line( 'Create a DNS TXT record:' );
				WP_CLI::line( '  Name:  ' . $result['challenge_path'] );
				WP_CLI::line( '  Value: ' . $result['token'] );
			}
			WP_CLI::line( '' );
			WP_CLI::line( sprintf( 'Then run: wp dm mapping verify %d --token=<the-token>', $mapping_id ) );
		}

		private function cmd_set_primary( $args, $assoc_args ) {
			$mapping_id = $this->resolve_id( $args, $assoc_args );
			$result     = DMS_Mapping_Engine::set_primary( $mapping_id );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
			WP_CLI::success( sprintf( '%s is now the primary domain for site %d.', $result['domain'], $result['blog_id'] ) );
		}

		/**
		 * Issues or renews TLS certificates through the configured provider.
		 *
		 * ## OPTIONS
		 *
		 * <subcommand>
		 * : One of issue, renew.
		 *
		 * <domain>
		 * : The certificate domain.
		 *
		 * [--site=<id>]
		 * : Site (blog) ID; defaults to the domain's mapped site.
		 *
		 * ## EXAMPLES
		 *
		 *     wp dm cert issue example.org --site=3
		 *     wp dm cert renew example.org
		 *
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 */
		public function cert( $args, $assoc_args ) {
			$this->check_permission();

			$subcommand = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';
			$domain     = isset( $args[1] ) ? DMS_Mapping_Engine::normalize_domain( $args[1] ) : false;
			if ( ! in_array( $subcommand, array( 'issue', 'renew' ), true ) ) {
				WP_CLI::error( 'Usage: wp dm cert issue|renew <domain> [--site=<id>]' );
			}
			if ( false === $domain ) {
				WP_CLI::error( 'A valid domain is required.' );
			}

			$blog_id = isset( $assoc_args['site'] ) ? absint( $assoc_args['site'] ) : 0;
			if ( ! $blog_id ) {
				$mapping = DMS_Mapping_Engine::get_mapping_for_domain( $domain );
				if ( $mapping ) {
					$blog_id = (int) $mapping['blog_id'];
				}
			}
			if ( ! $blog_id ) {
				WP_CLI::error( 'No mapped site found for this domain; pass --site=<id>.' );
			}

			$provider = DMS_SSL_Providers::get();
			if ( 'issue' === $subcommand ) {
				$ok = $provider->issue_certificate( $blog_id, $domain );
			} else {
				$ok = $provider->renew_certificate( $blog_id, $domain );
			}
			if ( ! $ok ) {
				WP_CLI::error(
					'Certificate ' . $subcommand . ' failed. The active SSL provider does not perform issuance (configure ssl_provider in settings or register a provider via the dm_ssl_provider filter).'
				);
			}
			WP_CLI::success( sprintf( 'Certificate %s completed for %s (site %d).', $subcommand, $domain, $blog_id ) );
		}

		/**
		 * Streams the audit log.
		 *
		 * ## OPTIONS
		 *
		 * [<subcommand>]
		 * : Only `tail` is supported (reserved for future subcommands).
		 *
		 * [--site=<id>]
		 * : Only entries for this site.
		 *
		 * [--limit=<n>]
		 * : Number of entries to show. Default: 20.
		 *
		 * ## EXAMPLES
		 *
		 *     wp dm audit tail --site=3 --limit=50
		 *
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 */
		public function audit( $args, $assoc_args ) {
			$this->check_permission();
			global $wpdb;

			$table = $wpdb->base_prefix . DMS_TABLE_LOGS;
			$limit = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 20;
			$limit = $limit ? min( $limit, 500 ) : 20;

			if ( isset( $assoc_args['site'] ) ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM {$table} WHERE blog_id = %d ORDER BY id DESC LIMIT %d",
						absint( $assoc_args['site'] ),
						$limit
					)
				);
			} else {
				$rows = $wpdb->get_results(
					$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit )
				);
			}
			if ( $wpdb->last_error ) {
				WP_CLI::error( 'Audit query failed.' );
			}
			if ( empty( $rows ) ) {
				WP_CLI::line( 'No log entries found.' );
				return;
			}
			foreach ( $rows as $row ) {
				WP_CLI::line(
					sprintf(
						'%s | site %d | %s | %s | user %d | %s',
						$row->created_at,
						(int) $row->blog_id,
						$row->domain,
						$row->action,
						(int) $row->user_id,
						$row->context
					)
				);
			}
		}

		/**
		 * Runs data migrations.
		 *
		 * ## OPTIONS
		 *
		 * [--source=<source>]
		 * : Migration source: legacy or mercator. Default: legacy.
		 *
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 */
		public function migrate( $args, $assoc_args ) {
			$this->check_permission();
			$source = isset( $assoc_args['source'] ) ? sanitize_key( $assoc_args['source'] ) : 'legacy';

			if ( 'legacy' === $source ) {
				DMS_Migration::migrate_legacy_tables();
				WP_CLI::success( 'Legacy table migration finished (see dm_audit_log for migrate.mapping entries).' );
			} elseif ( 'mercator' === $source ) {
				DMS_Migration::migrate_mercator();
				WP_CLI::success( 'Mercator migration finished (see dm_audit_log for migrate.mercator entries).' );
			} else {
				WP_CLI::error( 'Invalid --source. Use legacy or mercator.' );
			}
		}

		/**
		 * Resolves a mapping ID from positional argument or --id.
		 *
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 * @return int
		 */
		private function resolve_id( $args, $assoc_args ) {
			if ( isset( $args[1] ) && is_numeric( $args[1] ) ) {
				return absint( $args[1] );
			}
			if ( isset( $assoc_args['id'] ) ) {
				return absint( $assoc_args['id'] );
			}
			WP_CLI::error( 'A mapping ID is required.' );
			return 0;
		}
	}

	WP_CLI::add_command( 'dm', 'DMS_CLI_Command' );
}
