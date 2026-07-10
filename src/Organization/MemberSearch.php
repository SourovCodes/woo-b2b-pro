<?php
/**
 * AJAX customer search annotated with organization membership.
 *
 * @package WooB2B
 */

namespace WooB2B\Organization;

defined( 'ABSPATH' ) || exit;

/**
 * Same customer search WooCommerce uses on order screens, but each result
 * is annotated with the customer's current organization so admins can see
 * a pending move before selecting anyone.
 */
class MemberSearch {

	public const ACTION = 'wb2b_json_search_members';

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Marker that precedes the other-organization annotation. The admin JS
	 * looks for this exact string to trigger the move confirmation.
	 */
	public static function move_marker(): string {
		return __( '— member of: ', 'woo-b2b-pro' );
	}

	/**
	 * Serve the search request (same nonce as WooCommerce customer search,
	 * which the enhanced-select params already carry).
	 */
	public function handle(): void {
		check_ajax_referer( 'search-customers', 'security' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( -1 );
		}

		$term        = isset( $_GET['term'] ) ? (string) wc_clean( wp_unslash( $_GET['term'] ) ) : '';
		$current_org = isset( $_GET['organization_id'] ) ? (int) $_GET['organization_id'] : 0;

		if ( '' === $term ) {
			wp_die();
		}

		$ids     = \WC_Data_Store::load( 'customer' )->search_customers( $term, 20 );
		$results = array();

		foreach ( $ids as $id ) {
			$user = get_userdata( $id );
			if ( ! $user ) {
				continue;
			}
			$results[ $id ] = $this->label( $user, $current_org );
		}

		wp_send_json( $results );
	}

	/**
	 * Build the result label: "Name (#id – email)" plus the membership
	 * annotation relative to the organization being edited.
	 *
	 * @param object $user        User (WP_User or equivalent).
	 * @param int    $current_org Organization being edited.
	 */
	public function label( $user, int $current_org ): string {
		$label = sprintf( '%s (#%d – %s)', $user->display_name, (int) $user->ID, $user->user_email );

		$organization = Organization::for_user( (int) $user->ID );
		if ( $organization ) {
			if ( $organization->id() === $current_org ) {
				$label .= ' ' . __( '— already a member', 'woo-b2b-pro' );
			} else {
				$label .= ' ' . self::move_marker() . $organization->name();
			}
		}

		return esc_html( $label );
	}
}
