<?php
/**
 * Pay by invoice payment gateway.
 *
 * @package WooB2B
 */

namespace WooB2B\Gateway;

use WooB2B\Organization\Organization;

defined( 'ABSPATH' ) || exit;

/**
 * Offline gateway for B2B customers who pay after delivery.
 *
 * The plugin does NOT generate an invoice: order data is exported to a
 * centralized external system (integration hooks into the
 * `wb2b_invoice_order_placed` action) which produces the invoice PDF and
 * sends it to the customer. Payment is collected after the order has been
 * delivered and fulfilled, so `payment_complete()` is deliberately never
 * called — the order is placed in the configured status (processing by
 * default) and marked paid manually once settled.
 */
class InvoiceGateway extends \WC_Payment_Gateway {

	public const ID = 'wb2b_invoice';

	/**
	 * Configure the gateway and hook thank-you / email output.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->icon               = '';
		$this->has_fields         = false;
		$this->method_title       = __( 'Pay by invoice (B2B)', 'woo-b2b-pro' );
		$this->method_description = __( 'Customers order now and pay after delivery. No invoice is created here — order data is exported to your central system, which issues the invoice.', 'woo-b2b-pro' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
		add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'prevent_auto_paid_date' ), 10, 3 );
	}

	/**
	 * Stop WooCommerce from stamping "Paid on" (date_paid) just because the
	 * order reached the processing/completed status.
	 *
	 * WC_Order::maybe_set_date_paid() treats reaching the filtered
	 * "payment complete" status as payment, which is wrong here: invoice
	 * orders are only paid after delivery. Returning a status the order
	 * never has keeps date_paid empty. When date_paid IS already set (a
	 * real payment_complete() call sets it before applying this filter),
	 * the original status passes through untouched.
	 *
	 * @param string         $status   Status that counts as "paid".
	 * @param int            $order_id Order ID.
	 * @param \WC_Order|null $order    The order, when provided by core.
	 * @return string
	 */
	public function prevent_auto_paid_date( $status, $order_id, $order = null ) {
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof \WC_Order || self::ID !== $order->get_payment_method() ) {
			return $status;
		}
		if ( $order->get_date_paid( 'edit' ) ) {
			return $status;
		}
		return 'wb2b-invoice-unpaid';
	}

	/**
	 * Admin settings under WooCommerce → Settings → Payments.
	 */
	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'      => array(
				'title'   => __( 'Enable/Disable', 'woo-b2b-pro' ),
				'label'   => __( 'Enable pay by invoice', 'woo-b2b-pro' ),
				'type'    => 'checkbox',
				'default' => 'no',
			),
			'title'        => array(
				'title'    => __( 'Title', 'woo-b2b-pro' ),
				'type'     => 'text',
				'desc_tip' => __( 'Payment method name shown at checkout.', 'woo-b2b-pro' ),
				'default'  => __( 'Pay by invoice', 'woo-b2b-pro' ),
			),
			'description'  => array(
				'title'    => __( 'Description', 'woo-b2b-pro' ),
				'type'     => 'textarea',
				'desc_tip' => __( 'Shown under the payment method name at checkout.', 'woo-b2b-pro' ),
				'default'  => __( 'Place your order now and pay by invoice after delivery. The invoice will be sent to you separately.', 'woo-b2b-pro' ),
			),
			'instructions' => array(
				'title'    => __( 'Instructions', 'woo-b2b-pro' ),
				'type'     => 'textarea',
				'desc_tip' => __( 'Shown on the thank-you page and in order emails.', 'woo-b2b-pro' ),
				'default'  => __( 'Thank you for your order. You will receive an invoice after delivery; payment is due per the terms stated on it.', 'woo-b2b-pro' ),
			),
			'order_status' => array(
				'title'       => __( 'Order status after checkout', 'woo-b2b-pro' ),
				'type'        => 'select',
				'description' => __( 'Status given to new orders. The order is never marked as paid automatically — do that manually once the invoice is settled.', 'woo-b2b-pro' ),
				'default'     => 'processing',
				'options'     => array(
					'processing' => __( 'Processing — start fulfilment immediately', 'woo-b2b-pro' ),
					'on-hold'    => __( 'On hold — review before fulfilment', 'woo-b2b-pro' ),
				),
			),
			'members_only' => array(
				'title'   => __( 'Organization members only', 'woo-b2b-pro' ),
				'label'   => __( 'Offer this payment method only to customers assigned to an organization', 'woo-b2b-pro' ),
				'type'    => 'checkbox',
				'default' => 'yes',
			),
		);
	}

	/**
	 * Restrict the gateway to organization members when configured.
	 *
	 * @return bool
	 */
	public function is_available() {
		$available = parent::is_available();

		if ( $available && 'yes' === $this->get_option( 'members_only', 'yes' ) ) {
			$available = is_user_logged_in() && null !== Organization::for_user( get_current_user_id() );
		}

		/**
		 * Filter whether the pay-by-invoice gateway is offered to the
		 * current customer.
		 *
		 * @param bool           $available Availability so far.
		 * @param InvoiceGateway $gateway   Gateway instance.
		 */
		return (bool) apply_filters( 'wb2b_invoice_gateway_available', $available, $this );
	}

	/**
	 * Place the order without taking payment.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		/**
		 * Filter the status given to orders placed with pay by invoice.
		 *
		 * @param string    $status Status slug (without the wc- prefix).
		 * @param \WC_Order $order  The order.
		 */
		$status = apply_filters( 'wb2b_invoice_order_status', $this->get_option( 'order_status', 'processing' ), $order );

		// Deliberately no payment_complete(): payment is collected after
		// delivery, once the external system has invoiced the customer.
		$order->update_status( $status, __( 'Order placed with pay by invoice; payment due after delivery.', 'woo-b2b-pro' ) );

		wc_reduce_stock_levels( $order_id );

		if ( isset( WC()->cart ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		/**
		 * Fires after a pay-by-invoice order has been placed.
		 *
		 * This is the integration point for exporting the order to the
		 * centralized system that generates and sends the invoice PDF.
		 *
		 * @param \WC_Order $order The placed order.
		 */
		do_action( 'wb2b_invoice_order_placed', $order );

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Instructions on the order-received page.
	 */
	public function thankyou_page(): void {
		$instructions = $this->get_option( 'instructions' );
		if ( $instructions ) {
			echo wp_kses_post( wpautop( wptexturize( $instructions ) ) );
		}
	}

	/**
	 * Instructions in customer order emails.
	 *
	 * @param \WC_Order $order         The order.
	 * @param bool      $sent_to_admin Whether this is the admin copy.
	 * @param bool      $plain_text    Whether the email is plain text.
	 */
	public function email_instructions( $order, $sent_to_admin, $plain_text = false ): void {
		if ( $sent_to_admin || self::ID !== $order->get_payment_method() ) {
			return;
		}
		$instructions = $this->get_option( 'instructions' );
		if ( ! $instructions ) {
			return;
		}
		if ( $plain_text ) {
			echo esc_html( wp_strip_all_tags( wptexturize( $instructions ) ) ) . PHP_EOL;
		} else {
			echo wp_kses_post( wpautop( wptexturize( $instructions ) ) ) . PHP_EOL;
		}
	}
}
