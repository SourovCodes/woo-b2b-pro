<?php
/**
 * PHPUnit bootstrap. Tests run against Brain Monkey — no WordPress
 * installation is required.
 *
 * @package WooB2B\Tests
 */

define( 'ABSPATH', sys_get_temp_dir() . '/wordpress/' );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// ---------------------------------------------------------------------------
// Minimal doubles for WordPress/WooCommerce classes referenced by the code
// under test. Brain Monkey covers functions; classes need real definitions.
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WP_HTTP_Response' ) ) {
	class WP_HTTP_Response {
		private $data;

		public function __construct( $data = null ) {
			$this->data = $data;
		}

		public function get_data() {
			return $this->data;
		}

		public function set_data( $data ) {
			$this->data = $data;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $route;

		public function __construct( string $route = '' ) {
			$this->route = $route;
		}

		public function get_route(): string {
			return $this->route;
		}
	}
}

if ( ! class_exists( 'WC_Order' ) ) {
	/**
	 * Recording double: remembers every set_billing_* call.
	 */
	class WC_Order {
		public $billing     = array();
		public $customer_id = 0;

		public function get_customer_id() {
			return $this->customer_id;
		}

		public function get_billing_email() {
			return $this->billing['email'] ?? '';
		}

		public function __call( $name, $args ) {
			if ( 0 === strpos( $name, 'set_billing_' ) ) {
				$this->billing[ substr( $name, strlen( 'set_billing_' ) ) ] = $args[0];
				return;
			}
			throw new \BadMethodCallException( $name );
		}
	}
}
