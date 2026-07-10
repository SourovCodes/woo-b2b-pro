<?php

namespace WooB2B\Tests\Unit;

use Brain\Monkey\Functions;
use WooB2B\Organization\MembersMetabox;
use WooB2B\Organization\Organization;
use WooB2B\Tests\TestCase;

class MembersMetaboxTest extends TestCase {

	/**
	 * Stub two published organizations (42 and 43) and a set of existing
	 * users with their current organization assignment.
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

	public function test_adds_users_and_moves_members_of_other_organizations(): void {
		// User 7 is unassigned, user 8 belongs to organization 43.
		$this->stub_world( array( 7 => 0, 8 => 43 ) );

		$updates = array();
		Functions\when( 'update_user_meta' )->alias(
			static function ( $uid, $key, $value ) use ( &$updates ) {
				$updates[ (int) $uid ] = array( $key, (int) $value );
				return true;
			}
		);
		Functions\expect( 'delete_user_meta' )->never();

		( new MembersMetabox() )->apply_changes( 42, array( 7, 8 ), array() );

		$this->assertSame( array( Organization::USER_META, 42 ), $updates[7] );
		$this->assertSame( array( Organization::USER_META, 42 ), $updates[8], 'User in another organization is moved.' );
	}

	public function test_unknown_users_and_duplicates_are_skipped(): void {
		$this->stub_world( array( 7 => 0 ) );

		Functions\expect( 'update_user_meta' )->once()->with( 7, Organization::USER_META, 42 );

		( new MembersMetabox() )->apply_changes( 42, array( 7, 7, 999, 0, -3 ), array() );
	}

	public function test_removal_only_applies_to_current_members(): void {
		// User 7 is a member of 42; user 8 belongs to 43.
		$this->stub_world( array( 7 => 42, 8 => 43 ) );

		Functions\expect( 'delete_user_meta' )->once()->with( 7, Organization::USER_META );
		Functions\expect( 'update_user_meta' )->never();

		( new MembersMetabox() )->apply_changes( 42, array(), array( 7, 8 ) );
	}

	public function test_nothing_happens_for_unknown_organization(): void {
		$this->stub_world( array( 7 => 0 ) );

		Functions\expect( 'update_user_meta' )->never();
		Functions\expect( 'delete_user_meta' )->never();

		( new MembersMetabox() )->apply_changes( 999, array( 7 ), array( 7 ) );
	}
}
