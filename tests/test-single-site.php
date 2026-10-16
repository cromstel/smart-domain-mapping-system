<?php
/**
 * PHPUnit tests for Phase 1 of the feature-integration plan:
 * dual-mode architecture, single-site mapping store, dm_mappings and
 * dm_mapping_values tables, and the dms/v1 REST contract.
 *
 * These tests follow the exact patterns from the existing test suite
 * (DMS_Test_* extends WP_UnitTestCase, using WP test factory, etc.)
 * and are designed to run under a full WordPress test environment
 * (WP_TESTS_DIR set to wordpress-develop/tests/phpunit).
 *
 * NOTES FOR WHEN THESE ARE RUN:
 * - Requires WP_TESTS_DIR and WordPress develop test suite.
 * - Assumes the plugin has been activated at least once so dm_db_version
 *   is recorded in site options.
 * - Do NOT run against a live production install without a test harness.
 *
 * @package Domain_Mapping_System
 */

use WP_UnitTestCase;

/**
 * Test suite for the dual-mode detection and the new single-site mapping
 * tables (dm_mappings / dm_mapping_values) plus their REST contract.
 *
 * Tests are split across mode-specific groups:
 *   - DMS_Test_Mode: detection + detection change when WP install type changes.
 *   - DMS_Test_SiteMapping: CRUD over dm_mappings (single-site only; ignores
 *     multisite data in wp_sitemeta).
 *   - DMS_Test_MappingValues: CRUD over dm_mapping_values, including the
 *     allow-list validation.
 *   - DMS_Test_REST_Dms: `dms/v1` contract endpoints, permissions, batch.
 */
final class DMS_Test_Mode extends WP_UnitTestCase {

	public function test_mode_is_multisite_when_is_multisite() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires a Multisite install; CI runs this suite in both modes.' );
		}
		$this->assertTrue( DMS_Mode::is_multisite(), 'DMS::is_multisite() should return true in a Multisite install.' );
	}

	public function test_mode_is_single_when_not_multisite() {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Requires a single-site install; CI runs this suite in both modes.' );
		}
		$this->assertFalse( DMS_Mode::is_multisite(), 'DMS::is_multisite() should return false in a single-site install.' );
	}

	public function test_mode_constants_exist() {
		$this->assertTrue( defined( 'DMS_Mode::MODE_MULTISITE' ), 'DMS_Mode::MODE_MULTISITE constant missing.' );
		$this->assertTrue( defined( 'DMS_Mode::MODE_SINGLE' ), 'DMS_Mode::MODE_SINGLE constant missing.' );
	}
}

final class DMS_Test_Single_Site_Mappings extends WP_UnitTestCase {

	public function test_add_mapping_in_single_site_mode() {
		$host = 'example.com';
		$mapping_id = DMS_Single_Site_Mappings::add_mapping( $host, '', array( 'active' => true ) );
		$this->assertIsInt( $mapping_id, 'add_mapping should return a mapping ID in single-site mode.' );
		$mapping = DMS_Single_Site_Mappings::get_mapping( $mapping_id );
		$this->assertIsArray( $mapping, 'get_mapping should return an array in single-site mode.' );
		$this->assertEquals( $host, $mapping['host'], 'host should match.' );
		$this->assertTrue( $mapping['active'], 'mapping should be active.' );
	}

	public function test_add_duplicate_host_path() {
		$host = 'example.org';
		DMS_Single_Site_Mappings::add_mapping( $host, '', array( 'active' => true ) );
		$result = DMS_Single_Site_Mappings::add_mapping( $host, '', array( 'active' => true ) );
		$this->assertIs_wp_error( $result, 'adding duplicate host/path should return a WP_Error.' );
	}

	public function test_get_mapping_for_host() {
		$host = 'testsite.net';
		$id   = DMS_Single_Site_Mappings::add_mapping( $host, 'docs', array( 'active' => true ) );
		$found = DMS_Single_Site_Mappings::get_mapping_for_host( $host, 'docs' );
		$this->assertNotNull( $found, 'get_mapping_for_host should find the mapping.' );
		$this->assertEquals( $id, $found['id'], 'returned ID should match added ID.' );
	}

	public function test_remove_mapping() {
		$host = 'removeme.io';
		$id   = DMS_Single_Site_Mappings::add_mapping( $host, '', array( 'active' => true ) );
		$result = DMS_Single_Site_Mappings::remove_mapping( $id );
		$this->assertTrue( $result, 'remove_mapping should return true on success.' );
		$still = DMS_Single_Site_Mappings::get_mapping( $id );
		$this->assertNull( $still, 'mapping should no longer exist after removal.' );
	}

	public function test_normalize_host_path() {
		$h = DMS_Single_Site_Mappings::normalize_host( 'Example.COM' );
		$this->assertEquals( 'example.com', $h, 'host normalization should lowercase.' );

		$p = DMS_Single_Site_Mappings::normalize_path( 'My-Path/' );
		$this->assertEquals( 'my-path', $p, 'normalize_path trims edge slashes and lowercases.' );

		$p2 = DMS_Single_Site_Mappings::normalize_path( 'solutions' );
		$this->assertEquals( 'solutions', $p2, 'simple segment should normalize.' );
	}
}

final class DMS_Test_Mapping_Values extends WP_UnitTestCase {

	public function test_set_value_single_site_mode() {
		// Prerequisite: a mapping must exist.
		$mapping_id = DMS_Single_Site_Mappings::add_mapping( 'valuetest.org', '', array( 'active' => true ) );
		$this->assertIsInt( $mapping_id, 'add_mapping prerequisite.' );

		$val_id = DMS_Mapping_Values::set_value( $mapping_id, 'post', 42, true );
		$this->assertIsInt( $val_id, 'set_value should return a value ID.' );

		$val = DMS_Mapping_Values::get_value( $val_id );
		$this->assertEquals( 'post', $val['object_type'], 'object_type should be post.' );
		$this->assertEquals( 42, $val['object_id'], 'object_id should be 42.' );
		$this->assertTrue( $val['primary'], 'primary should be true.' );
	}

	public function test_set_unsupported_object_type() {
		$mapping_id = DMS_Single_Site_Mappings::add_mapping( 'invalid-type.org', '', array( 'active' => true ) );
		$this->assertIsInt( $mapping_id, 'add_mapping prerequisite.' );

		$result = DMS_Mapping_Values::set_value( $mapping_id, 'invalid_cpt', 99 );
		$this->assertIs_wp_error( $result, 'unsupported object type should return WP_Error.' );
	}

	public function test_remove_value() {
		$mapping_id = DMS_Single_Site_Mappings::add_mapping( 'valueremove.net', '', array( 'active' => true ) );
		$val_id     = DMS_Mapping_Values::set_value( $mapping_id, 'page', 1, false );

		$result = DMS_Mapping_Values::remove_value( $val_id );
		$this->assertTrue( $result, 'remove_value should return true.' );

		$still = DMS_Mapping_Values::get_value( $val_id );
		$this->assertNull( $still, 'value should not exist after removal.' );
	}

	public function test_list_values_for_mapping() {
		$mapping_id = DMS_Single_Site_Mappings::add_mapping( 'listtest.com', '', array( 'active' => true ) );

		// Add two values.
		$id1 = DMS_Mapping_Values::set_value( $mapping_id, 'post', 1, true );
		$id2 = DMS_Mapping_Values::set_value( $mapping_id, 'category', 5, false );

		$list = DMS_Mapping_Values::get_values( $mapping_id );
		$this->assertIsArray( $list, 'get_values should return an array.' );
		$this->assertGreaterThan( 0, count( $list ), 'should have at least one value.' );

		$ids = array_map( fn( $v ) => $v['id'], $list );
		$this->assertContains( $id1, $ids, 'first added value should appear in list.' );
		$this->assertContains( $id2, $ids, 'second added value should appear in list.' );
	}

	public function test_is_supported_type() {
		foreach ( array( 'post', 'page', 'category', 'tag', 'archive', 'product', 'cpt' ) as $type ) {
			$result = DMS_Mapping_Values::is_supported_type( $type );
			$this->assertTrue( $result, sprintf( 'object type %s should be supported.', $type ) );
		}
		$result = DMS_Mapping_Values::is_supported_type( 'unknown' );
		$this->assertFalse( $result, 'unknown object type should not be supported.' );
	}
}

final class DMS_Test_REST_Dms extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		if ( DMS_Mode::is_multisite() ) {
			$this->markTestSkipped( 'The dms/v1 contract is single-site only; in multisite its routes delegate to the multisite engine.' );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Creates a single-site mapping once per test, since REST calls
	 * operate on the mapping ID.
	 *
	 * @return int Mapping ID.
	 */
	protected function create_test_mapping() {
		$id = DMS_Single_Site_Mappings::add_mapping( 'resttest.io', '', array( 'active' => true, 'primary' => true ) );
		return $id;
	}

	public function test_rest_get_mappings_single_site() {
		$id = $this->create_test_mapping();
		$url = add_query_arg( 'id', $id, rest_url( 'dms/v1/mappings' ) );
		$response = rest_do_remote_request( 'GET', $url );
		$this->assertEquals( 200, $response['response']['code'], 'GET /dms/v1/mappings should return 200.' );
	}

	public function test_rest_create_mapping_single_site() {
		$url = rest_url( 'dms/v1/mappings' );
		$response = rest_do_remote_request( 'POST', $url, array(
			'body' => json_encode( array( 'host' => 'new.io', 'active' => false, 'primary' => true ) ),
		) );
		$this->assertEquals( 201, $response['response']['code'], 'POST /dms/v1/mappings should return 201.' );
	}

	public function test_rest_batch_limit_exceeded() {
		$items = array_fill( 0, 30, array( 'op' => 'create', 'host' => 'batch.io' ) );
		$url = rest_url( 'dms/v1/mappings/batch' );
		$response = rest_do_remote_request( 'POST', $url, array(
			'body' => json_encode( array( 'items' => $items ) ),
		) );
		$this->assertEquals( 400, $response['response']['code'], 'Batch exceeding limit should return 400.' );
	}

	public function test_rest_values_unsupported_in_multisite() {
		// Single-site supports mapping values, so there is nothing to assert
		// in this class (which only runs in single-site mode).
		$this->markTestSkipped( 'dms/v1 values are supported in single-site mode; this class only runs there.' );
	}

	public function test_rest_update_mapping_delegates_mode() {
		$id = $this->create_test_mapping();
		$url = rest_url( 'dms/v1/mappings/' . $id );
		$response = rest_do_remote_request( 'PATCH', $url, array(
			'body' => json_encode( array( 'host' => 'updated.io' ) ),
		) );
		$this->assertEquals( 200, $response['response']['code'], 'PATCH /dms/v1/mappings/{id} should return 200 in single-site mode.' );
	}
}