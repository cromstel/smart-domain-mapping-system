<?php
/**
 * REST contract tests for the `dms/v1` namespace.
 *
 * Mirrors the existing test style from test-sample.php and test-db-tables.php:
 * WP_UnitTestCase, cookies handled by test harness, assertions on HTTP status,
 * WP_Error codes + messages, response body shape. Uses the plugin's own
 * helpers (rest_url, rest_ensure_response).
 *
 * These require a WordPress test environment (WP_TESTS_DIR). The test
 * harness runs wp_remote_get/post with cookie/header setup that mirrors
 * how the existing DMS_REST_API tests operate.
 */

use WP_UnitTestCase;

final class DMS_Test_REST_Contract extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		if ( DMS_Mode::is_multisite() ) {
			$this->markTestSkipped( 'The dms/v1 contract is single-site only; in multisite its routes delegate to the multisite engine.' );
		}

		// The endpoints are capability-gated; in single-site the DMS
		// capabilities map to manage_options, i.e. an administrator.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Creates one single-site mapping per test to reuse in POST/PATCH/GET/DELETE.
	 *
	 * @return int Mapping ID.
	 */
	protected function make_mapping() {
		return DMS_Single_Site_Mappings::add_mapping( 'contract.io', '', array( 'active' => true ) );
	}

	// ----- GET /dms/v1/mappings -----

	public function test_get_mappings_lists_our_mapping() {
		$id = $this->make_mapping();
		$response = rest_do_remote_request( 'GET', rest_url( 'dms/v1/mappings' ), array( 'id' => $id ) );
		$this->assertEquals( 200, $response['response']['code'] );
		$data = json_decode( $response['body'], true );
		$this->assertIsArray( $data, 'response body should be a bare array (no envelope).' );
		$this->assertContains( $id, array_column( $data, 'id' ), 'our mapping id should appear in the list.' );
	}

	// ----- POST /dms/v1/mappings -----

	public function test_post_mappings_creates_mapping() {
		$url = rest_url( 'dms/v1/mappings' );
		$response = rest_do_remote_request( 'POST', $url, array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body' => json_encode( array( 'host' => 'newcontract.io', 'path' => '', 'active' => true ) ),
		) );
		$this->assertEquals( 201, $response['response']['code'], 'POST should return 201.' );
		$body = json_decode( $response['body'], true );
		$this->assertArrayHasKey( 'id', $body, 'response should include mapping id.' );
		$this->assertEquals( 'newcontract.io', $body['host'], 'host should match.' );
	}

	// ----- GET /dms/v1/mappings/{id} -----

	public function test_get_mapping_by_id() {
		$id = $this->make_mapping();
		$url = rest_url( 'dms/v1/mappings/' . $id );
		$response = rest_do_remote_request( 'GET', $url );
		$this->assertEquals( 200, $response['response']['code'] );
		$body = json_decode( $response['body'], true );
		$this->assertEquals( $id, $body['id'], 'mapping ID in body should match URL.' );
	}

	// ----- PATCH /dms/v1/mappings/{id} -----

	public function test_patch_updates_host() {
		$id = $this->make_mapping();
		$url = rest_url( 'dms/v1/mappings/' . $id );
		$response = rest_do_remote_request( 'PATCH', $url, array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body' => json_encode( array( 'host' => 'updated.io' ) ),
		) );
		$this->assertEquals( 200, $response['response']['code'] );
		$body = json_decode( $response['body'], true );
		$this->assertEquals( 'updated.io', $body['host'], 'host should be updated.' );
	}

	// ----- DELETE /dms/v1/mappings/{id} -----

	public function test_delete_mapping() {
		$id = $this->make_mapping();
		$url = rest_url( 'dms/v1/mappings/' . $id );
		$response = rest_do_remote_request( 'DELETE', $url );
		$this->assertEquals( 200, $response['response']['code'] );
		$body = json_decode( $response['body'], true );
		$this->assertTrue( $body['removed'], 'removed flag should be true.' );
		// Verify it's gone.
		$get = rest_do_remote_request( 'GET', rest_url( 'dms/v1/mappings/' . $id ) );
		$this->assertEquals( 404, $get['response']['code'], 'deleted mapping should return 404 on GET.' );
	}

	// ----- POST /dms/v1/mappings/{id}/values -----

	public function test_post_mapping_values() {
		$id = $this->make_mapping();
		// First create a post (factory) then map it.
		// We rely on the fact that tests have a blog with some content.
		$post_id = isset( $this->posts[0]->ID ) ? $this->posts[0]->ID : 1;
		$url = rest_url( 'dms/v1/mappings/' . $id . '/values' );
		$response = rest_do_remote_request( 'POST', $url, array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body' => json_encode( array( 'object_type' => 'post', 'object_id' => $post_id, 'primary' => true ) ),
		) );
		$this->assertEquals( 201, $response['response']['code'] );
		$body = json_decode( $response['body'], true );
		$this->assertArrayHasKey( 'id', $body );
		$this->assertEquals( 'post', $body['object_type'] );
	}

	// ----- PUT /dms/v1/mapping_values/{value_id} -----

	public function test_put_mapping_value_primary() {
		$id = $this->make_mapping();
		$post_id = isset( $this->posts[0]->ID ) ? $this->posts[0]->ID : 1;
		// Create value first.
		$val_url = rest_url( 'dms/v1/mappings/' . $id . '/values' );
		$create = rest_do_remote_request( 'POST', $val_url, array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body' => json_encode( array( 'object_type' => 'post', 'object_id' => $post_id ) ),
		) );
		$this->assertEquals( 201, $create['response']['code'] );
		$val_id = json_decode( $create['body'], true )['id'];

		// Now update primary.
		$update_url = rest_url( 'dms/v1/mapping_values/' . $val_id );
		$response = rest_do_remote_request( 'PUT', $update_url, array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body' => json_encode( array( 'primary' => true ) ),
		) );
		$this->assertEquals( 200, $response['response']['code'] );
		$body = json_decode( $response['body'], true );
		$this->assertTrue( $body['primary'], 'primary should be true after update.' );
	}

	// ----- DELETE /dms/v1/mapping_values/{value_id} -----

	public function test_delete_mapping_value() {
		$id = $this->make_mapping();
		$post_id = isset( $this->posts[0]->ID ) ? $this->posts[0]->ID : 1;
		$val_url = rest_url( 'dms/v1/mappings/' . $id . '/values' );
		$create = rest_do_remote_request( 'POST', $val_url, array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body' => json_encode( array( 'object_type' => 'post', 'object_id' => $post_id ) ),
		) );
		$this->assertEquals( 201, $create['response']['code'] );
		$val_id = json_decode( $create['body'], true )['id'];

		$delete_url = rest_url( 'dms/v1/mapping_values/' . $val_id );
		$response = rest_do_remote_request( 'DELETE', $delete_url );
		$this->assertEquals( 200, $response['response']['code'] );
		$body = json_decode( $response['body'], true );
		$this->assertTrue( $body['removed'], 'removed flag should be true.' );
	}

	// ----- POST /dms/v1/mappings/batch -----

	public function test_batch_create_within_limit() {
		// Fewer than 25 items, should succeed.
		$items = array(
			array( 'op' => 'create', 'host' => 'batch1.io' ),
			array( 'op' => 'create', 'host' => 'batch2.io' ),
		);
		$url = rest_url( 'dms/v1/mappings/batch' );
		$response = rest_do_remote_request( 'POST', $url, array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body' => json_encode( array( 'items' => $items ) ),
		) );
		$this->assertEquals( 200, $response['response']['code'] );
		$body = json_decode( $response['body'], true );
		$this->assertIsArray( $body, 'batch response should be a bare array.' );
		$this->assertGreaterThanOrEqual( 2, count( $body ), 'should have at least 2 results.' );
	}

	public function test_batch_exceeds_limit_returns_400() {
		$items = array_fill( 0, 26, array( 'op' => 'create', 'host' => 'badax.io' ) );
		$url = rest_url( 'dms/v1/mappings/batch' );
		$response = rest_do_remote_request( 'POST', $url, array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body' => json_encode( array( 'items' => $items ) ),
		) );
		$this->assertEquals( 400, $response['response']['code'], 'batch > 25 should return 400.' );
	}
}