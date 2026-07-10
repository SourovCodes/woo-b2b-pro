<?php

namespace WooB2B\Tests\Unit;

use Brain\Monkey\Functions;
use WooB2B\PriceGuard;
use WooB2B\Settings;
use WooB2B\Tests\TestCase;

class PriceGuardTest extends TestCase {

	private function guard_for( bool $hide_enabled, bool $logged_in ): PriceGuard {
		$this->stub_options( array( Settings::OPTION_HIDE_PRICES => $hide_enabled ? 'yes' : 'no' ) );
		Functions\when( 'is_user_logged_in' )->justReturn( $logged_in );
		Functions\when( 'wc_get_page_permalink' )->justReturn( 'https://shop.test/my-account/' );
		return new PriceGuard();
	}

	public function test_price_html_replaced_for_guests_when_enabled(): void {
		$guard = $this->guard_for( true, false );
		$html  = $guard->filter_price_html( '<span>$10</span>' );

		$this->assertStringNotContainsString( '$10', $html );
		$this->assertStringContainsString( 'https://shop.test/my-account/', $html );
		$this->assertStringContainsString( 'Sign in to see prices', $html );
	}

	public function test_price_html_untouched_for_logged_in_users(): void {
		$guard = $this->guard_for( true, true );
		$this->assertSame( '<span>$10</span>', $guard->filter_price_html( '<span>$10</span>' ) );
	}

	public function test_price_html_untouched_when_disabled(): void {
		$guard = $this->guard_for( false, false );
		$this->assertSame( '<span>$10</span>', $guard->filter_price_html( '<span>$10</span>' ) );
	}

	public function test_guests_cannot_purchase_while_hidden(): void {
		$guard = $this->guard_for( true, false );
		$this->assertFalse( $guard->filter_purchasable( true ) );
	}

	public function test_purchasable_untouched_when_visible(): void {
		$guard = $this->guard_for( true, true );
		$this->assertTrue( $guard->filter_purchasable( true ) );
	}

	public function test_structured_data_offers_removed(): void {
		$guard  = $this->guard_for( true, false );
		$markup = $guard->filter_structured_data(
			array(
				'@type'  => 'Product',
				'name'   => 'Widget',
				'offers' => array( array( 'price' => '10' ) ),
			)
		);

		$this->assertArrayNotHasKey( 'offers', $markup );
		$this->assertSame( 'Widget', $markup['name'] );
	}

	public function test_store_api_product_prices_stripped(): void {
		$guard = $this->guard_for( true, false );

		$response = new \WP_HTTP_Response(
			array(
				array(
					'id'         => 1,
					'prices'     => array( 'price' => '1000' ),
					'price_html' => '<span>$10</span>',
				),
			)
		);
		$request  = new \WP_REST_Request( '/wc/store/v1/products' );

		$result = $guard->filter_store_api_response( $response, null, $request );
		$data   = $result->get_data();

		$this->assertArrayNotHasKey( 'prices', $data[0] );
		$this->assertStringContainsString( 'Sign in to see prices', $data[0]['price_html'] );
	}

	public function test_non_product_rest_routes_untouched(): void {
		$guard = $this->guard_for( true, false );

		$payload  = array( array( 'id' => 1, 'prices' => array( 'price' => '1000' ) ) );
		$response = new \WP_HTTP_Response( $payload );
		$request  = new \WP_REST_Request( '/wc/store/v1/cart' );

		$result = $guard->filter_store_api_response( $response, null, $request );

		$this->assertSame( $payload, $result->get_data() );
	}
}
