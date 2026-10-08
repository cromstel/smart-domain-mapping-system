<?php
/**
 * Public canonical redirect helpers.
 *
 * Wired to `template_redirect` by DMS_Mapping_Engine::init(). Only sites
 * whose `wp_blogs.domain` is a plugin-managed mapping are touched; unmapped
 * sites keep core's default behaviour.
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redirects the current request to $domain using the configured status
 * (`default_redirect` setting: 301 or 302).
 *
 * @param string $domain Target domain.
 * @param int    $status Optional HTTP status override.
 * @return void
 */
function dm_redirect_to_mapped_domain( $domain, $status = 0 ) {
	$normalized = DMS_Mapping_Engine::normalize_domain( $domain );
	if ( false === $normalized ) {
		return;
	}

	$request_uri = '/';
	if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
		$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
	}

	if ( ! $status ) {
		$options = get_site_option( 'dm_options', array() );
		$status  = isset( $options['default_redirect'] ) && '302' === (string) $options['default_redirect'] ? 302 : 301;
	}

	$url = ( is_ssl() ? 'https' : 'http' ) . '://' . $normalized . $request_uri;

	// Mapped domains can be off-site, so wp_redirect (not wp_safe_redirect).
	wp_redirect( esc_url_raw( $url ), $status ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- intentional cross-host canonical redirect.
	exit;
}

/**
 * Canonicalizes front-end requests to the site's primary mapped domain.
 *
 * Handles e.g. the www-variant core resolves via get_site_by_path() and any
 * extra hostname pointing at the site. No-ops when the site has no mapping.
 */
function dm_canonical_redirect() {
	if ( 'GET' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' ) ) {
		return;
	}
	if ( ! is_multisite() || ! function_exists( 'get_site' ) ) {
		return;
	}

	$blog_id = get_current_blog_id();
	$site    = get_site( $blog_id );
	if ( ! $site ) {
		return;
	}

	// Only sites whose wp_blogs.domain is one of our mappings are managed.
	$mapped = DMS_Mapping_Engine::get_mapping_for_domain( $site->domain );
	if ( ! $mapped || (int) $mapped['blog_id'] !== (int) $blog_id ) {
		return;
	}

	$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) ) : '';
	$host = preg_replace( '#^[a-z][a-z0-9+.\\-]*://#', '', $host );
	if ( '' === $host || false === $host ) {
		return;
	}

	// Compare the raw host (www NOT stripped — that difference is exactly
	// what we redirect on).
	$current = strtolower( trim( (string) $site->domain ) );
	if ( $host === $current ) {
		return;
	}

	dm_redirect_to_mapped_domain( $current );
}
