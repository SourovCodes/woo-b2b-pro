<?php

namespace WooB2B\Tests\Unit;

use Brain\Monkey\Functions;
use WooB2B\Customer\OrderGate;
use WooB2B\Settings;
use WooB2B\Tests\TestCase;

class OrderGateTest extends TestCase {

	private const ADDRESS = array(
		'address_1' => '1 Campus Way',
		'city'      => 'Springfield',
		'postcode'  => '90210',
		'country'   => 'US',
	);

	public function test_not_blocked_when_setting_off(): void {
		$this->stub_options( array( Settings::OPTION_REQUIRE_ORGANIZATION => 'no' ) );
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$this->assertFalse( ( new OrderGate() )->blocked() );
	}

	public function test_guests_blocked_when_enabled(): void {
		$this->stub_options( array( Settings::OPTION_REQUIRE_ORGANIZATION => 'yes' ) );
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$this->assertTrue( ( new OrderGate() )->blocked() );
	}

	public function test_unassigned_users_blocked(): void {
		$this->stub_options( array( Settings::OPTION_REQUIRE_ORGANIZATION => 'yes' ) );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 9 );
		Functions\when( 'get_user_meta' )->justReturn( '' );

		$this->assertTrue( ( new OrderGate() )->blocked() );
	}

	public function test_organization_members_can_order(): void {
		$this->stub_options( array( Settings::OPTION_REQUIRE_ORGANIZATION => 'yes' ) );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		$this->stub_organization_user( 7, 42, self::ADDRESS );

		$this->assertFalse( ( new OrderGate() )->blocked() );
	}

	public function test_membership_counts_even_without_org_address(): void {
		// Ordering requires membership only; the billing enforcement
		// separately decides whether the org address is usable.
		$this->stub_options( array( Settings::OPTION_REQUIRE_ORGANIZATION => 'yes' ) );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		$this->stub_organization_user( 7, 42, array() );

		$this->assertFalse( ( new OrderGate() )->blocked() );
	}

	public function test_purchasable_forced_false_when_blocked(): void {
		$this->stub_options( array( Settings::OPTION_REQUIRE_ORGANIZATION => 'yes' ) );
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$this->assertFalse( ( new OrderGate() )->filter_purchasable( true ) );
	}

	public function test_purchasable_untouched_when_not_blocked(): void {
		$this->stub_options( array( Settings::OPTION_REQUIRE_ORGANIZATION => 'no' ) );
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$this->assertTrue( ( new OrderGate() )->filter_purchasable( true ) );
	}

	public function test_classic_checkout_rejected_when_blocked(): void {
		$this->stub_options( array( Settings::OPTION_REQUIRE_ORGANIZATION => 'yes' ) );
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		Functions\expect( 'wc_add_notice' )->once()->with( \Mockery::type( 'string' ), 'error' );

		( new OrderGate() )->block_classic_checkout();
	}

	public function test_classic_checkout_untouched_for_members(): void {
		$this->stub_options( array( Settings::OPTION_REQUIRE_ORGANIZATION => 'yes' ) );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		$this->stub_organization_user( 7, 42, self::ADDRESS );

		Functions\expect( 'wc_add_notice' )->never();

		( new OrderGate() )->block_classic_checkout();
	}
}
