<?php
/**
 * Payment gateway registration.
 *
 * @package WooB2B
 */

namespace WooB2B\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the pay-by-invoice gateway with WooCommerce, for both the
 * classic checkout and the checkout block.
 */
class Registrar {

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateway' ) );
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( $this, 'register_blocks_support' ) );
	}

	/**
	 * Add the gateway class to WooCommerce's list.
	 *
	 * @param array $gateways Gateway class names / instances.
	 * @return array
	 */
	public function add_gateway( array $gateways ): array {
		$gateways[] = InvoiceGateway::class;
		return $gateways;
	}

	/**
	 * Register checkout-block support when WooCommerce Blocks is present.
	 *
	 * @param object $registry Blocks PaymentMethodRegistry.
	 */
	public function register_blocks_support( $registry ): void {
		if ( class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
			$registry->register( new BlocksSupport() );
		}
	}
}
