<?php
/**
 * Alias domain authentication - RETIRED, no handlers are registered.
 *
 * This class used to sync a WordPress auth cookie to "alias" domains on login
 * so a user stayed signed in across mapped domains. It never worked, and the
 * design could not be made to work, so the accept path has been removed rather
 * than repaired:
 *
 *  1. It is impossible by construction. set_alias_cookie() called setcookie()
 *     with the *alias* domain as the cookie Domain, from the response of the
 *     *current* domain. A browser rejects any Domain attribute that is not a
 *     suffix of the host serving the response, so no alias cookie was ever
 *     stored and the sync silently did nothing.
 *
 *  2. The writer and reader disagreed on the signed payload. The writer hashed
 *     `auth_cookie|expiration|domain`, while the reader recomputed
 *     `user_id|expiration|user_login|user_pass`. The comparison could never
 *     succeed.
 *
 *  3. Worse than dead: the reader treated the first cookie field as a user ID
 *     when the writer stored the whole auth cookie there, and then called
 *     wp_set_auth_cookie( $user_id, true ) - a *remembered* session minted from
 *     a plugin cookie. Repairing defect 2 into a self-consistent hash (for
 *     example by dropping user_pass from the payload) would turn this into a
 *     session-forgery primitive, so the accept path is deleted rather than
 *     patched.
 *
 * The supported way to stay signed in across related domains is
 * DMS_Subdomain_Auth, which shares one cookie across a parent domain
 * (e.g. .example.com). That relies on a mechanism browsers actually honour.
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_Alias_Auth {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers nothing.
	 *
	 * The wp_login / wp_logout / init hooks this used to add are gone, so no
	 * alias cookie is ever written and no request is ever authenticated from
	 * one. Sites that still flip the opt-in filter are told why, instead of
	 * being left to wonder why nothing happened.
	 *
	 * @return void
	 */
	private function __construct() {
		if ( apply_filters( 'dms_alias_auth_enabled', false ) ) {
			_doing_it_wrong(
				'DMS_Alias_Auth',
				esc_html__(
					'DMS alias auth has been retired and no longer registers any handlers. To stay signed in across related domains, use a shared parent domain with DMS_Subdomain_Auth.',
					'domain-mapping-system'
				),
				'1.2.0'
			);
		}
	}
}
