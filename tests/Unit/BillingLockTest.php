<?php

namespace WooB2B\Tests\Unit;

use Brain\Monkey\Functions;
use WooB2B\Customer\BillingLock;
use WooB2B\Settings;
use WooB2B\Tests\TestCase;

class BillingLockTest extends TestCase {

	private const ADDRESS = array(
		'address_1' => '1 Campus Way',
		'city'      => 'Springfield',
		'state'     => 'CA',
		'postcode'  => '90210',
		'country'   => 'US',
		'email'     => 'billing@acme.test',
		'phone'     => '',
	);

	/**
	 * Anonymous stand-in for WC_Customer.
	 */
	private function customer( int $id ): object {
		return new class( $id ) {
			private $id;
			public function __construct( int $id ) {
				$this->id = $id;
			}
			public function get_id(): int {
				return $this->id;
			}
		};
	}

	public function test_billing_props_come_from_company(): void {
		$this->stub_options( array( Settings::OPTION_ORGANIZATION_BILLING => 'yes' ) );
		$this->stub_organization_user( 7, 42, self::ADDRESS, 'Acme University' );

		$lock     = new BillingLock();
		$customer = $this->customer( 7 );

		$this->assertSame( '1 Campus Way', $lock->filter_billing_prop( 'Personal St 5', $customer, 'address_1' ) );
		$this->assertSame( 'US', $lock->filter_billing_prop( 'DE', $customer, 'country' ) );
		$this->assertSame( 'Acme University', $lock->filter_billing_prop( 'Personal Co', $customer, 'company' ) );
		$this->assertSame( 'billing@acme.test', $lock->filter_billing_prop( 'me@personal.test', $customer, 'email' ) );
	}

	public function test_empty_company_contact_fields_fall_back_to_customer(): void {
		$this->stub_options( array( Settings::OPTION_ORGANIZATION_BILLING => 'yes' ) );
		$this->stub_organization_user( 7, 42, self::ADDRESS );

		$lock = new BillingLock();

		$this->assertSame( '+1 555 0199', $lock->filter_billing_prop( '+1 555 0199', $this->customer( 7 ), 'phone' ) );
	}

	public function test_empty_company_address_fields_are_authoritative(): void {
		$this->stub_options( array( Settings::OPTION_ORGANIZATION_BILLING => 'yes' ) );
		$this->stub_organization_user( 7, 42, self::ADDRESS ); // No address_2.

		$lock = new BillingLock();

		$this->assertSame( '', $lock->filter_billing_prop( 'Apt 9', $this->customer( 7 ), 'address_2' ) );
	}

	public function test_passthrough_for_users_without_company(): void {
		$this->stub_options( array( Settings::OPTION_ORGANIZATION_BILLING => 'yes' ) );
		Functions\when( 'get_user_meta' )->justReturn( '' );

		$lock = new BillingLock();

		$this->assertSame( 'Personal St 5', $lock->filter_billing_prop( 'Personal St 5', $this->customer( 7 ), 'address_1' ) );
	}

	public function test_passthrough_when_feature_disabled(): void {
		$this->stub_options( array( Settings::OPTION_ORGANIZATION_BILLING => 'no' ) );
		$this->stub_organization_user( 7, 42, self::ADDRESS );

		$lock = new BillingLock();

		$this->assertSame( 'Personal St 5', $lock->filter_billing_prop( 'Personal St 5', $this->customer( 7 ), 'address_1' ) );
	}

	public function test_passthrough_when_company_has_no_address(): void {
		$this->stub_options( array( Settings::OPTION_ORGANIZATION_BILLING => 'yes' ) );
		$this->stub_organization_user( 7, 42, array( 'email' => 'billing@acme.test' ) );

		$lock = new BillingLock();

		$this->assertSame( 'Personal St 5', $lock->filter_billing_prop( 'Personal St 5', $this->customer( 7 ), 'address_1' ) );
	}

	public function test_passthrough_for_guest_session_customer(): void {
		$this->stub_options( array( Settings::OPTION_ORGANIZATION_BILLING => 'yes' ) );

		$lock = new BillingLock();

		$this->assertSame( 'Guest St 1', $lock->filter_billing_prop( 'Guest St 1', $this->customer( 0 ), 'address_1' ) );
	}

	public function test_bulk_billing_array_comes_from_company(): void {
		$this->stub_options( array( Settings::OPTION_ORGANIZATION_BILLING => 'yes' ) );
		$this->stub_organization_user( 7, 42, self::ADDRESS, 'Acme University' );

		$stored = array(
			'first_name' => 'Sam',
			'last_name'  => 'Smoke',
			'company'    => 'Personal Co',
			'address_1'  => 'Personal St 5',
			'city'       => 'Personaltown',
			'state'      => '',
			'postcode'   => '00000',
			'country'    => 'DE',
			'email'      => 'me@personal.test',
			'phone'      => '+49 30 000',
		);

		$billing = ( new BillingLock() )->filter_billing_array( $stored, $this->customer( 7 ) );

		$this->assertSame( 'Acme University', $billing['company'] );
		$this->assertSame( '1 Campus Way', $billing['address_1'] );
		$this->assertSame( 'US', $billing['country'] );
		$this->assertSame( 'billing@acme.test', $billing['email'] );
		$this->assertSame( '+49 30 000', $billing['phone'] ); // Empty company phone falls back.
		$this->assertSame( 'Sam', $billing['first_name'] );   // Personal names untouched.
	}

	public function test_bulk_billing_array_passthrough_without_company(): void {
		$this->stub_options( array( Settings::OPTION_ORGANIZATION_BILLING => 'yes' ) );
		Functions\when( 'get_user_meta' )->justReturn( '' );

		$stored = array( 'address_1' => 'Personal St 5' );
		$this->assertSame( $stored, ( new BillingLock() )->filter_billing_array( $stored, $this->customer( 7 ) ) );
	}

	public function test_abort_billing_save_adds_error_notice(): void {
		$this->stub_options( array( Settings::OPTION_ORGANIZATION_BILLING => 'yes' ) );
		$this->stub_organization_user( 7, 42, self::ADDRESS );

		Functions\expect( 'wc_add_notice' )->once()->with( \Mockery::type( 'string' ), 'error' );

		( new BillingLock() )->abort_billing_save( 7, 'billing' );
	}

	public function test_shipping_save_not_blocked(): void {
		$this->stub_options( array( Settings::OPTION_ORGANIZATION_BILLING => 'yes' ) );
		$this->stub_organization_user( 7, 42, self::ADDRESS );

		Functions\expect( 'wc_add_notice' )->never();

		( new BillingLock() )->abort_billing_save( 7, 'shipping' );
	}
}
