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
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_filter( 'woocommerce_cart_shipping_packages', array( $this, 'sync_package_destination' ) );
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
	 * Load the billing-card stylesheet on the checkout page for members.
	 */
	public function enqueue_styles(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || ! $this->applies() ) {
			return;
		}
		wp_enqueue_style(
			'wb2b-checkout',
			plugins_url( 'assets/css/checkout.css', WB2B_PLUGIN_FILE ),
			array(),
			WB2B_VERSION
		);
	}

	/**
	 * Replace the billing form with a card showing the organization's
	 * billing address.
	 */
	public function render_organization_summary(): void {
		$organization = $this->applies();
		if ( ! $organization ) {
			return;
		}

		// The card shows the organization name as its own heading, so drop
		// the company line from the formatted address to avoid repeating it.
		$address = $organization->address();
		unset( $address['company'] );
		$formatted = WC()->countries->get_formatted_address( $address );

		$contact = array_filter(
			array(
				$organization->address_field( 'email' ),
				$organization->address_field( 'phone' ),
			)
		);

		ob_start();
		?>
		<div class="wb2b-org-billing">
			<p class="wb2b-org-billing__name"><?php echo esc_html( $organization->name() ); ?></p>
			<address><?php echo wp_kses_post( $formatted ); ?></address>
			<?php if ( $contact ) : ?>
				<p class="wb2b-org-billing__contact"><?php echo esc_html( implode( ' · ', $contact ) ); ?></p>
			<?php endif; ?>
			<p class="wb2b-org-billing__note">
				<?php esc_html_e( 'Billing details are provided by your organization and cannot be changed at checkout.', 'woo-b2b-pro' ); ?>
			</p>
		</div>
		<?php
		$html = (string) ob_get_clean();

		/**
		 * Filter the organization billing card rendered on the checkout
		 * page in place of the billing form.
		 *
		 * @param string       $html         Card markup.
		 * @param Organization $organization Organization being shown.
		 */
		echo apply_filters( 'wb2b_checkout_billing_card_html', $html, $organization ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above; filter output is theme-author territory.
	}

	/**
	 * When a member is NOT shipping to a separate address, they are
	 * shipping to the organization address — but the billing fields the
	 * checkout script would normally copy into the shipping destination
	 * were removed, so the session destination (and therefore the shipping
	 * rates) could be stale. Point the package destination at the
	 * organization address in that case.
	 *
	 * @param array $packages Shipping packages.
	 * @return array
	 */
	public function sync_package_destination( $packages ) {
		$organization = $this->applies();
		if ( ! $organization || ! is_array( $packages ) || $this->shipping_separately() ) {
			return $packages;
		}

		$address = $organization->address();
		foreach ( $packages as $index => $package ) {
			if ( ! isset( $package['destination'] ) ) {
				continue;
			}
			$packages[ $index ]['destination'] = array_merge(
				$package['destination'],
				array(
					'country'   => $address['country'],
					'state'     => $address['state'],
					'postcode'  => $address['postcode'],
					'city'      => $address['city'],
					'address'   => $address['address_1'],
					'address_1' => $address['address_1'],
					'address_2' => $address['address_2'],
				)
			);
		}

		return $packages;
	}

	/**
	 * Whether the current request says "ship to a different address".
	 * Falls back to the store's default toggle state when no form data is
	 * available (e.g. the first render of the checkout page).
	 */
	public function shipping_separately(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- read-only; used solely to pick the address shipping rates are calculated against.
		if ( isset( $_POST['ship_to_different_address'] ) ) {
			return ! empty( $_POST['ship_to_different_address'] );
		}
		if ( isset( $_POST['post_data'] ) ) {
			parse_str( (string) wp_unslash( $_POST['post_data'] ), $form );
			return ! empty( $form['ship_to_different_address'] );
		}
		// phpcs:enable
		return 'shipping' === get_option( 'woocommerce_ship_to_destination' );
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

		// When "ship to a different address" is unchecked the customer is
		// shipping to the billing (= organization) address. WooCommerce made
		// its billing→shipping copy *before* this filter, i.e. against the
		// removed (empty) billing fields, so re-copy the enforced values.
		if ( empty( $data['ship_to_different_address'] ) ) {
			foreach ( array( 'first_name', 'last_name', 'company', 'country', 'state', 'postcode', 'city', 'address_1', 'address_2', 'phone' ) as $key ) {
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
