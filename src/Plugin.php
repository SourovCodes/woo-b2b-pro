<?php
/**
 * Main plugin orchestrator.
 *
 * @package WooB2B
 */

namespace WooB2B;

use WooB2B\Organization\AddressMetabox;
use WooB2B\Organization\PostType;
use WooB2B\Organization\UserProfile;
use WooB2B\Customer\BillingLock;
use WooB2B\Customer\CheckoutGuard;
use WooB2B\Customer\OrderGate;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every feature into WordPress. Instantiated once on plugins_loaded
 * after the WooCommerce dependency check has passed.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Retrieve (and lazily create) the shared instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register all feature hooks.
	 */
	public function register(): void {
		( new Settings() )->register();
		( new PriceGuard() )->register();
		( new LoginGuard() )->register();
		( new PostType() )->register();
		( new AddressMetabox() )->register();
		( new UserProfile() )->register();
		( new BillingLock() )->register();
		( new CheckoutGuard() )->register();
		( new OrderGate() )->register();
	}
}
