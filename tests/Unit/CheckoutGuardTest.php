<?php

namespace WooB2B\Tests\Unit;

use Brain\Monkey\Functions;
use WooB2B\Customer\CheckoutGuard;
use WooB2B\Settings;
use WooB2B\Tests\TestCase;

class CheckoutGuardTest extends TestCase {

	private const ADDRESS = array(
		'address_1' => '1 Campus Way',
		'address_2' => '',
		'city'      => 'Springfield',
		'state'     => 'CA',
		'postcode'  => '90210',
		'country'   => 'US',
		'email'     => 'billing@acme.test',
		'phone'     => '+1 555 0100',
	);

	private function stub_logged_in_company_member(): void {
		$this->stub_options( array( Settings::OPTION_ORGANIZATION_BILLING => 'yes' ) );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		$this->stub_organization_user( 7, 42, self::ADDRESS, 'Acme University' );
		Functions\when( 'wp_get_current_user' )->justReturn(
			(object) array(
				'ID'         => 7,
				'first_name' => 'Jane',
				'last_name'  => 'Doe',
				'user_email' => 'jane@personal.test',
			)
		);
	}

	public function test_billing_fields_removed_for_company_members(): void {
		$this->stub_logged_in_company_member();

		$fields = ( new CheckoutGuard() )->remove_billing_fields(
			array(
				'billing'  => array( 'billing_first_name' => array(), 'billing_address_1' => array() ),
				'shipping' => array( 'shipping_address_1' => array() ),
			)
		);

		$this->assertSame( array(), $fields['billing'] );
		$this->assertArrayHasKey( 'shipping_address_1', $fields['shipping'] );
	}

	public function test_billing_fields_kept_for_unassigned_users(): void {
		$this->stub_options( array( Settings::OPTION_ORGANIZATION_BILLING => 'yes' ) );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 9 );
		Functions\when( 'get_user_meta' )->justReturn( '' );

		$original = array( 'billing' => array( 'billing_first_name' => array() ) );
		$this->assertSame( $original, ( new CheckoutGuard() )->remove_billing_fields( $original ) );
	}

	public function test_billing_fields_kept_for_guests(): void {
		$this->stub_options( array( Settings::OPTION_ORGANIZATION_BILLING => 'yes' ) );
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$original = array( 'billing' => array( 'billing_first_name' => array() ) );
		$this->assertSame( $original, ( new CheckoutGuard() )->remove_billing_fields( $original ) );
	}

	public function test_posted_data_forced_from_company(): void {
		$this->stub_logged_in_company_member();

		$data = ( new CheckoutGuard() )->force_billing_posted_data(
			array(
				'ship_to_different_address' => 1,
				'shipping_address_1'        => '9 Delivery Dock',
			)
		);

		$this->assertSame( 'Acme University', $data['billing_company'] );
		$this->assertSame( '1 Campus Way', $data['billing_address_1'] );
		$this->assertSame( 'US', $data['billing_country'] );
		$this->assertSame( 'billing@acme.test', $data['billing_email'] );
		$this->assertSame( 'Jane', $data['billing_first_name'] );
		$this->assertSame( 'Doe', $data['billing_last_name'] );
		// Shipping input untouched.
		$this->assertSame( '9 Delivery Dock', $data['shipping_address_1'] );
	}

	public function test_empty_company_email_falls_back_to_account_email(): void {
		$this->stub_options( array( Settings::OPTION_ORGANIZATION_BILLING => 'yes' ) );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		$this->stub_organization_user( 7, 42, array_merge( self::ADDRESS, array( 'email' => '' ) ) );
		Functions\when( 'wp_get_current_user' )->justReturn(
			(object) array(
				'ID'         => 7,
				'first_name' => 'Jane',
				'last_name'  => 'Doe',
				'user_email' => 'jane@personal.test',
			)
		);

		$data = ( new CheckoutGuard() )->force_billing_posted_data( array( 'ship_to_different_address' => 1 ) );

		$this->assertSame( 'jane@personal.test', $data['billing_email'] );
	}

	public function test_shipping_backfilled_when_not_shipping_separately(): void {
		$this->stub_logged_in_company_member();

		// WooCommerce pre-filled shipping from the (removed, empty) billing
		// fields before our filter ran.
		$data = ( new CheckoutGuard() )->force_billing_posted_data(
			array(
				'shipping_address_1' => '',
				'shipping_city'      => '',
				'shipping_country'   => '',
				'shipping_postcode'  => '',
				'shipping_state'     => '',
				'shipping_company'   => '',
				'shipping_first_name' => '',
				'shipping_last_name' => '',
				'shipping_address_2' => '',
			)
		);

		$this->assertSame( '1 Campus Way', $data['shipping_address_1'] );
		$this->assertSame( 'Springfield', $data['shipping_city'] );
		$this->assertSame( 'US', $data['shipping_country'] );
		$this->assertSame( 'Jane', $data['shipping_first_name'] );
	}

	public function test_posted_data_untouched_without_company(): void {
		$this->stub_options( array( Settings::OPTION_ORGANIZATION_BILLING => 'yes' ) );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 9 );
		Functions\when( 'get_user_meta' )->justReturn( '' );

		$original = array( 'billing_address_1' => 'Personal St 5' );
		$this->assertSame( $original, ( new CheckoutGuard() )->force_billing_posted_data( $original ) );
	}

	public function test_order_billing_forced_from_company(): void {
		$this->stub_options( array( Settings::OPTION_ORGANIZATION_BILLING => 'yes' ) );
		$this->stub_organization_user( 7, 42, self::ADDRESS, 'Acme University' );

		$order              = new \WC_Order();
		$order->customer_id = 7;

		( new CheckoutGuard() )->force_order_billing( $order );

		$this->assertSame( 'Acme University', $order->billing['company'] );
		$this->assertSame( '1 Campus Way', $order->billing['address_1'] );
		$this->assertSame( 'US', $order->billing['country'] );
		$this->assertSame( 'billing@acme.test', $order->billing['email'] );
	}

	public function test_order_billing_untouched_for_guest_orders(): void {
		$this->stub_options( array( Settings::OPTION_ORGANIZATION_BILLING => 'yes' ) );

		$order = new \WC_Order();

		( new CheckoutGuard() )->force_order_billing( $order );

		$this->assertSame( array(), $order->billing );
	}

	public function test_separate_shipping_forced_for_company_members(): void {
		$this->stub_logged_in_company_member();
		$this->assertTrue( ( new CheckoutGuard() )->force_separate_shipping( false ) );
	}

	public function test_separate_shipping_untouched_for_others(): void {
		$this->stub_options( array( Settings::OPTION_ORGANIZATION_BILLING => 'yes' ) );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		$this->assertFalse( ( new CheckoutGuard() )->force_separate_shipping( false ) );
	}
}
