<?php
/**
 * Organization post type registration.
 *
 * @package WooB2B
 */

namespace WooB2B\Organization;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the wb2b_organization post type under the WooCommerce admin menu.
 * Every capability maps to manage_woocommerce so only administrators and
 * shop managers can manage organizations.
 */
class PostType {

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_filter( 'manage_' . Organization::POST_TYPE . '_posts_columns', array( $this, 'add_columns' ) );
		add_action( 'manage_' . Organization::POST_TYPE . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
	}

	/**
	 * Register the post type.
	 */
	public function register_post_type(): void {
		register_post_type(
			Organization::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => __( 'Organizations', 'woo-b2b-pro' ),
					'singular_name'      => __( 'Organization', 'woo-b2b-pro' ),
					'add_new'            => __( 'Add new', 'woo-b2b-pro' ),
					'add_new_item'       => __( 'Add new organization', 'woo-b2b-pro' ),
					'edit_item'          => __( 'Edit organization', 'woo-b2b-pro' ),
					'new_item'           => __( 'New organization', 'woo-b2b-pro' ),
					'search_items'       => __( 'Search organizations', 'woo-b2b-pro' ),
					'not_found'          => __( 'No organizations found.', 'woo-b2b-pro' ),
					'not_found_in_trash' => __( 'No organizations found in trash.', 'woo-b2b-pro' ),
					'menu_name'          => __( 'Organizations', 'woo-b2b-pro' ),
				),
				'description'         => __( 'B2B / B2Edu organisations whose members share a billing address.', 'woo-b2b-pro' ),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'woocommerce',
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'show_in_rest'        => false,
				'supports'            => array( 'title' ),
				'capabilities'        => array(
					'edit_post'          => 'manage_woocommerce',
					'read_post'          => 'manage_woocommerce',
					'delete_post'        => 'manage_woocommerce',
					'edit_posts'         => 'manage_woocommerce',
					'edit_others_posts'  => 'manage_woocommerce',
					'delete_posts'       => 'manage_woocommerce',
					'delete_others_posts' => 'manage_woocommerce',
					'publish_posts'      => 'manage_woocommerce',
					'read_private_posts' => 'manage_woocommerce',
					'create_posts'       => 'manage_woocommerce',
				),
				'map_meta_cap'        => false,
			)
		);
	}

	/**
	 * Add Members and Billing city columns to the list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_columns( $columns ) {
		$date = $columns['date'] ?? null;
		unset( $columns['date'] );

		$columns['wb2b_billing'] = __( 'Billing address', 'woo-b2b-pro' );
		$columns['wb2b_members'] = __( 'Members', 'woo-b2b-pro' );

		if ( null !== $date ) {
			$columns['date'] = $date;
		}
		return $columns;
	}

	/**
	 * Render custom column values.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Organization post ID.
	 */
	public function render_column( $column, $post_id ): void {
		$organization = Organization::find( (int) $post_id );
		if ( ! $organization ) {
			return;
		}

		if ( 'wb2b_members' === $column ) {
			echo esc_html( (string) self::member_count( $organization->id() ) );
			return;
		}

		if ( 'wb2b_billing' === $column ) {
			$parts = array_filter(
				array(
					$organization->address_field( 'address_1' ),
					$organization->address_field( 'city' ),
					$organization->address_field( 'postcode' ),
					$organization->address_field( 'country' ),
				)
			);
			echo $parts ? esc_html( implode( ', ', $parts ) ) : '&mdash;';
		}
	}

	/**
	 * Number of users assigned to an organization.
	 *
	 * @param int $organization_id Organization post ID.
	 */
	public static function member_count( int $organization_id ): int {
		$query = new \WP_User_Query(
			array(
				'meta_key'    => Organization::USER_META,
				'meta_value'  => $organization_id,
				'count_total' => true,
				'fields'      => 'ID',
				'number'      => 1,
			)
		);
		return (int) $query->get_total();
	}

	/**
	 * Users assigned to an organization.
	 *
	 * @param int $organization_id Organization post ID.
	 * @return \WP_User[]
	 */
	public static function members( int $organization_id, int $limit = 50 ): array {
		$query = new \WP_User_Query(
			array(
				'meta_key'   => Organization::USER_META,
				'meta_value' => $organization_id,
				'number'     => $limit,
				'orderby'    => 'display_name',
			)
		);
		return $query->get_results();
	}
}
