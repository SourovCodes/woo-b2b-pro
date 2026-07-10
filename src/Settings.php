<?php
/**
 * WooCommerce settings tab.
 *
 * @package WooB2B
 */

namespace WooB2B;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a "B2B" tab under WooCommerce → Settings and exposes typed access
 * to the plugin options.
 */
class Settings {

	public const TAB_ID = 'wb2b';

	public const OPTION_HIDE_PRICES       = 'wb2b_hide_prices';
	public const OPTION_PRICE_PLACEHOLDER = 'wb2b_price_placeholder';
	public const OPTION_FORCE_LOGIN       = 'wb2b_force_login';
	public const OPTION_ORGANIZATION_BILLING   = 'wb2b_organization_billing';
	public const OPTION_REQUIRE_ORGANIZATION   = 'wb2b_require_organization';
	public const OPTION_REMOVE_DATA       = 'wb2b_remove_data_on_uninstall';

	/**
	 * Option defaults, used both by getters and by the settings screen.
	 *
	 * @var array<string,string>
	 */
	private const DEFAULTS = array(
		self::OPTION_HIDE_PRICES       => 'no',
		self::OPTION_PRICE_PLACEHOLDER => '',
		self::OPTION_FORCE_LOGIN      => 'no',
		self::OPTION_ORGANIZATION_BILLING  => 'yes',
		self::OPTION_REQUIRE_ORGANIZATION  => 'no',
		self::OPTION_REMOVE_DATA      => 'no',
	);

	/**
	 * Hook the tab into WooCommerce settings.
	 */
	public function register(): void {
		add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_tab' ), 50 );
		add_action( 'woocommerce_settings_' . self::TAB_ID, array( $this, 'render' ) );
		add_action( 'woocommerce_update_options_' . self::TAB_ID, array( $this, 'save' ) );
	}

	/**
	 * Whether a yes/no option is enabled.
	 *
	 * @param string $option Option name (one of the OPTION_* constants).
	 */
	public static function enabled( string $option ): bool {
		$default = self::DEFAULTS[ $option ] ?? 'no';
		return 'yes' === get_option( $option, $default );
	}

	/**
	 * Placeholder text shown in place of prices for guests.
	 */
	public static function price_placeholder(): string {
		$text = (string) get_option( self::OPTION_PRICE_PLACEHOLDER, '' );
		if ( '' === trim( $text ) ) {
			$text = __( 'Sign in to see prices', 'woo-b2b-pro' );
		}
		return $text;
	}

	/**
	 * Add the tab label.
	 *
	 * @param array $tabs Existing tabs.
	 * @return array
	 */
	public function add_tab( array $tabs ): array {
		$tabs[ self::TAB_ID ] = __( 'B2B', 'woo-b2b-pro' );
		return $tabs;
	}

	/**
	 * Settings field definitions consumed by woocommerce_admin_fields().
	 *
	 * @return array[]
	 */
	public function get_fields(): array {
		return array(
			array(
				'title' => __( 'Store access', 'woo-b2b-pro' ),
				'type'  => 'title',
				'desc'  => __( 'Control what guests can see before they log in.', 'woo-b2b-pro' ),
				'id'    => 'wb2b_access_options',
			),
			array(
				'title'   => __( 'Hide prices from guests', 'woo-b2b-pro' ),
				'desc'    => __( 'Replace product prices with a login prompt and prevent purchasing until the visitor logs in.', 'woo-b2b-pro' ),
				'id'      => self::OPTION_HIDE_PRICES,
				'type'    => 'checkbox',
				'default' => self::DEFAULTS[ self::OPTION_HIDE_PRICES ],
			),
			array(
				'title'       => __( 'Hidden price text', 'woo-b2b-pro' ),
				'desc'        => __( 'Shown in place of the price. Links to the login page.', 'woo-b2b-pro' ),
				'id'          => self::OPTION_PRICE_PLACEHOLDER,
				'type'        => 'text',
				'placeholder' => __( 'Sign in to see prices', 'woo-b2b-pro' ),
				'default'     => self::DEFAULTS[ self::OPTION_PRICE_PLACEHOLDER ],
				'desc_tip'    => true,
			),
			array(
				'title'   => __( 'Require login to browse', 'woo-b2b-pro' ),
				'desc'    => __( 'Redirect all visitors to the My Account login/registration page until they log in. The privacy policy and terms pages stay public.', 'woo-b2b-pro' ),
				'id'      => self::OPTION_FORCE_LOGIN,
				'type'    => 'checkbox',
				'default' => self::DEFAULTS[ self::OPTION_FORCE_LOGIN ],
			),
			array(
				'type' => 'sectionend',
				'id'   => 'wb2b_access_options',
			),
			array(
				'title' => __( 'Organizations', 'woo-b2b-pro' ),
				'type'  => 'title',
				'desc'  => __( 'Customers assigned to an organization bill to its address.', 'woo-b2b-pro' ),
				'id'    => 'wb2b_organization_options',
			),
			array(
				'title'   => __( 'Enforce organization billing address', 'woo-b2b-pro' ),
				'desc'    => __( 'Members of an organization use its billing address everywhere; they cannot edit it and the checkout billing form is replaced by a summary.', 'woo-b2b-pro' ),
				'id'      => self::OPTION_ORGANIZATION_BILLING,
				'type'    => 'checkbox',
				'default' => self::DEFAULTS[ self::OPTION_ORGANIZATION_BILLING ],
			),
			array(
				'title'   => __( 'Require organization membership to order', 'woo-b2b-pro' ),
				'desc'    => __( 'Only customers assigned to an organization can add products to the cart and check out. Guests and unassigned accounts see an explanatory notice instead of the purchase button.', 'woo-b2b-pro' ),
				'id'      => self::OPTION_REQUIRE_ORGANIZATION,
				'type'    => 'checkbox',
				'default' => self::DEFAULTS[ self::OPTION_REQUIRE_ORGANIZATION ],
			),
			array(
				'type' => 'sectionend',
				'id'   => 'wb2b_organization_options',
			),
			array(
				'title' => __( 'Housekeeping', 'woo-b2b-pro' ),
				'type'  => 'title',
				'id'    => 'wb2b_housekeeping_options',
			),
			array(
				'title'   => __( 'Remove data on uninstall', 'woo-b2b-pro' ),
				'desc'    => __( 'Delete organizations, member assignments and plugin settings when the plugin is deleted.', 'woo-b2b-pro' ),
				'id'      => self::OPTION_REMOVE_DATA,
				'type'    => 'checkbox',
				'default' => self::DEFAULTS[ self::OPTION_REMOVE_DATA ],
			),
			array(
				'type' => 'sectionend',
				'id'   => 'wb2b_housekeeping_options',
			),
		);
	}

	/**
	 * Render the tab.
	 */
	public function render(): void {
		woocommerce_admin_fields( $this->get_fields() );
	}

	/**
	 * Persist the tab.
	 */
	public function save(): void {
		woocommerce_update_options( $this->get_fields() );
	}
}
