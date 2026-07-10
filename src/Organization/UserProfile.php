<?php
/**
 * Assign customers to an organization from the user profile screen.
 *
 * @package WooB2B
 */

namespace WooB2B\Organization;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a "Organization" dropdown to user profiles (admin only)
 * and a matching column to the users list table.
 */
class UserProfile {

	private const NONCE_ACTION = 'wb2b_assign_organization';
	private const NONCE_FIELD  = 'wb2b_assign_organization_nonce';
	private const INPUT_NAME   = 'wb2b_organization_id';

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'show_user_profile', array( $this, 'render' ) );
		add_action( 'edit_user_profile', array( $this, 'render' ) );
		add_action( 'personal_options_update', array( $this, 'save' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save' ) );
		add_filter( 'manage_users_columns', array( $this, 'add_column' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'render_column' ), 10, 3 );
	}

	/**
	 * Render the dropdown on the profile screen.
	 *
	 * @param \WP_User $user User being edited.
	 */
	public function render( $user ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$organizations = get_posts(
			array(
				'post_type'      => Organization::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$current = Organization::for_user( (int) $user->ID );
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<h2><?php esc_html_e( 'Organization', 'woo-b2b-pro' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="<?php echo esc_attr( self::INPUT_NAME ); ?>"><?php esc_html_e( 'Member of', 'woo-b2b-pro' ); ?></label></th>
				<td>
					<select name="<?php echo esc_attr( self::INPUT_NAME ); ?>" id="<?php echo esc_attr( self::INPUT_NAME ); ?>">
						<option value="0"><?php esc_html_e( '— None —', 'woo-b2b-pro' ); ?></option>
						<?php foreach ( $organizations as $organization_post ) : ?>
							<option value="<?php echo esc_attr( (string) $organization_post->ID ); ?>" <?php selected( $current ? $current->id() : 0, $organization_post->ID ); ?>>
								<?php echo esc_html( $organization_post->post_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'A customer can belong to one organization. Members bill to the organization billing address and cannot edit it themselves.', 'woo-b2b-pro' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the assignment.
	 *
	 * @param int $user_id User being saved.
	 */
	public function save( $user_id ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ self::NONCE_FIELD ] ), self::NONCE_ACTION ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce is verified, not stored.
			return;
		}
		if ( ! isset( $_POST[ self::INPUT_NAME ] ) ) {
			return;
		}

		$organization_id = (int) $_POST[ self::INPUT_NAME ];
		if ( $organization_id > 0 && ! Organization::find( $organization_id ) ) {
			return; // Unknown organization; leave the assignment untouched.
		}

		Organization::assign_user( (int) $user_id, $organization_id );
	}

	/**
	 * Add the users list column.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		$columns['wb2b_organization'] = __( 'Organization', 'woo-b2b-pro' );
		return $columns;
	}

	/**
	 * Render the users list column.
	 *
	 * @param string $output    Current output.
	 * @param string $column    Column key.
	 * @param int    $user_id   User ID.
	 * @return string
	 */
	public function render_column( $output, $column, $user_id ) {
		if ( 'wb2b_organization' !== $column ) {
			return $output;
		}
		$organization = Organization::for_user( (int) $user_id );
		if ( ! $organization ) {
			return '&mdash;';
		}
		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( (string) get_edit_post_link( $organization->id() ) ),
			esc_html( $organization->name() )
		);
	}
}
