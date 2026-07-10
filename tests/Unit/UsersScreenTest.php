<?php

namespace WooB2B\Tests\Unit;

use Brain\Monkey\Functions;
use WooB2B\Organization\Organization;
use WooB2B\Organization\UsersScreen;
use WooB2B\Tests\TestCase;

class UsersScreenTest extends TestCase {

	/**
	 * Stub organizations 42/43 and users with current assignments.
	 *
	 * @param array $assignments user_id => organization_id (0 = none).
	 */
	private function stub_world( array $assignments ): void {
		Functions\when( 'get_post' )->alias(
			static function ( $id ) {
				if ( ! in_array( (int) $id, array( 42, 43 ), true ) ) {
					return null;
				}
				return (object) array(
					'ID'          => (int) $id,
					'post_type'   => Organization::POST_TYPE,
					'post_status' => 'publish',
				);
			}
		);
		Functions\when( 'get_userdata' )->alias(
			static fn( $id ) => array_key_exists( (int) $id, $assignments ) ? (object) array( 'ID' => (int) $id ) : false
		);
		Functions\when( 'get_user_meta' )->alias(
			static function ( $uid, $key ) use ( $assignments ) {
				if ( Organization::USER_META !== $key ) {
					return '';
				}
				$org = $assignments[ (int) $uid ] ?? 0;
				return $org > 0 ? (string) $org : '';
			}
		);
	}

	public function test_bulk_assign_counts_only_actual_changes(): void {
		// 7 unassigned, 8 already in 42, 9 in 43 (will move).
		$this->stub_world( array( 7 => 0, 8 => 42, 9 => 43 ) );

		$updated = array();
		Functions\when( 'update_user_meta' )->alias(
			static function ( $uid ) use ( &$updated ) {
				$updated[] = (int) $uid;
				return true;
			}
		);

		$changed = ( new UsersScreen() )->apply_bulk( array( 7, 8, 9, 999 ), 42 );

		$this->assertSame( 2, $changed );
		$this->assertSame( array( 7, 9 ), $updated, 'Existing member 8 and unknown user 999 are skipped.' );
	}

	public function test_bulk_remove_unassigns_members(): void {
		$this->stub_world( array( 7 => 42, 8 => 0 ) );

		Functions\expect( 'delete_user_meta' )->once()->with( 7, Organization::USER_META );

		$changed = ( new UsersScreen() )->apply_bulk( array( 7, 8 ), 0 );

		$this->assertSame( 1, $changed, 'Already-unassigned user does not count as a change.' );
	}

	public function test_bulk_assign_to_unknown_organization_is_rejected(): void {
		$this->stub_world( array( 7 => 0 ) );

		Functions\expect( 'update_user_meta' )->never();

		$this->assertSame( 0, ( new UsersScreen() )->apply_bulk( array( 7 ), 999 ) );
	}

	public function test_handle_bulk_action_ignores_other_actions(): void {
		$sendback = 'https://shop.test/wp-admin/users.php';
		$this->assertSame( $sendback, ( new UsersScreen() )->handle_bulk_action( $sendback, 'promote', array( 7 ) ) );
	}

	public function test_handle_bulk_action_flags_missing_target(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'remove_query_arg' )->alias( static fn( $keys, $url ) => $url );
		Functions\when( 'add_query_arg' )->alias(
			static fn( $key, $value, $url ) => $url . '?' . $key . '=' . $value
		);
		$_GET = array();

		$result = ( new UsersScreen() )->handle_bulk_action( 'users.php', UsersScreen::BULK_ACTION, array( 7 ) );

		$this->assertStringContainsString( 'wb2b_org_error=1', $result );
	}

	public function test_handle_bulk_action_assigns_and_reports_count(): void {
		$this->stub_world( array( 7 => 0, 8 => 43 ) );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'update_user_meta' )->justReturn( true );
		Functions\when( 'wc_clean' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'remove_query_arg' )->alias( static fn( $keys, $url ) => $url );
		Functions\when( 'add_query_arg' )->alias(
			static fn( $key, $value, $url ) => $url . '?' . $key . '=' . $value
		);
		$_GET['wb2b_new_org'] = '42';

		$result = ( new UsersScreen() )->handle_bulk_action( 'users.php', UsersScreen::BULK_ACTION, array( 7, 8 ) );

		$this->assertStringContainsString( 'wb2b_org_changed=2', $result );
		unset( $_GET['wb2b_new_org'] );
	}

	public function test_handle_bulk_action_requires_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$_GET['wb2b_new_org'] = '42';

		$sendback = 'users.php';
		$this->assertSame( $sendback, ( new UsersScreen() )->handle_bulk_action( $sendback, UsersScreen::BULK_ACTION, array( 7 ) ) );
		unset( $_GET['wb2b_new_org'] );
	}
}
