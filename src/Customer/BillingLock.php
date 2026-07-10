<?php
/**
 * Enforce the organization billing address for assigned customers.
 *
 * @package WooB2B
 */

namespace WooB2B\Customer;

use WooB2B\Organization\Organization;
use WooB2B\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Members of an organization bill to the organization address:
 *
 * - WC_Customer billing getters live-read the organization address, so My
 *   Account, checkout defaults, and anything using WC_Customer agree
 *   without data syncing.
 * - The My Account "edit billing address" endpoint is blocked, saves are
 *   aborted server-side, and the edit link is hidden.
 */
class BillingLock {

	/**
	 * Billing props sourced from the organization. first_name / last_name stay
	 * personal to the customer.
	 *
	 * @var string[]
	 */
	private const LOCKED_PROPS = array(
		'company',
		'address_1',
		'address_2',
		'city',
		'state',
		'postcode',
		'country',
		'email',
		'phone',
	);

	/**
	 * Hook registration.
	 */
	public function register(): void {
		foreach ( self::LOCKED_PROPS as $prop ) {
			add_filter(
				"woocommerce_customer_get_billing_{$prop}",
				function ( $value, $customer ) use ( $prop ) {
					return $this->filter_billing_prop( $value, $customer, $prop );
				},
				10,
				2
			);
		}

		// The bulk getter (used by wc_get_account_formatted_address and
		// others) bypasses the per-prop filters, so cover it too.
		add_filter( 'woocommerce_customer_get_billing', array( $this, 'filter_billing_array' ), 10, 2 );

		add_action( 'template_redirect', array( $this, 'block_billing_edit_endpoint' ) );
		add_action( 'woocommerce_after_save_address_validation', array( $this, 'abort_billing_save' ), 10, 2 );
		add_action( 'woocommerce_my_account_after_my_address', array( $this, 'render_managed_note' ) );
		add_action( 'wp_head', array( $this, 'hide_edit_link_css' ) );
	}

	/**
	 * Organization whose billing address applies to the current visitor, or null.
	 */
	private function organization_for_current_user(): ?Organization {
		if ( ! is_user_logged_in() ) {
			return null;
		}
		return $this->organization_for_user( get_current_user_id() );
	}

	/**
	 * Organization enforcement lookup for any user: feature enabled, user
	 * assigned, and the organization actually has an address.
	 *
	 * @param int $user_id User ID.
	 */
	public function organization_for_user( int $user_id ): ?Organization {
		if ( ! Settings::enabled( Settings::OPTION_ORGANIZATION_BILLING ) ) {
			return null;
		}
		$organization = Organization::for_user( $user_id );
		if ( ! $organization || ! $organization->has_address() ) {
			return null;
		}
		return $organization;
	}

	/**
	 * Serve billing props from the organization for assigned customers.
	 *
	 * @param mixed        $value    Stored value.
	 * @param \WC_Customer $customer Customer being read.
	 * @param string       $prop     Billing prop name.
	 * @return mixed
	 */
	public function filter_billing_prop( $value, $customer, string $prop ) {
		if ( ! is_object( $customer ) || ! method_exists( $customer, 'get_id' ) ) {
			return $value;
		}

		$organization = $this->organization_for_user( (int) $customer->get_id() );
		if ( ! $organization ) {
			return $value;
		}

		$organization_value = $organization->address_field( $prop );

		// Contact fields fall back to the customer's own details when the
		// organization has not provided them; address fields are authoritative.
		if ( in_array( $prop, array( 'email', 'phone' ), true ) && '' === $organization_value ) {
			return $value;
		}

		return $organization_value;
	}

	/**
	 * Serve the whole billing array from the organization for assigned
	 * customers (same rules as the per-prop filter).
	 *
	 * @param mixed        $value    Stored billing array.
	 * @param \WC_Customer $customer Customer being read.
	 * @return mixed
	 */
	public function filter_billing_array( $value, $customer ) {
		if ( ! is_array( $value ) || ! is_object( $customer ) || ! method_exists( $customer, 'get_id' ) ) {
			return $value;
		}

		$organization = $this->organization_for_user( (int) $customer->get_id() );
		if ( ! $organization ) {
			return $value;
		}

		foreach ( self::LOCKED_PROPS as $prop ) {
			if ( ! array_key_exists( $prop, $value ) ) {
				continue;
			}
			$organization_value = $organization->address_field( $prop );
			if ( in_array( $prop, array( 'email', 'phone' ), true ) && '' === $organization_value ) {
				continue;
			}
			$value[ $prop ] = $organization_value;
		}

		return $value;
	}

	/**
	 * Organization members cannot open the billing address edit form.
	 */
	public function block_billing_edit_endpoint(): void {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'edit-address' ) ) {
			return;
		}

		global $wp;
		$address_type = $wp->query_vars['edit-address'] ?? '';
		if ( 'billing' !== $address_type ) {
			return;
		}

		if ( ! $this->organization_for_current_user() ) {
			return;
		}

		wc_add_notice( $this->locked_message(), 'notice' );
		wp_safe_redirect( wc_get_endpoint_url( 'edit-address', '', wc_get_page_permalink( 'myaccount' ) ) );
		exit;
	}

	/**
	 * Abort billing address saves for organization members (defence in depth —
	 * the form is already unreachable). WooCommerce cancels the save when
	 * an error notice exists after this hook.
	 *
	 * @param int    $user_id      User being saved.
	 * @param string $address_type 'billing' or 'shipping'.
	 */
	public function abort_billing_save( $user_id, $address_type ): void {
		if ( 'billing' !== $address_type ) {
			return;
		}
		if ( ! $this->organization_for_user( (int) $user_id ) ) {
			return;
		}
		wc_add_notice( $this->locked_message(), 'error' );
	}

	/**
	 * Note shown under the billing address on the My Account addresses page.
	 *
	 * @param string $name Address type being rendered.
	 */
	public function render_managed_note( $name ): void {
		if ( 'billing' !== $name ) {
			return;
		}
		$organization = $this->organization_for_current_user();
		if ( ! $organization ) {
			return;
		}
		printf(
			'<br /><em class="wb2b-managed-note">%s</em>',
			esc_html(
				sprintf(
					/* translators: %s: organization name. */
					__( 'Billing address is managed by %s and cannot be edited.', 'woo-b2b-pro' ),
					$organization->name()
				)
			)
		);
	}

	/**
	 * Hide the billing "Edit" link on the addresses page. The endpoint
	 * block above is the real enforcement; this only removes a dead link.
	 */
	public function hide_edit_link_css(): void {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}
		if ( ! $this->organization_for_current_user() ) {
			return;
		}
		echo '<style id="wb2b-billing-lock">.woocommerce-Addresses .woocommerce-Address:first-child .woocommerce-Address-title .edit,.woocommerce-MyAccount-content > .woocommerce-Address:first-of-type .woocommerce-Address-title .edit{display:none;}</style>';
	}

	/**
	 * User-facing explanation for the lock.
	 */
	private function locked_message(): string {
		return __( 'Your billing address is managed by your organization and cannot be changed here.', 'woo-b2b-pro' );
	}
}
