<?php
/**
 * Billing address metabox for organizations.
 *
 * @package WooB2B
 */

namespace WooB2B\Organization;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves the WooCommerce-style billing address on the organization
 * edit screen, plus a read-only members metabox.
 */
class AddressMetabox {

	private const NONCE_ACTION = 'wb2b_save_organization_address';
	private const NONCE_FIELD  = 'wb2b_organization_address_nonce';

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes_' . Organization::POST_TYPE, array( $this, 'add_metaboxes' ) );
		add_action( 'save_post_' . Organization::POST_TYPE, array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Load WooCommerce's country/state script on the organization edit
	 * screen so the state field adapts to the selected country exactly
	 * like WooCommerce's own address forms (dropdown of states/districts
	 * where the country has them, free text otherwise).
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_scripts( $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || Organization::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style( 'woocommerce_admin_styles' );

		// WooCommerce only registers wc-users on the user-edit screens;
		// register the same script for this screen.
		if ( ! wp_script_is( 'wc-users', 'registered' ) ) {
			$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
			wp_register_script(
				'wc-users',
				WC()->plugin_url() . '/assets/js/admin/users' . $suffix . '.js',
				array( 'jquery', 'wc-enhanced-select', 'selectWoo' ),
				defined( 'WC_VERSION' ) ? WC_VERSION : false,
				true
			);
		}
		wp_enqueue_script( 'wc-users' );
		wp_localize_script(
			'wc-users',
			'wc_users_params',
			array(
				'countries'              => wp_json_encode(
					array_merge( WC()->countries->get_allowed_country_states(), WC()->countries->get_shipping_country_states() ),
					JSON_HEX_TAG | JSON_UNESCAPED_SLASHES
				),
				'i18n_select_state_text' => esc_attr__( 'Select an option&hellip;', 'woocommerce' ),
			)
		);
	}

	/**
	 * Register the metaboxes.
	 */
	public function add_metaboxes(): void {
		add_meta_box(
			'wb2b-organization-address',
			__( 'Billing address', 'woo-b2b-pro' ),
			array( $this, 'render_address' ),
			Organization::POST_TYPE,
			'normal',
			'high'
		);
		add_meta_box(
			'wb2b-organization-members',
			__( 'Members', 'woo-b2b-pro' ),
			array( $this, 'render_members' ),
			Organization::POST_TYPE,
			'side'
		);
	}

	/**
	 * Field definitions: key => [label, type].
	 *
	 * @return array<string, array{0:string,1:string}>
	 */
	private function fields(): array {
		return array(
			'address_1' => array( __( 'Address line 1', 'woo-b2b-pro' ), 'text' ),
			'address_2' => array( __( 'Address line 2', 'woo-b2b-pro' ), 'text' ),
			'city'      => array( __( 'City', 'woo-b2b-pro' ), 'text' ),
			'postcode'  => array( __( 'Postcode / ZIP', 'woo-b2b-pro' ), 'text' ),
			'country'   => array( __( 'Country / Region', 'woo-b2b-pro' ), 'country' ),
			'state'     => array( __( 'State / County', 'woo-b2b-pro' ), 'state' ),
			'email'     => array( __( 'Billing email', 'woo-b2b-pro' ), 'email' ),
			'phone'     => array( __( 'Billing phone', 'woo-b2b-pro' ), 'text' ),
		);
	}

	/**
	 * Render the address metabox.
	 *
	 * @param \WP_Post $post Organization post.
	 */
	public function render_address( $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		echo '<p class="description">';
		esc_html_e( 'Members of this organization bill to this address. The company name (title above) is used as the billing company.', 'woo-b2b-pro' );
		echo '</p>';
		echo '<table class="form-table"><tbody>';

		foreach ( $this->fields() as $key => $field ) {
			list( $label, $type ) = $field;
			$meta_key             = Organization::META_PREFIX . $key;
			$value                = (string) get_post_meta( $post->ID, $meta_key, true );
			$input_id             = 'wb2b_billing_' . $key;

			echo '<tr><th scope="row"><label for="' . esc_attr( $input_id ) . '">' . esc_html( $label ) . '</label></th><td>';

			if ( 'country' === $type ) {
				// The js_field-country / js_field-state classes hook into
				// WooCommerce's users.js, which swaps the state input for a
				// dropdown of that country's states/districts (or back to
				// free text for countries without any) — identical to the
				// WooCommerce checkout and user-profile behavior.
				$countries = WC()->countries ? WC()->countries->get_countries() : array();
				echo '<select id="' . esc_attr( $input_id ) . '" name="' . esc_attr( $input_id ) . '" class="js_field-country" style="width:25em;">';
				echo '<option value="">' . esc_html__( '— Select a country —', 'woo-b2b-pro' ) . '</option>';
				foreach ( $countries as $code => $name ) {
					echo '<option value="' . esc_attr( $code ) . '" ' . selected( $value, $code, false ) . '>' . esc_html( $name ) . '</option>';
				}
				echo '</select>';
			} elseif ( 'state' === $type ) {
				echo '<input type="text" class="regular-text js_field-state" id="' . esc_attr( $input_id ) . '" name="' . esc_attr( $input_id ) . '" value="' . esc_attr( $value ) . '" />';
			} else {
				$input_type = 'email' === $type ? 'email' : 'text';
				echo '<input type="' . esc_attr( $input_type ) . '" class="regular-text" id="' . esc_attr( $input_id ) . '" name="' . esc_attr( $input_id ) . '" value="' . esc_attr( $value ) . '" />';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Render the members metabox.
	 *
	 * @param \WP_Post $post Organization post.
	 */
	public function render_members( $post ): void {
		$members = PostType::members( (int) $post->ID );

		if ( ! $members ) {
			echo '<p>' . esc_html__( 'No customers assigned yet. Assign customers from their user profile.', 'woo-b2b-pro' ) . '</p>';
			return;
		}

		echo '<ul style="margin:0;">';
		foreach ( $members as $user ) {
			printf(
				'<li><a href="%s">%s</a> <span class="description">(%s)</span></li>',
				esc_url( get_edit_user_link( $user->ID ) ),
				esc_html( $user->display_name ),
				esc_html( $user->user_email )
			);
		}
		echo '</ul>';

		$total = PostType::member_count( (int) $post->ID );
		if ( $total > count( $members ) ) {
			/* translators: %d: number of members not listed. */
			echo '<p class="description">' . esc_html( sprintf( __( '…and %d more.', 'woo-b2b-pro' ), $total - count( $members ) ) ) . '</p>';
		}
	}

	/**
	 * Persist the address fields.
	 *
	 * @param int      $post_id Organization post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function save( $post_id, $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ self::NONCE_FIELD ] ), self::NONCE_ACTION ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce is verified, not stored.
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		foreach ( array_keys( $this->fields() ) as $key ) {
			$input_id = 'wb2b_billing_' . $key;
			$raw      = isset( $_POST[ $input_id ] ) ? wp_unslash( $_POST[ $input_id ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per-field below.

			if ( 'email' === $key ) {
				$value = sanitize_email( $raw );
			} else {
				$value = wc_clean( $raw );
			}

			update_post_meta( $post_id, Organization::META_PREFIX . $key, $value );
		}
	}
}
