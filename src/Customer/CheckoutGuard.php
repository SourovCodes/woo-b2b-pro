<?php
/**
 * Checkout handling for organization members.
 *
 * @package WooB2B
 */

namespace WooB2B\Customer;

use WooB2B\Organization\Organization;
use WooB2B\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Organization members do not fill in billing details at checkout: the billing
 * form is replaced with a summary of the organization address, the shipping
 * form is always shown, and the billing address is forced server-side on
 * both the classic checkout and the Store API (block checkout).
 */
class CheckoutGuard {

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_filter( 'woocommerce_checkout_fields', array( $this, 'remove_billing_fields' ), 1000 );
		add_action( 'woocommerce_before_checkout_billing_form', array( $this, 'render_organization_summary' ) );
		add_filter( 'woocommerce_ship_to_different_address_checked', array( $this, 'force_separate_shipping' ), 100 );
		add_filter( 'woocommerce_checkout_posted_data', array( $this, 'force_billing_posted_data' ), 100 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'force_order_billing' ), 100 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'force_order_billing' ), 100 );
	}

	/**
	 * Organization of the current visitor when enforcement applies, or null.
	 */
	public function applies(): ?Organization {
		if ( ! Settings::enabled( Settings::OPTION_ORGANIZATION_BILLING ) || ! is_user_logged_in() ) {
			return null;
		}
		$organization = Organization::for_user( get_current_user_id() );
		if ( ! $organization || ! $organization->has_address() ) {
			return null;
		}
		return $organization;
	}

	/**
	 * Drop every billing field from the checkout form for organization members.
	 *
	 * @param array $fields Checkout fieldsets.
	 * @return array
	 */
	public function remove_billing_fields( $fields ) {
		if ( ! $this->applies() ) {
			return $fields;
		}
		$fields['billing'] = array();
		return $fields;
	}

	/**
	 * Replace the billing form with a summary of the organization address.
	 */
	public function render_organization_summary(): void {
		$organization = $this->applies();
		if ( ! $organization ) {
			return;
		}

		$address   = $organization->address();
		$formatted = WC()->countries->get_formatted_address( $address );

		echo '<div class="wb2b-organization-billing woocommerce-info" style="margin-bottom:1.5em;">';
		printf(
			'<p style="margin:0 0 .5em;">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: organization name. */
					__( 'Billing details will be taken from your organization, %s:', 'woo-b2b-pro' ),
					$organization->name()
				)
			)
		);
		echo '<address style="font-style:normal;">' . wp_kses_post( $formatted ) . '</address>';
		echo '</div>';
		// The shipping form is mandatory for organization members; hide the
		// "ship to a different address?" toggle it would otherwise show.
		echo '<style id="wb2b-hide-ship-toggle">#ship-to-different-address{display:none;}</style>';
	}

	/**
	 * Always treat shipping as a separate address so the shipping form
	 * renders and posts for organization members.
	 *
	 * @param bool $checked Original state.
	 * @return bool
	 */
	public function force_separate_shipping( $checked ) {
		return $this->applies() ? true : $checked;
	}

	/**
	 * Force organization billing values into the posted checkout data. Runs
	 * after WooCommerce collected the form fields, so removed billing
	 * fields are re-populated from the organization here.
	 *
	 * @param array $data Posted data.
	 * @return array
	 */
	public function force_billing_posted_data( $data ) {
		$organization = $this->applies();
		if ( ! $organization ) {
			return $data;
		}

		$user = wp_get_current_user();

		$first_name = (string) get_user_meta( $user->ID, 'billing_first_name', true );
		$last_name  = (string) get_user_meta( $user->ID, 'billing_last_name', true );

		$data['billing_first_name'] = '' !== $first_name ? $first_name : (string) $user->first_name;
		$data['billing_last_name']  = '' !== $last_name ? $last_name : (string) $user->last_name;

		foreach ( $organization->address() as $key => $value ) {
			$data[ 'billing_' . $key ] = $value;
		}

		// Contact fields fall back to the customer's own details.
		if ( '' === $data['billing_email'] ) {
			$data['billing_email'] = (string) $user->user_email;
		}
		if ( '' === $data['billing_phone'] ) {
			$data['billing_phone'] = (string) get_user_meta( $user->ID, 'billing_phone', true );
		}

		// WooCommerce copies billing into the shipping fields *before* this
		// filter when "ship to a different address" is unchecked, so that
		// copy ran against the removed (empty) billing fields. Re-copy the
		// enforced billing values in that case.
		if ( empty( $data['ship_to_different_address'] ) ) {
			foreach ( array( 'first_name', 'last_name', 'company', 'country', 'state', 'postcode', 'city', 'address_1', 'address_2' ) as $key ) {
				if ( array_key_exists( 'shipping_' . $key, $data ) && '' === (string) $data[ 'shipping_' . $key ] ) {
					$data[ 'shipping_' . $key ] = $data[ 'billing_' . $key ] ?? '';
				}
			}
		}

		return $data;
	}

	/**
	 * Authoritative server-side enforcement: write the organization billing
	 * address onto the order. Hooked to both the classic checkout
	 * (woocommerce_checkout_create_order) and the Store API block checkout
	 * (woocommerce_store_api_checkout_update_order_from_request).
	 *
	 * @param \WC_Order $order Order being created/updated.
	 */
	public function force_order_billing( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return;
		}
		if ( ! Settings::enabled( Settings::OPTION_ORGANIZATION_BILLING ) ) {
			return;
		}
		$organization = Organization::for_user( $user_id );
		if ( ! $organization || ! $organization->has_address() ) {
			return;
		}

		foreach ( $organization->address() as $key => $value ) {
			if ( in_array( $key, array( 'email', 'phone' ), true ) && '' === $value ) {
				continue; // Keep the customer's own contact details.
			}
			$setter = "set_billing_{$key}";
			if ( is_callable( array( $order, $setter ) ) ) {
				$order->{$setter}( $value );
			}
		}

		if ( '' === (string) $order->get_billing_email() ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$order->set_billing_email( $user->user_email );
			}
		}
	}
}
