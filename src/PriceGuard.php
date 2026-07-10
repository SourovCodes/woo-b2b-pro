<?php
/**
 * Hide prices from logged-out visitors.
 *
 * @package WooB2B
 */

namespace WooB2B;

defined( 'ABSPATH' ) || exit;

/**
 * When enabled, guests see a login prompt instead of prices and cannot
 * purchase. Covers price HTML, purchasability (which also blocks the
 * Store API add-to-cart), product structured data, and Store API
 * product listings.
 */
class PriceGuard {

	/**
	 * Hook everything.
	 */
	public function register(): void {
		add_filter( 'woocommerce_get_price_html', array( $this, 'filter_price_html' ), 100, 2 );
		add_filter( 'woocommerce_is_purchasable', array( $this, 'filter_purchasable' ), 100 );
		add_filter( 'woocommerce_variation_is_purchasable', array( $this, 'filter_purchasable' ), 100 );
		add_filter( 'woocommerce_structured_data_product', array( $this, 'filter_structured_data' ), 100 );
		add_filter( 'rest_post_dispatch', array( $this, 'filter_store_api_response' ), 100, 3 );
	}

	/**
	 * Whether prices must be hidden for the current visitor.
	 */
	public function should_hide(): bool {
		return Settings::enabled( Settings::OPTION_HIDE_PRICES ) && ! is_user_logged_in();
	}

	/**
	 * Placeholder markup shown instead of a price.
	 */
	public function placeholder_html(): string {
		$html = sprintf(
			'<span class="wb2b-price-hidden"><a href="%s">%s</a></span>',
			esc_url( wc_get_page_permalink( 'myaccount' ) ),
			esc_html( Settings::price_placeholder() )
		);

		/**
		 * Filter the markup rendered in place of a hidden price.
		 *
		 * @param string $html Placeholder markup.
		 */
		return apply_filters( 'wb2b_price_placeholder_html', $html );
	}

	/**
	 * Swap the price HTML for the placeholder.
	 *
	 * @param string $price_html Original price HTML.
	 * @return string
	 */
	public function filter_price_html( $price_html ) {
		return $this->should_hide() ? $this->placeholder_html() : $price_html;
	}

	/**
	 * Guests cannot purchase while prices are hidden.
	 *
	 * @param bool $purchasable Original value.
	 * @return bool
	 */
	public function filter_purchasable( $purchasable ) {
		return $this->should_hide() ? false : $purchasable;
	}

	/**
	 * Strip price offers from product structured data so hidden prices do
	 * not leak into search-engine markup.
	 *
	 * @param array $markup Structured data.
	 * @return array
	 */
	public function filter_structured_data( $markup ) {
		if ( $this->should_hide() && is_array( $markup ) ) {
			unset( $markup['offers'] );
		}
		return $markup;
	}

	/**
	 * Strip price data from Store API product responses (used by product
	 * blocks) for guests.
	 *
	 * @param \WP_HTTP_Response $response Result to send.
	 * @param \WP_REST_Server   $server   Server instance.
	 * @param \WP_REST_Request  $request  Request used.
	 * @return \WP_HTTP_Response
	 */
	public function filter_store_api_response( $response, $server, $request ) {
		if ( ! $this->should_hide() || ! $response instanceof \WP_HTTP_Response ) {
			return $response;
		}

		$route = $request instanceof \WP_REST_Request ? $request->get_route() : '';
		if ( ! preg_match( '#^/wc/store(?:/v\d+)?/products#', (string) $route ) ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}

		if ( isset( $data['id'] ) ) {
			$data = $this->strip_price_fields( $data );
		} else {
			foreach ( $data as $index => $item ) {
				if ( is_array( $item ) ) {
					$data[ $index ] = $this->strip_price_fields( $item );
				}
			}
		}

		$response->set_data( $data );
		return $response;
	}

	/**
	 * Remove price-bearing keys from a Store API product payload.
	 *
	 * @param array $product Product response data.
	 * @return array
	 */
	private function strip_price_fields( array $product ): array {
		unset( $product['prices'] );
		$product['price_html'] = $this->placeholder_html();

		if ( isset( $product['variations'] ) && is_array( $product['variations'] ) ) {
			foreach ( $product['variations'] as $i => $variation ) {
				if ( is_array( $variation ) ) {
					unset( $product['variations'][ $i ]['prices'] );
				}
			}
		}

		return $product;
	}
}
