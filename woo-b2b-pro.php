<?php
/**
 * Plugin Name:          Woo B2B Pro
 * Plugin URI:           https://github.com/sourovcodes/woo-b2b-pro
 * Description:          B2B / B2Edu toolkit for WooCommerce: hide prices from guests, lock the store behind login, and manage organizations (companies or institutions) whose billing address is enforced for their members.
 * Version:              1.0.0
 * Author:               Sourov
 * Author URI:           https://github.com/sourovcodes
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          woo-b2b-pro
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.0
 * WC tested up to:      10.9
 *
 * @package WooB2B
 */

defined( 'ABSPATH' ) || exit;

define( 'WB2B_VERSION', '1.0.0' );
define( 'WB2B_PLUGIN_FILE', __FILE__ );
define( 'WB2B_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Composer autoloader when developing; lightweight PSR-4 fallback for production installs.
if ( file_exists( WB2B_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require WB2B_PLUGIN_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( $class ) {
			if ( 0 !== strpos( $class, 'WooB2B\\' ) ) {
				return;
			}
			$relative = substr( $class, strlen( 'WooB2B\\' ) );
			$path     = WB2B_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( file_exists( $path ) ) {
				require $path;
			}
		}
	);
}

// Declare compatibility with WooCommerce features (HPOS, cart/checkout blocks).
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>';
					esc_html_e( 'Woo B2B Pro requires WooCommerce to be installed and active.', 'woo-b2b-pro' );
					echo '</p></div>';
				}
			);
			return;
		}

		\WooB2B\Plugin::instance()->register();
	}
);
