<?php
/**
 * Per-domain favicon renderer.
 *
 * Outputs <link rel="icon"> tags for the active mapping's favicon (attachment_id).
 * Supports multiple sizes by generating appropriate HTML for the same image.
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DMS_Per_Domain_Favicon {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( ! DMS_Mode::is_single_site() ) {
			return;
		}
		add_action( 'wp_head', array( $this, 'output_favicon' ), 15 );
	}

	/**
	 * Outputs the favicon link tags for the current mapping.
	 *
	 * @return void
	 */
	public function output_favicon() {
		$mapping = $this->get_current_mapping();
		if ( ! $mapping || empty( $mapping['attachment_id'] ) ) {
			return;
		}

		$attachment_id = (int) $mapping['attachment_id'];
		$url           = wp_get_attachment_url( $attachment_id );
		if ( ! $url ) {
			return;
		}

		$mime = get_post_mime_type( $attachment_id );
		$type = $this->get_mime_type( $mime );

		// Output standard favicon link.
		echo '<link rel="icon" href="' . esc_url( $url ) . '" type="' . esc_attr( $type ) . "\" />\n";

		// If it's a PNG/ICO, also output apple-touch-icon for iOS.
		if ( in_array( $mime, array( 'image/png', 'image/x-icon', 'image/vnd.microsoft.icon' ), true ) ) {
			echo '<link rel="apple-touch-icon" href="' . esc_url( $url ) . "\" />\n";
		}
	}

	/**
	 * Gets the MIME type for the HTML type attribute.
	 *
	 * @param string $mime WP mime type.
	 * @return string HTML type attribute value.
	 */
	private function get_mime_type( $mime ) {
		$map = array(
			'image/png'                => 'image/png',
			'image/x-icon'             => 'image/x-icon',
			'image/vnd.microsoft.icon' => 'image/x-icon',
			'image/svg+xml'            => 'image/svg+xml',
			'image/webp'               => 'image/webp',
			'image/jpeg'               => 'image/jpeg',
			'image/gif'                => 'image/gif',
		);
		return isset( $map[ $mime ] ) ? $map[ $mime ] : 'image/png';
	}

	/**
	 * Gets the mapping for the current request host/path.
	 *
	 * @return array|null
	 */
	private function get_current_mapping() {
		if ( ! class_exists( 'DMS_Single_Site_Mappings' ) ) {
			return null;
		}

		$host = $this->get_request_host();
		$path = $this->get_request_path();

		return DMS_Single_Site_Mappings::get_mapping_for_host( $host, $path );
	}

	/**
	 * Gets the request host (normalized).
	 *
	 * @return string
	 */
	private function get_request_host() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';
		if ( false !== strpos( $host, ':' ) ) {
			$host = substr( $host, 0, strpos( $host, ':' ) );
		}
		return strtolower( $host );
	}

	/**
	 * Gets the request path (normalized, without leading/trailing slash).
	 *
	 * @return string
	 */
	private function get_request_path() {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
		if ( false !== strpos( $path, '?' ) ) {
			$path = substr( $path, 0, strpos( $path, '?' ) );
		}
		$path = trim( $path, '/' );
		return strtolower( $path );
	}
}
