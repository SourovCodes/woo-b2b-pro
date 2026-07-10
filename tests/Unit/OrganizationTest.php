<?php

namespace WooB2B\Tests\Unit;

use Brain\Monkey\Functions;
use WooB2B\Organization\Organization;
use WooB2B\Tests\TestCase;

class OrganizationTest extends TestCase {

	private const ADDRESS = array(
		'address_1' => '1 Campus Way',
		'address_2' => 'Building B',
		'city'      => 'Springfield',
		'state'     => 'CA',
		'postcode'  => '90210',
		'country'   => 'US',
		'email'     => 'billing@acme.test',
		'phone'     => '+1 555 0100',
	);

	public function test_for_user_returns_null_without_assignment(): void {
		Functions\when( 'get_user_meta' )->justReturn( '' );
		$this->assertNull( Organization::for_user( 7 ) );
	}

	public function test_for_user_returns_null_for_deleted_company(): void {
		Functions\when( 'get_user_meta' )->justReturn( '42' );
		Functions\when( 'get_post' )->justReturn( null );
		$this->assertNull( Organization::for_user( 7 ) );
	}

	public function test_find_rejects_wrong_post_type(): void {
		Functions\when( 'get_post' )->justReturn(
			(object) array(
				'ID'          => 42,
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);
		$this->assertNull( Organization::find( 42 ) );
	}

	public function test_find_rejects_unpublished_company(): void {
		Functions\when( 'get_post' )->justReturn(
			(object) array(
				'ID'          => 42,
				'post_type'   => Organization::POST_TYPE,
				'post_status' => 'draft',
			)
		);
		$this->assertNull( Organization::find( 42 ) );
	}

	public function test_address_maps_meta_and_company_name(): void {
		$this->stub_organization_user( 7, 42, self::ADDRESS, 'Acme University' );

		$organization = Organization::for_user( 7 );

		$this->assertNotNull( $organization );
		$this->assertSame( 42, $organization->id() );
		$this->assertSame( 'Acme University', $organization->name() );

		$address = $organization->address();
		$this->assertSame( 'Acme University', $address['company'] );
		foreach ( self::ADDRESS as $key => $value ) {
			$this->assertSame( $value, $address[ $key ] );
		}
	}

	public function test_has_address_requires_a_core_field(): void {
		$this->stub_organization_user( 7, 42, array( 'email' => 'billing@acme.test' ) );
		$organization = Organization::for_user( 7 );

		$this->assertNotNull( $organization );
		$this->assertFalse( $organization->has_address() );
	}

	public function test_has_address_true_with_core_field(): void {
		$this->stub_organization_user( 7, 42, array( 'city' => 'Springfield' ) );
		$organization = Organization::for_user( 7 );

		$this->assertNotNull( $organization );
		$this->assertTrue( $organization->has_address() );
	}

	public function test_address_field_rejects_unknown_keys(): void {
		$this->stub_organization_user( 7, 42, self::ADDRESS );
		$organization = Organization::for_user( 7 );

		$this->assertSame( '', $organization->address_field( 'first_name' ) );
	}
}
