<?php
/**
 * Structured audit logging with correlation IDs and log rotation.
 *
 * Phase 3A: Enhanced logging with JSON context, correlation IDs,
 * and automatic log rotation by size/age.
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_Logging {

	// Default log retention (days) - filterable.
	const DEFAULT_RETENTION_DAYS = 180;

	// Maximum log entries before rotation triggers.
	const MAX_LOG_ENTRIES = 10000;

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Schedule daily log rotation check.
		if ( ! wp_next_scheduled( 'dms_daily_log_rotation' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'dms_daily_log_rotation' );
		}
		add_action( 'dms_daily_log_rotation', array( $this, 'rotate_logs' ) );
	}

	/**
	 * Logs an audit event with structured context.
	 *
	 * @param int|null    $blog_id   Blog ID (null for single-site).
	 * @param string|null $domain    Domain associated with the event.
	 * @param int         $user_id   User ID performing the action.
	 * @param string      $action    Action key (e.g., 'dms.mapping.create').
	 * @param array|string $context  Context data (array will be JSON-encoded).
	 * @return bool True when the row was written. False means the audit trail
	 *              lost an event — callers should treat a false as a signal to
	 *              check $wpdb->last_error, never to retry blindly.
	 */
	public function log( ?int $blog_id, ?string $domain, int $user_id, string $action, $context = '' ): bool {
		global $wpdb;

		$correlation_id = $this->generate_correlation_id();

		$context_json = is_array( $context ) ? wp_json_encode( $context ) : sanitize_textarea_field( (string) $context );

		$result = $wpdb->insert(
			$wpdb->base_prefix . DMS_TABLE_LOGS,
			array(
				'blog_id'        => $blog_id ? absint( $blog_id ) : null,
				'domain'         => sanitize_text_field( $domain ?? '' ),
				'user_id'        => absint( $user_id ),
				'action'         => sanitize_text_field( $action ),
				'context'        => $context_json,
				'correlation_id' => $correlation_id,
				'created_at'     => current_time( 'mysql' ),
			),
			// One placeholder per column above. A short list here means the
			// whole INSERT silently fails in PHP 8 and the row is never written.
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Generates a unique correlation ID for request tracing.
	 *
	 * @return string Correlation ID (UUID v4 format).
	 */
	private function generate_correlation_id(): string {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0x0fff ) | 0x4000,
			wp_rand( 0x8000, 0xbfff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff )
		);
	}

	/**
	 * Rotates audit logs based on age and size limits.
	 * Called via daily cron hook.
	 *
	 * @return void
	 */
	public function rotate_logs(): void {
		global $wpdb;

		$retention_days = $this->get_retention_days();
		$max_entries    = apply_filters( 'dms_max_log_entries', self::MAX_LOG_ENTRIES );

		// Delete by age.
		if ( $retention_days > 0 ) {
			$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$retention_days} days" ) );
			$table  = $wpdb->base_prefix . DMS_TABLE_LOGS;
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE created_at < %s",
					$cutoff
				)
			);
		}

		// Delete by count (keep most recent). MySQL rejects
		// `DELETE ... WHERE id IN (SELECT id FROM <same table>)` with error 1093,
		// which is why this is a single-table DELETE with ORDER BY + LIMIT
		// instead of a self-referencing subquery.
		$table = $wpdb->base_prefix . DMS_TABLE_LOGS;
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $count > (int) $max_entries ) {
			$to_delete = $count - (int) $max_entries;
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} ORDER BY created_at ASC, id ASC LIMIT %d",
					$to_delete
				)
			);
		}

		// Log the rotation event itself.
		$this->log(
			null,
			null,
			get_current_user_id(),
			'dms.log.rotation',
			array(
				'retention_days' => $retention_days,
				'max_entries'    => $max_entries,
			)
		);
	}

	/**
	 * Gets the configured log retention period.
	 *
	 * @return int Retention in days (0 = unlimited).
	 */
	private function get_retention_days(): int {
		$retention = get_site_option( 'dm_audit_retention_days', self::DEFAULT_RETENTION_DAYS );
		return max( 0, min( 3650, (int) $retention ) );
	}

	/**
	 * Exports audit logs for a date range (for compliance/export).
	 *
	 * @param string $start_date Start date (Y-m-d).
	 * @param string $end_date   End date (Y-m-d).
	 * @param int    $limit      Max rows to return.
	 * @return array Log entries.
	 */
	public function export_logs( string $start_date, string $end_date, int $limit = 10000 ): array {
		global $wpdb;

		$table = $wpdb->base_prefix . DMS_TABLE_LOGS;
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE created_at >= %s AND created_at <= %s ORDER BY created_at DESC LIMIT %d",
				$start_date . ' 00:00:00',
				$end_date . ' 23:59:59',
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
