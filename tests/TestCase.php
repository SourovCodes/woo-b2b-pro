<?php
/**
 * Base test case wiring Brain Monkey.
 *
 * @package WooB2B\Tests
 */

namespace WooB2B\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WooB2B\Organization\Organization;

abstract class TestCase extends \PHPUnit\Framework\TestCase {

	use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
	}

	protected function tearDown(): void {
		Organization::flush_cache();
		$_REQUEST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stub get_option with a fixed option map; unknown options fall back
	 * to the default passed by the caller.
	 *
	 * @param array $options option => value.
	 */
	protected function stub_options( array $options ): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( $options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
			}
		);
	}

	/**
	 * Stub a user assigned to a published company with the given address
	 * meta (keys without the _wb2b_billing_ prefix).
	 *
	 * @param int    $user_id    User ID.
	 * @param int    $organization_id Organization post ID.
	 * @param array  $address    Address meta values.
	 * @param string $name       Organization title.
	 */
	protected function stub_organization_user( int $user_id, int $organization_id, array $address, string $name = 'Acme Corp' ): void {
		Functions\when( 'get_user_meta' )->alias(
			static function ( $uid, $key ) use ( $user_id, $organization_id ) {
				if ( (int) $uid === $user_id && Organization::USER_META === $key ) {
					return (string) $organization_id;
				}
				return '';
			}
		);
		Functions\when( 'get_post' )->alias(
			static function ( $id ) use ( $organization_id ) {
				if ( (int) $id !== $organization_id ) {
					return null;
				}
				return (object) array(
					'ID'          => $organization_id,
					'post_type'   => Organization::POST_TYPE,
					'post_status' => 'publish',
				);
			}
		);
		Functions\when( 'get_the_title' )->justReturn( $name );
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id, $key ) use ( $organization_id, $address ) {
				if ( (int) $id !== $organization_id || 0 !== strpos( (string) $key, Organization::META_PREFIX ) ) {
					return '';
				}
				$short = substr( $key, strlen( Organization::META_PREFIX ) );
				return $address[ $short ] ?? '';
			}
		);
	}
}
