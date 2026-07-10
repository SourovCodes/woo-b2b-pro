<?php
/**
 * Organization tools on the Users admin screen.
 *
 * @package WooB2B
 */

namespace WooB2B\Organization;

defined( 'ABSPATH' ) || exit;

/**
 * Adds an organization filter and a native "Change organization…" bulk
 * action to wp-admin → Users. The bulk action lives in the regular Bulk
 * actions dropdown (so core's select-an-action / select-items guards
 * apply) and reads its target from an adjacent organization dropdown.
 */
class UsersScreen {

	public const BULK_ACTION = 'wb2b_change_org';

	private const FILTER_FIELD = 'wb2b_org';
	private const BULK_SELECT  = 'wb2b_new_org';
	private const NOTICE_ARG   = 'wb2b_org_changed';
	private const ERROR_ARG    = 'wb2b_org_error';
	private const REMOVE_VALUE = 'remove';

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'restrict_manage_users', array( $this, 'render_controls' ) );
		add_action( 'pre_get_users', array( $this, 'filter_users_query' ) );
		add_filter( 'bulk_actions-users', array( $this, 'register_bulk_action' ) );
		add_filter( 'handle_bulk_actions-users', array( $this, 'handle_bulk_action' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Add "Change organization…" to the Bulk actions dropdown.
	 *
	 * @param array $actions Registered bulk actions.
	 * @return array
	 */
	public function register_bulk_action( $actions ) {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			$actions[ self::BULK_ACTION ] = __( 'Change organization…', 'woo-b2b-pro' );
		}
		return $actions;
	}

	/**
	 * Render the filter dropdown (with its own Filter button) and the
	 * target dropdown consumed by the "Change organization…" bulk action.
	 *
	 * @param string $which 'top' or 'bottom' table nav.
	 */
	public function render_controls( $which ): void {
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
		if ( ! $organizations ) {
			return;
		}

		$suffix   = 'top' === $which ? '' : '2';
		$filtered = $this->requested_filter();
		?>
		<label class="screen-reader-text" for="<?php echo esc_attr( self::BULK_SELECT . $suffix ); ?>"><?php esc_html_e( 'Target organization for the bulk action', 'woo-b2b-pro' ); ?></label>
		<select name="<?php echo esc_attr( self::BULK_SELECT . $suffix ); ?>" id="<?php echo esc_attr( self::BULK_SELECT . $suffix ); ?>">
			<option value=""><?php esc_html_e( '— Select organization —', 'woo-b2b-pro' ); ?></option>
			<option value="<?php echo esc_attr( self::REMOVE_VALUE ); ?>"><?php esc_html_e( 'No organization (remove)', 'woo-b2b-pro' ); ?></option>
			<?php foreach ( $organizations as $org ) : ?>
				<option value="<?php echo esc_attr( (string) $org->ID ); ?>"><?php echo esc_html( $org->post_title ); ?></option>
			<?php endforeach; ?>
		</select>

		<label class="screen-reader-text" for="<?php echo esc_attr( self::FILTER_FIELD . $suffix ); ?>"><?php esc_html_e( 'Filter by organization', 'woo-b2b-pro' ); ?></label>
		<select name="<?php echo esc_attr( self::FILTER_FIELD . $suffix ); ?>" id="<?php echo esc_attr( self::FILTER_FIELD . $suffix ); ?>" style="margin-left:8px;">
			<option value=""><?php esc_html_e( 'All organizations…', 'woo-b2b-pro' ); ?></option>
			<option value="none" <?php selected( $filtered, 'none' ); ?>><?php esc_html_e( 'No organization', 'woo-b2b-pro' ); ?></option>
			<?php foreach ( $organizations as $org ) : ?>
				<option value="<?php echo esc_attr( (string) $org->ID ); ?>" <?php selected( $filtered, (string) $org->ID ); ?>><?php echo esc_html( $org->post_title ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
		submit_button( __( 'Filter', 'woo-b2b-pro' ), '', 'wb2b_filter_org' . $suffix, false );
	}

	/**
	 * Apply the organization filter to the Users list query.
	 *
	 * @param \WP_User_Query $query Users query.
	 */
	public function filter_users_query( $query ): void {
		global $pagenow;

		if ( ! is_admin() || 'users.php' !== $pagenow || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$filter = $this->requested_filter();
		if ( '' === $filter ) {
			return;
		}

		if ( 'none' === $filter ) {
			$query->set(
				'meta_query',
				array(
					'relation' => 'OR',
					array(
						'key'     => Organization::USER_META,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => Organization::USER_META,
						'value' => '',
					),
				)
			);
			return;
		}

		$query->set(
			'meta_query',
			array(
				array(
					'key'   => Organization::USER_META,
					'value' => (int) $filter,
				),
			)
		);
	}

	/**
	 * Process the bulk action. Core has already verified the bulk-users
	 * nonce and collected the checked user IDs.
	 *
	 * @param string $sendback Redirect URL.
	 * @param string $action   Bulk action name.
	 * @param int[]  $user_ids Checked users.
	 * @return string Redirect URL.
	 */
	public function handle_bulk_action( $sendback, $action, $user_ids ) {
		if ( self::BULK_ACTION !== $action ) {
			return $sendback;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $sendback;
		}

		// Normalize the redirect target and drop anything that could
		// replay the bulk action on refresh.
		if ( ! $sendback ) {
			$sendback = admin_url( 'users.php' );
		}
		$sendback = remove_query_arg(
			array( 'action', 'action2', 'users', '_wpnonce', self::BULK_SELECT, self::BULK_SELECT . '2', self::NOTICE_ARG, self::ERROR_ARG ),
			$sendback
		);

		$target = $this->requested_value( self::BULK_SELECT );
		if ( '' === $target ) {
			return add_query_arg( self::ERROR_ARG, '1', $sendback );
		}

		$organization_id = self::REMOVE_VALUE === $target ? 0 : (int) $target;
		$changed         = $this->apply_bulk( array_map( 'intval', (array) $user_ids ), $organization_id );

		return add_query_arg( self::NOTICE_ARG, $changed, $sendback );
	}

	/**
	 * Assign (or remove, when $organization_id is 0) a set of users.
	 * Returns how many assignments actually changed.
	 *
	 * @param int[] $user_ids        User IDs.
	 * @param int   $organization_id Target organization, 0 to remove.
	 */
	public function apply_bulk( array $user_ids, int $organization_id ): int {
		if ( $organization_id > 0 && ! Organization::find( $organization_id ) ) {
			return 0;
		}

		$changed = 0;
		foreach ( array_unique( array_map( 'intval', $user_ids ) ) as $user_id ) {
			if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
				continue;
			}

			$current    = Organization::for_user( $user_id );
			$current_id = $current ? $current->id() : 0;
			if ( $current_id === $organization_id ) {
				continue;
			}

			Organization::assign_user( $user_id, $organization_id );
			++$changed;
		}

		return $changed;
	}

	/**
	 * Success / error notice after the bulk action.
	 */
	public function render_notice(): void {
		global $pagenow;

		if ( 'users.php' !== $pagenow ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only flags set by our own redirect.
		if ( isset( $_GET[ self::ERROR_ARG ] ) ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html__( 'Choose a target organization (or "No organization") next to the Bulk actions dropdown, then apply again.', 'woo-b2b-pro' )
			);
			return;
		}

		if ( ! isset( $_GET[ self::NOTICE_ARG ] ) ) {
			return;
		}
		$count = (int) $_GET[ self::NOTICE_ARG ];
		// phpcs:enable
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of users whose organization changed. */
					_n( '%d organization membership updated.', '%d organization memberships updated.', $count, 'woo-b2b-pro' ),
					$count
				)
			)
		);
	}

	/**
	 * Current filter value from either tablenav position.
	 */
	private function requested_filter(): string {
		return $this->requested_value( self::FILTER_FIELD );
	}

	/**
	 * First non-empty value of a paired top/bottom control.
	 *
	 * @param string $field Base field name.
	 */
	private function requested_value( string $field ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only lookup; bulk processing happens after core's bulk-users nonce check.
		foreach ( array( $field, $field . '2' ) as $key ) {
			if ( isset( $_GET[ $key ] ) && '' !== $_GET[ $key ] ) {
				return (string) wc_clean( wp_unslash( $_GET[ $key ] ) );
			}
		}
		// phpcs:enable
		return '';
	}
}
