<?php
/**
 * Domain ownership verification (DNS TXT / HTTP-01 style challenges).
 *
 * Only hashes of issued tokens are stored (`token_hash` = HMAC-SHA256 with
 * AUTH_KEY), so a database read never reveals a usable token.
 *
 * Completion is an *external* proof: the plugin fetches the challenge URL
 * (HTTP) or reads the TXT record (DNS) and compares what it found against
 * the stored hash. Merely knowing the token proves nothing — the record
 * must be published on the domain.
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_DNS_Verification {
	/**
	 * Challenge lifetime in seconds.
	 */
	const CHALLENGE_TTL = WEEK_IN_SECONDS;

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function generate_token( int $length = 32 ): string {
		return wp_generate_password( $length, false );
	}

	/**
	 * Creates a verification challenge row and returns the instructions.
	 *
	 * @param int    $blog_id Target blog ID.
	 * @param string $domain  Normalized domain under verification.
	 * @param string $method  dns|http.
	 * @return array|WP_Error {domain, token, method, challenge_path} on success.
	 */
	public function generate_challenge( int $blog_id, string $domain, string $method = 'dns' ) {
		global $wpdb;

		$method = in_array( $method, array( 'dns', 'http' ), true ) ? $method : 'dns';
		$table  = $wpdb->base_prefix . DMS_TABLE_VERIFICATIONS;
		$token  = $this->generate_token();

		$inserted = $wpdb->insert(
			$table,
			array(
				'blog_id'    => absint( $blog_id ),
				'domain'     => sanitize_text_field( $domain ),
				'method'     => $method,
				'token_hash' => hash_hmac( 'sha256', $token, AUTH_KEY ),
				'status'     => 'pending',
				'created_at' => current_time( 'mysql' ),
				'expires_at' => wp_date( 'Y-m-d H:i:s', time() + self::CHALLENGE_TTL ),
				'attempts'   => 0,
				'last_error' => null,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error(
				'dm_verification_failed',
				'Could not create verification challenge: ' . sanitize_text_field( $wpdb->last_error ),
				array( 'status' => 500 )
			);
		}

		if ( 'http' === $method ) {
			$challenge_path = '/.well-known/acme-challenge/' . $token;
		} else {
			$challenge_path = '_dm-verification.' . $domain;
		}

		DMS_Logging::get_instance()->log(
			$blog_id,
			$domain,
			get_current_user_id(),
			'verification.requested',
			wp_json_encode( array( 'method' => $method ) )
		);

		return array(
			'domain'         => $domain,
			'token'          => $token,
			'method'         => $method,
			'challenge_path' => $challenge_path,
		);
	}

	/**
	 * Completes a challenge by proving the token is published on the domain.
	 *
	 * On failure the row records the attempt (`attempts`, `last_error`,
	 * status = failed). Expired challenges must be regenerated.
	 *
	 * @param int    $blog_id Target blog ID.
	 * @param string $domain  Normalized domain.
	 * @param string $token   Plaintext token retrieved from the caller/user.
	 * @return array|WP_Error {domain, status: verified} on success.
	 */
	public function verify_challenge( int $blog_id, string $domain, string $token ): array|WP_Error {
		global $wpdb;

		$token = sanitize_text_field( $token );
		if ( '' === $token ) {
			return new WP_Error( 'dm_invalid_token', 'Verification token is required.', array( 'status' => 400 ) );
		}

		$table = $wpdb->base_prefix . DMS_TABLE_VERIFICATIONS;
		$hash  = hash_hmac( 'sha256', $token, AUTH_KEY );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE blog_id = %d AND domain = %s AND token_hash = %s AND status IN ( 'pending', 'failed' ) ORDER BY id DESC LIMIT 1",
				absint( $blog_id ),
				sanitize_text_field( $domain ),
				$hash
			)
		);

		if ( ! $row ) {
			return new WP_Error( 'dm_verification_not_found', 'No pending challenge matches this token.', array( 'status' => 404 ) );
		}
		if ( ! empty( $row->expires_at ) && strtotime( $row->expires_at ) < time() ) {
			return new WP_Error( 'dm_verification_expired', 'Challenge has expired. Generate a new one.', array( 'status' => 400 ) );
		}

		if ( 'http' === $row->method ) {
			$failure = $this->check_http( $domain, $token, $row->token_hash );
		} else {
			$failure = $this->check_dns( $domain, $token, $row->token_hash );
		}

		if ( null !== $failure ) {
			$wpdb->update(
				$table,
				array(
					'status'     => 'failed',
					'attempts'   => (int) $row->attempts + 1,
					'last_error' => sanitize_text_field( $failure ),
				),
				array( 'id' => (int) $row->id ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);
			DMS_Logging::get_instance()->log(
				$blog_id,
				$domain,
				get_current_user_id(),
				'verification.failed',
				wp_json_encode( array( 'error' => $failure ) )
			);
			return new WP_Error( 'dm_verification_failed', $failure, array( 'status' => 400 ) );
		}

		$wpdb->update(
			$table,
			array(
				'status'      => 'verified',
				'verified_at' => current_time( 'mysql' ),
				'attempts'    => (int) $row->attempts + 1,
				'last_error'  => null,
			),
			array( 'id' => (int) $row->id ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		DMS_Logging::get_instance()->log( $blog_id, $domain, get_current_user_id(), 'verification.completed', '' );

		return array(
			'domain' => $domain,
			'status' => 'verified',
		);
	}

	/**
	 * Fetches the challenge file over HTTP and compares it to the token.
	 *
	 * @param string $domain      Domain being verified.
	 * @param string $token       Expected plaintext token.
	 * @param string $stored_hash Stored HMAC of the token.
	 * @return string|null Failure reason or null on success.
	 */
	private function check_http( string $domain, string $token, string $stored_hash ): ?string {
		$url      = 'http://' . $domain . '/.well-known/acme-challenge/' . rawurlencode( $token );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 3,
				'sslverify'   => false,
			)
		);
		if ( is_wp_error( $response ) ) {
			return 'Challenge request failed: ' . $response->get_error_message();
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return 'Challenge URL returned HTTP ' . $code . '.';
		}
		$body = trim( wp_remote_retrieve_body( $response ) );
		if ( ! hash_equals( $stored_hash, hash_hmac( 'sha256', $body, AUTH_KEY ) ) ) {
			return 'Challenge body does not match the expected token.';
		}
		return null;
	}

	/**
	 * Reads the TXT record at _dm-verification.<domain> and compares it.
	 *
	 * @param string $domain      Domain being verified.
	 * @param string $token       Expected plaintext token.
	 * @param string $stored_hash Stored HMAC of the token.
	 * @return string|null Failure reason or null on success.
	 */
	private function check_dns( string $domain, string $token, string $stored_hash ): ?string {
		$record = '_dm-verification.' . $domain;
		if ( ! function_exists( 'dns_get_record' ) ) {
			return 'DNS lookup functions are not available on this server.';
		}
		// dns_get_record() raises E_WARNING for NXDOMAIN/NODATA; a missing record
		// is an expected outcome of verification, so suppression is intentional.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$records = @dns_get_record( $record, DNS_TXT );
		if ( empty( $records ) ) {
			return 'No TXT record found at ' . $record . '.';
		}
		foreach ( $records as $record_row ) {
			$value = isset( $record_row['txt'] ) ? trim( (string) $record_row['txt'] ) : '';
			if ( '' !== $value && hash_equals( $stored_hash, hash_hmac( 'sha256', $value, AUTH_KEY ) ) ) {
				return null;
			}
		}
		return 'TXT record at ' . $record . ' does not match the expected token.';
	}
}
