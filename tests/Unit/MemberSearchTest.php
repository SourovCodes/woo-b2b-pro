<?php

namespace WooB2B\Tests\Unit;

use Brain\Monkey\Functions;
use WooB2B\Organization\MemberSearch;
use WooB2B\Tests\TestCase;

class MemberSearchTest extends TestCase {

	private function user( int $id, string $name, string $email ): object {
		return (object) array(
			'ID'           => $id,
			'display_name' => $name,
			'user_email'   => $email,
		);
	}

	public function test_label_without_organization(): void {
		Functions\when( 'get_user_meta' )->justReturn( '' );

		$label = ( new MemberSearch() )->label( $this->user( 7, 'Jane Doe', 'jane@test.dev' ), 42 );

		$this->assertSame( 'Jane Doe (#7 – jane@test.dev)', $label );
	}

	public function test_label_marks_members_of_other_organizations(): void {
		$this->stub_organization_user( 7, 43, array( 'city' => 'X' ), 'Other Corp' );

		$label = ( new MemberSearch() )->label( $this->user( 7, 'Jane Doe', 'jane@test.dev' ), 42 );

		$this->assertStringContainsString( MemberSearch::move_marker() . 'Other Corp', $label );
	}

	public function test_label_marks_existing_members_of_current_organization(): void {
		$this->stub_organization_user( 7, 42, array( 'city' => 'X' ), 'This Corp' );

		$label = ( new MemberSearch() )->label( $this->user( 7, 'Jane Doe', 'jane@test.dev' ), 42 );

		$this->assertStringContainsString( 'already a member', $label );
		$this->assertStringNotContainsString( MemberSearch::move_marker(), $label );
	}
}
