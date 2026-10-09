<?php
/**
 * Mapping engine tests: normalization, CRUD round-trip, primary sync,
 * wildcard lookup and the active/verified state flags.
 */
class DMS_Test_Mapping_Engine extends WP_UnitTestCase {

	public function test_normalize_domain() {
		$engine = new ReflectionMethod( DMS_Mapping_Engine::class, 'normalize_domain' );
		$engine->setAccessible( true );

		$this->assertEquals( 'example.com', $engine->invoke( null, 'example.COM' ) );
		$this->assertEquals( 'example.com', $engine->invoke( null, 'https://www.Example.com/' ) );
		$this->assertEquals( '*.example.com', $engine->invoke( null, '*.EXAMPLE.com' ) );
		$this->assertEquals( 'example.com', $engine->invoke( null, 'example.com.' ) );
		$this->assertFalse( $engine->invoke( null, 'not-valid' ) );
		$this->assertFalse( $engine->invoke( null, 'example.com/path' ) );
		$this->assertFalse( $engine->invoke( null, '*.com' ) );
		$this->assertFalse( $engine->invoke( null, '' ) );
	}

	public function test_meta_key_for() {
		$this->assertEquals( 'dm_domain_example.com', DMS_Mapping_Engine::meta_key_for( 'WWW.Example.com' ) );
		$this->assertNull( DMS_Mapping_Engine::meta_key_for( 'invalid domain' ) );
	}

	public function test_add_get_list_remove_roundtrip() {
		$blog_id = self::factory()->blog->create();

		$mapping_id = DMS_Mapping_Engine::add_mapping( $blog_id, 'https://www.Roundtrip.example' );
		$this->assertIsInt( $mapping_id );

		$mapping = DMS_Mapping_Engine::get_mapping( $mapping_id );
		$this->assertIsArray( $mapping );
		$this->assertEquals( 'roundtrip.example', $mapping['domain'] );
		$this->assertEquals( $blog_id, $mapping['blog_id'] );
		$this->assertTrue( $mapping['active'], 'New mappings default to active (verification is optional).' );
		$this->assertFalse( $mapping['verified'] );
		$this->assertFalse( $mapping['primary'] );

		// Duplicates are rejected regardless of target site.
		$duplicate = DMS_Mapping_Engine::add_mapping( $blog_id, 'roundtrip.example' );
		$this->assertWPError( $duplicate );
		$this->assertEquals( 'dm_domain_exists', $duplicate->get_error_code() );

		// Invalid site / invalid domain produce errors, not writes.
		$this->assertWPError( DMS_Mapping_Engine::add_mapping( 999999, 'site-test.example' ) );
		$this->assertWPError( DMS_Mapping_Engine::add_mapping( $blog_id, 'not a domain' ) );

		$by_site = DMS_Mapping_Engine::list_mappings( array( 'blog_id' => $blog_id ) );
		$this->assertCount( 1, $by_site );
		$by_domain = DMS_Mapping_Engine::list_mappings( array( 'domain' => 'roundtrip.example' ) );
		$this->assertCount( 1, $by_domain );

		$this->assertTrue( DMS_Mapping_Engine::remove_mapping( $mapping_id ) );
		$this->assertWPError( DMS_Mapping_Engine::get_mapping( $mapping_id ) );
		$this->assertCount( 0, DMS_Mapping_Engine::list_mappings( array( 'blog_id' => $blog_id ) ) );
	}

	public function test_set_primary_syncs_wp_blogs_domain_and_restores_on_disable() {
		$blog_id    = self::factory()->blog->create();
		$site       = get_site( $blog_id );
		$original   = $site->domain;
		$mapping_id = DMS_Mapping_Engine::add_mapping( $blog_id, 'primary-test.example' );

		$primary = DMS_Mapping_Engine::set_primary( $mapping_id );
		$this->assertIsArray( $primary );
		$this->assertTrue( $primary['primary'] );
		$this->assertEquals( 'primary-test.example', get_site( $blog_id )->domain );

		// Disabling the primary mapping restores the pre-mapping domain.
		$disabled = DMS_Mapping_Engine::disable_mapping( $mapping_id );
		$this->assertIsArray( $disabled );
		$this->assertFalse( $disabled['active'] );
		$this->assertFalse( $disabled['primary'] );
		$this->assertEquals( $original, get_site( $blog_id )->domain );

		// Re-enable + re-promote works and re-arms restore.
		$enabled = DMS_Mapping_Engine::enable_mapping( $mapping_id );
		$this->assertTrue( $enabled['active'] );
		$reprimary = DMS_Mapping_Engine::set_primary( $mapping_id );
		$this->assertTrue( $reprimary['primary'] );
		$this->assertEquals( 'primary-test.example', get_site( $blog_id )->domain );
	}

	public function test_wildcard_mappings_resolve_by_parent_walk() {
		$blog_id = self::factory()->blog->create();
		DMS_Mapping_Engine::add_mapping( $blog_id, '*.wild.example' );

		$first = DMS_Mapping_Engine::get_mapping_for_domain( 'sub.wild.example' );
		$this->assertIsArray( $first );
		$this->assertEquals( $blog_id, $first['blog_id'] );

		$deep = DMS_Mapping_Engine::get_mapping_for_domain( 'a.b.wild.example' );
		$this->assertIsArray( $deep );
		$this->assertEquals( '*.wild.example', $deep['domain'] );

		// Wildcards do not cover the apex and cannot become primary.
		$this->assertNull( DMS_Mapping_Engine::get_mapping_for_domain( 'wild.example' ) );
		$wild_row = DMS_Mapping_Engine::list_mappings( array( 'domain' => '*.wild.example' ) );
		$this->assertWPError( DMS_Mapping_Engine::set_primary( $wild_row[0]['id'] ) );
	}

	public function test_verification_gates_activation_only_when_requested() {
		$blog_id = self::factory()->blog->create();

		// No verification workflow ever started -> activation allowed.
		$mapping_id = DMS_Mapping_Engine::add_mapping( $blog_id, 'gate-test.example' );
		$this->assertIsArray( DMS_Mapping_Engine::set_primary( $mapping_id ) );

		// Once a challenge is pending, activation is blocked until verified.
		$challenge = DMS_Mapping_Engine::generate_verification( $mapping_id, 'dns' );
		$this->assertIsArray( $challenge );
		$this->assertEquals( 'gate-test.example', $challenge['domain'] );
		$this->assertEquals( '_dm-verification.gate-test.example', $challenge['challenge_path'] );

		$blocked = DMS_Mapping_Engine::update_mapping( $mapping_id, false );
		$this->assertIsArray( $blocked ); // disable is always allowed
		$still_blocked = DMS_Mapping_Engine::enable_mapping( $mapping_id );
		$this->assertWPError( $still_blocked );
		$this->assertEquals( 'dm_requires_verification', $still_blocked->get_error_code() );
	}
}
