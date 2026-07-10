<?php
/**
 * Restrict ordering to organization members.
 *
 * @package WooB2B
 */

namespace WooB2B\Customer;

use WooB2B\Organization\Organization;
use WooB2B\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * When "Require organization membership to order" is enabled, visitors
 * who do not belong to an organization (including guests) cannot
 * purchase: products are not purchasable, an explanatory notice is shown
 * on product pages, and both the classic checkout and the Store API
 * checkout reject submissions server-side (covering carts filled before
 * the setting was switched on).
 */
class OrderGate {

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_filter( 'woocommerce_is_purchasable', array( $this, 'filter_purchasable' ), 90 );
		add_filter( 'woocommerce_variation_is_purchasable', array( $this, 'filter_purchasable' ), 90 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_product_notice' ), 31 );
		add_action( 'woocommerce_checkout_process', array( $this, 'block_classic_checkout' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'block_store_api_checkout' ), 5 );
	}

	/**
	 * Whether ordering is blocked for the current visitor.
	 */
	public function blocked(): bool {
		if ( ! Settings::enabled( Settings::OPTION_REQUIRE_ORGANIZATION ) ) {
			return false;
		}
		if ( ! is_user_logged_in() ) {
			return true;
		}
		return null === Organization::for_user( get_current_user_id() );
	}

	/**
	 * Non-members cannot purchase.
	 *
	 * @param bool $purchasable Original value.
	 * @return bool
	 */
	public function filter_purchasable( $purchasable ) {
		return $this->blocked() ? false : $purchasable;
	}

	/**
	 * Explain on single product pages why there is no add-to-cart button.
	 */
	public function render_product_notice(): void {
		if ( ! $this->blocked() ) {
			return;
		}
		printf(
			'<div class="wb2b-order-gate woocommerce-info">%s</div>',
			esc_html( $this->message() )
		);
	}

	/**
	 * Reject classic checkout submissions from non-members.
	 */
	public function block_classic_checkout(): void {
		if ( $this->blocked() ) {
			wc_add_notice( $this->message(), 'error' );
		}
	}

	/**
	 * Reject Store API (block checkout) submissions from non-members.
	 *
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When ordering is blocked.
	 */
	public function block_store_api_checkout(): void {
		if ( ! $this->blocked() ) {
			return;
		}
		if ( class_exists( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
				'wb2b_order_gate',
				esc_html( $this->message() ),
				403
			);
		}
	}

	/**
	 * User-facing explanation, filterable via wb2b_order_gate_message.
	 */
	public function message(): string {
		$message = __( 'Ordering is available to organization members only. Please contact us to have your account linked to an organization.', 'woo-b2b-pro' );

		/**
		 * Filter the message shown to visitors who cannot order.
		 *
		 * @param string $message Message text.
		 */
		return (string) apply_filters( 'wb2b_order_gate_message', $message );
	}
}
