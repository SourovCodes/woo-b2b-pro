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
	 * Recording double: remembers every set_billing_* call, status updates
	 * and whether payment_complete() was ever invoked.
	 */
	class WC_Order {
		public $billing        = array();
		public $customer_id    = 0;
		public $id             = 0;
		public $payment_method = '';
		public $status_updates = array();
		public $paid           = false;
		public $date_paid      = null;

		public function get_id() {
			return $this->id;
		}

		public function get_customer_id() {
			return $this->customer_id;
		}

		public function get_billing_email() {
			return $this->billing['email'] ?? '';
		}

		public function get_payment_method() {
			return $this->payment_method;
		}

		public function get_date_paid( $context = 'view' ) {
			return $this->date_paid;
		}

		public function update_status( $status, $note = '' ) {
			$this->status_updates[] = array( $status, $note );
			return true;
		}

		public function payment_complete( $transaction_id = '' ) {
			$this->paid = true;
			return true;
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

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	/**
	 * Minimal stand-in mirroring the pieces of WC_Payment_Gateway the
	 * plugin relies on: option access with form-field defaults and the
	 * enabled check in is_available().
	 */
	class WC_Payment_Gateway {
		public $id                 = '';
		public $icon               = '';
		public $has_fields         = false;
		public $title              = '';
		public $description        = '';
		public $method_title       = '';
		public $method_description = '';
		public $enabled            = 'yes';
		public $supports           = array( 'products' );
		public $form_fields        = array();
		public $settings           = array();

		public function init_form_fields() {}

		public function init_settings() {
			$this->enabled = $this->get_option( 'enabled', 'yes' );
		}

		public function get_option( $key, $empty_value = null ) {
			if ( array_key_exists( $key, $this->settings ) ) {
				return $this->settings[ $key ];
			}
			return $this->form_fields[ $key ]['default'] ?? $empty_value;
		}

		public function is_available() {
			return 'yes' === $this->enabled;
		}

		public function supports( $feature ) {
			return in_array( $feature, $this->supports, true );
		}

		public function get_return_url( $order = null ) {
			return 'https://example.test/order-received';
		}

		public function process_admin_options() {}
	}
}
