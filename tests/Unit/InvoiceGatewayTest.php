<?php
/**
 * Pay-by-invoice gateway tests.
 *
 * @package WooB2B\Tests
 */

namespace WooB2B\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use WooB2B\Gateway\InvoiceGateway;
use WooB2B\Gateway\Registrar;
use WooB2B\Tests\TestCase;

class InvoiceGatewayTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_option' )->justReturn( false );
	}

	private function make_gateway( array $settings = array() ): InvoiceGateway {
		$gateway           = new InvoiceGateway();
		$gateway->settings = $settings;
		$gateway->enabled  = $gateway->get_option( 'enabled', 'yes' );
		return $gateway;
	}

	public function test_process_payment_places_order_without_taking_payment(): void {
		$order     = new \WC_Order();
		$order->id = 42;

		Functions\expect( 'wc_get_order' )->once()->with( 42 )->andReturn( $order );
		Functions\expect( 'wc_reduce_stock_levels' )->once()->with( 42 );
		Functions\when( 'WC' )->justReturn( (object) array( 'cart' => null ) );

		Actions\expectDone( 'wb2b_invoice_order_placed' )->once()->with( $order );

		$gateway = $this->make_gateway( array( 'enabled' => 'yes' ) );
		$result  = $gateway->process_payment( 42 );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'https://example.test/order-received', $result['redirect'] );
		$this->assertSame( 'processing', $order->status_updates[0][0] );
		$this->assertFalse( $order->paid, 'payment_complete() must never be called' );
	}

	public function test_process_payment_uses_configured_order_status(): void {
		$order = new \WC_Order();

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'wc_reduce_stock_levels' )->justReturn( null );
		Functions\when( 'WC' )->justReturn( (object) array( 'cart' => null ) );

		$gateway = $this->make_gateway( array( 'order_status' => 'on-hold' ) );
		$gateway->process_payment( 7 );

		$this->assertSame( 'on-hold', $order->status_updates[0][0] );
	}

	public function test_order_status_is_filterable(): void {
		$order = new \WC_Order();

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'wc_reduce_stock_levels' )->justReturn( null );
		Functions\when( 'WC' )->justReturn( (object) array( 'cart' => null ) );

		Filters\expectApplied( 'wb2b_invoice_order_status' )
			->once()
			->andReturn( 'on-hold' );

		$gateway = $this->make_gateway( array() );
		$gateway->process_payment( 7 );

		$this->assertSame( 'on-hold', $order->status_updates[0][0] );
	}

	public function test_unavailable_to_guests_when_members_only(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$gateway = $this->make_gateway( array( 'enabled' => 'yes' ) );

		$this->assertFalse( $gateway->is_available() );
	}

	public function test_unavailable_to_users_without_organization_when_members_only(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'get_user_meta' )->justReturn( '' );

		$gateway = $this->make_gateway( array( 'enabled' => 'yes' ) );

		$this->assertFalse( $gateway->is_available() );
	}

	public function test_available_to_organization_members(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		$this->stub_organization_user( 5, 11, array() );

		$gateway = $this->make_gateway( array( 'enabled' => 'yes' ) );

		$this->assertTrue( $gateway->is_available() );
	}

	public function test_available_to_everyone_when_members_only_disabled(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$gateway = $this->make_gateway(
			array(
				'enabled'      => 'yes',
				'members_only' => 'no',
			)
		);

		$this->assertTrue( $gateway->is_available() );
	}

	public function test_unavailable_when_disabled(): void {
		$gateway = $this->make_gateway(
			array(
				'enabled'      => 'no',
				'members_only' => 'no',
			)
		);

		$this->assertFalse( $gateway->is_available() );
	}

	public function test_availability_is_filterable(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		Filters\expectApplied( 'wb2b_invoice_gateway_available' )
			->once()
			->andReturn( true );

		$gateway = $this->make_gateway( array( 'enabled' => 'yes' ) );

		$this->assertTrue( $gateway->is_available() );
	}

	public function test_paid_date_is_not_stamped_for_unpaid_invoice_orders(): void {
		$order                 = new \WC_Order();
		$order->payment_method = InvoiceGateway::ID;

		$gateway = $this->make_gateway( array() );

		$this->assertSame(
			'wb2b-invoice-unpaid',
			$gateway->prevent_auto_paid_date( 'processing', 1, $order ),
			'WooCommerce must never see the real status as "payment complete" for an unpaid invoice order'
		);
	}

	public function test_paid_date_passes_through_once_actually_paid(): void {
		$order                 = new \WC_Order();
		$order->payment_method = InvoiceGateway::ID;
		$order->date_paid      = 1234567890;

		$gateway = $this->make_gateway( array() );

		$this->assertSame( 'processing', $gateway->prevent_auto_paid_date( 'processing', 1, $order ) );
	}

	public function test_paid_date_untouched_for_other_gateways(): void {
		$order                 = new \WC_Order();
		$order->payment_method = 'cod';

		$gateway = $this->make_gateway( array() );

		$this->assertSame( 'processing', $gateway->prevent_auto_paid_date( 'processing', 1, $order ) );
	}

	public function test_registrar_adds_gateway_class(): void {
		$registrar = new Registrar();

		$this->assertSame(
			array( 'other', InvoiceGateway::class ),
			$registrar->add_gateway( array( 'other' ) )
		);
	}
}
