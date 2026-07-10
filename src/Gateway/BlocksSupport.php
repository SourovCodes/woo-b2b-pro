<?php
/**
 * Checkout-block support for the pay-by-invoice gateway.
 *
 * @package WooB2B
 */

namespace WooB2B\Gateway;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes the gateway to the cart/checkout blocks. Availability is decided
 * server-side by InvoiceGateway::is_available(), so the block simply mirrors
 * the gateway's title and description.
 */
final class BlocksSupport extends AbstractPaymentMethodType {

	/**
	 * Payment method name, matching the gateway id.
	 *
	 * @var string
	 */
	protected $name = InvoiceGateway::ID;

	/**
	 * Load the gateway settings.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . InvoiceGateway::ID . '_settings', array() );
	}

	/**
	 * Whether the payment method should be offered.
	 *
	 * @return bool
	 */
	public function is_active() {
		$gateway = $this->gateway();
		return $gateway ? $gateway->is_available() : false;
	}

	/**
	 * Register and return the block script handle.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'wb2b-invoice-blocks',
			plugins_url( 'assets/js/invoice-blocks.js', WB2B_PLUGIN_FILE ),
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			WB2B_VERSION,
			true
		);
		return array( 'wb2b-invoice-blocks' );
	}

	/**
	 * Data handed to the block script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$gateway = $this->gateway();
		return array(
			'title'       => $gateway ? $gateway->title : $this->get_setting( 'title' ),
			'description' => $gateway ? $gateway->description : $this->get_setting( 'description' ),
			'supports'    => $gateway ? array_filter( $gateway->supports, array( $gateway, 'supports' ) ) : array( 'products' ),
		);
	}

	/**
	 * The registered gateway instance, if WooCommerce has loaded it.
	 *
	 * @return InvoiceGateway|null
	 */
	private function gateway(): ?InvoiceGateway {
		$gateways = WC()->payment_gateways ? WC()->payment_gateways->payment_gateways() : array();
		$gateway  = $gateways[ InvoiceGateway::ID ] ?? null;
		return $gateway instanceof InvoiceGateway ? $gateway : null;
	}
}
