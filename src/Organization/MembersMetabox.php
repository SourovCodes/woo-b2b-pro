<?php
/**
 * Member management on the organization edit screen.
 *
 * @package WooB2B
 */

namespace WooB2B\Organization;

defined( 'ABSPATH' ) || exit;

/**
 * Lists an organization's members with a WooCommerce customer-search
 * picker to add new ones and a checkbox to remove existing ones. Adding
 * a customer who already belongs to another organization moves them to
 * this one (a customer belongs to exactly one organization).
 */
class MembersMetabox {

	private const NONCE_ACTION = 'wb2b_manage_members';
	private const NONCE_FIELD  = 'wb2b_members_nonce';
	private const ADD_FIELD    = 'wb2b_add_members';
	private const REMOVE_FIELD = 'wb2b_remove_members';

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes_' . Organization::POST_TYPE, array( $this, 'add_metabox' ) );
		add_action( 'save_post_' . Organization::POST_TYPE, array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Load the member-search script on the organization edit screen.
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

		wp_enqueue_script(
			'wb2b-members',
			plugins_url( 'assets/js/admin/members.js', WB2B_PLUGIN_FILE ),
			array( 'jquery', 'selectWoo', 'wc-enhanced-select' ),
			WB2B_VERSION,
			true
		);
		wp_localize_script(
			'wb2b-members',
			'wb2b_members_params',
			array(
				'search_action'     => MemberSearch::ACTION,
				'organization_id'   => (int) get_the_ID(),
				'move_marker'       => MemberSearch::move_marker(),
				/* translators: %s: organization name. */
				'i18n_move_confirm' => __( 'This customer currently belongs to “%s”. Adding them here will move them to this organization. Continue?', 'woo-b2b-pro' ),
			)
		);
	}

	/**
	 * Register the metabox.
	 */
	public function add_metabox(): void {
		// Main column, right below the billing address: the member roster
		// is primary content and the search field needs the width.
		add_meta_box(
			'wb2b-organization-members',
			__( 'Members', 'woo-b2b-pro' ),
			array( $this, 'render' ),
			Organization::POST_TYPE,
			'normal'
		);
	}

	/**
	 * Render member list with remove checkboxes plus the add-members picker.
	 *
	 * @param \WP_Post $post Organization post.
	 */
	public function render( $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$members = PostType::members( (int) $post->ID );

		if ( $members ) {
			?>
			<table class="widefat striped" style="margin-bottom:1em;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Member', 'woo-b2b-pro' ); ?></th>
						<th><?php esc_html_e( 'Email', 'woo-b2b-pro' ); ?></th>
						<th><?php esc_html_e( 'Orders', 'woo-b2b-pro' ); ?></th>
						<th style="width:70px;"><?php esc_html_e( 'Remove', 'woo-b2b-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $members as $user ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>"><strong><?php echo esc_html( $user->display_name ); ?></strong></a></td>
							<td><a href="mailto:<?php echo esc_attr( $user->user_email ); ?>"><?php echo esc_html( $user->user_email ); ?></a></td>
							<td><?php echo esc_html( (string) ( function_exists( 'wc_get_customer_order_count' ) ? wc_get_customer_order_count( $user->ID ) : 0 ) ); ?></td>
							<td style="text-align:center;">
								<label class="screen-reader-text" for="wb2b-remove-<?php echo (int) $user->ID; ?>"><?php esc_html_e( 'Remove member', 'woo-b2b-pro' ); ?></label>
								<input type="checkbox" id="wb2b-remove-<?php echo (int) $user->ID; ?>" name="<?php echo esc_attr( self::REMOVE_FIELD ); ?>[]" value="<?php echo (int) $user->ID; ?>" />
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description" style="margin-top:-0.5em;">
				<?php esc_html_e( 'Ticked members are removed when the organization is updated.', 'woo-b2b-pro' ); ?>
				<?php
				$total = PostType::member_count( (int) $post->ID );
				if ( $total > count( $members ) ) {
					printf(
						' <a href="%s">%s</a>',
						esc_url( add_query_arg( 'wb2b_org', (int) $post->ID, admin_url( 'users.php' ) ) ),
						/* translators: %d: total member count. */
						esc_html( sprintf( __( 'Showing the first %1$d — view all %2$d members.', 'woo-b2b-pro' ), count( $members ), $total ) )
					);
				}
				?>
			</p>
			<?php
		} else {
			echo '<p>' . esc_html__( 'No members yet.', 'woo-b2b-pro' ) . '</p>';
		}

		?>
		<p style="margin-bottom:4px;"><strong><?php esc_html_e( 'Add members', 'woo-b2b-pro' ); ?></strong></p>
		<select
			class="wb2b-member-search"
			multiple="multiple"
			style="width:100%;max-width:30em;"
			name="<?php echo esc_attr( self::ADD_FIELD ); ?>[]"
			data-placeholder="<?php esc_attr_e( 'Search customers…', 'woo-b2b-pro' ); ?>"></select>
		<p class="description">
			<?php esc_html_e( 'Customers who already belong to another organization are moved to this one.', 'woo-b2b-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Process additions/removals on save.
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

		$add    = isset( $_POST[ self::ADD_FIELD ] ) ? array_map( 'intval', (array) wp_unslash( $_POST[ self::ADD_FIELD ] ) ) : array();
		$remove = isset( $_POST[ self::REMOVE_FIELD ] ) ? array_map( 'intval', (array) wp_unslash( $_POST[ self::REMOVE_FIELD ] ) ) : array();

		$this->apply_changes( (int) $post_id, $add, $remove );
	}

	/**
	 * Apply membership changes. Additions overwrite any previous
	 * organization membership; removals only apply to users who actually
	 * belong to this organization (stale form data is ignored).
	 *
	 * @param int   $organization_id Organization post ID.
	 * @param int[] $add             User IDs to add.
	 * @param int[] $remove          User IDs to remove.
	 */
	public function apply_changes( int $organization_id, array $add, array $remove ): void {
		if ( ! Organization::find( $organization_id ) ) {
			return;
		}

		foreach ( array_unique( array_map( 'intval', $add ) ) as $user_id ) {
			if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
				continue;
			}
			Organization::assign_user( $user_id, $organization_id );
		}

		foreach ( array_unique( array_map( 'intval', $remove ) ) as $user_id ) {
			if ( $user_id <= 0 ) {
				continue;
			}
			$current = Organization::for_user( $user_id );
			if ( $current && $current->id() === $organization_id ) {
				Organization::assign_user( $user_id, 0 );
			}
		}
	}
}
