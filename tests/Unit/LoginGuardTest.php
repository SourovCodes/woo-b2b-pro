<?php

namespace WooB2B\Tests\Unit;

use Brain\Monkey\Functions;
use WooB2B\LoginGuard;
use WooB2B\Settings;
use WooB2B\Tests\TestCase;

class LoginGuardTest extends TestCase {

	private function stub_frontend_request( array $overrides = array() ): void {
		$defaults = array(
			'is_user_logged_in'           => false,
			'is_admin'                    => false,
			'wp_doing_ajax'               => false,
			'wp_doing_cron'               => false,
			'wp_is_serving_rest_request'  => false,
			'is_robots'                   => false,
			'is_favicon'                  => false,
			'is_page'                     => false,
			'wc_get_page_id'              => 8,
			'wc_terms_and_conditions_page_id' => 0,
		);
		foreach ( array_merge( $defaults, $overrides ) as $function => $value ) {
			Functions\when( $function )->justReturn( $value );
		}

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wc_get_page_permalink' )->justReturn( 'https://shop.test/my-account/' );
		Functions\when( 'home_url' )->alias(
			static fn( $path = '' ) => 'https://shop.test' . $path
		);
		Functions\when( 'add_query_arg' )->alias(
			static fn( $key, $value, $url ) => $url . '?' . $key . '=' . $value
		);
		$_SERVER['REQUEST_URI'] = '/shop/widgets/';
	}

	public function test_guest_is_redirected_to_login_with_return_url(): void {
		$this->stub_options( array( Settings::OPTION_FORCE_LOGIN => 'yes' ) );
		$this->stub_frontend_request();

		$url = ( new LoginGuard() )->redirect_url();

		$this->assertNotNull( $url );
		$this->assertStringStartsWith( 'https://shop.test/my-account/', $url );
		$this->assertStringContainsString( LoginGuard::REDIRECT_PARAM, $url );
		$this->assertStringContainsString( rawurlencode( 'https://shop.test/shop/widgets/' ), $url );
	}

	public function test_no_redirect_when_disabled(): void {
		$this->stub_options( array( Settings::OPTION_FORCE_LOGIN => 'no' ) );
		$this->stub_frontend_request();

		$this->assertNull( ( new LoginGuard() )->redirect_url() );
	}

	public function test_no_redirect_for_logged_in_users(): void {
		$this->stub_options( array( Settings::OPTION_FORCE_LOGIN => 'yes' ) );
		$this->stub_frontend_request( array( 'is_user_logged_in' => true ) );

		$this->assertNull( ( new LoginGuard() )->redirect_url() );
	}

	public function test_no_redirect_on_exempt_pages(): void {
		$this->stub_options( array( Settings::OPTION_FORCE_LOGIN => 'yes' ) );
		$this->stub_frontend_request( array( 'is_page' => true ) );

		$this->assertNull( ( new LoginGuard() )->redirect_url() );
	}

	public function test_fails_open_when_no_account_page_configured(): void {
		$this->stub_options( array( Settings::OPTION_FORCE_LOGIN => 'yes' ) );
		$this->stub_frontend_request( array( 'wc_get_page_id' => -1 ) );

		$this->assertNull( ( new LoginGuard() )->redirect_url() );
	}

	public function test_no_redirect_for_rest_requests(): void {
		$this->stub_options( array( Settings::OPTION_FORCE_LOGIN => 'yes' ) );
		$this->stub_frontend_request( array( 'wp_is_serving_rest_request' => true ) );

		$this->assertNull( ( new LoginGuard() )->redirect_url() );
	}

	public function test_login_redirect_honors_validated_return_url(): void {
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_validate_redirect' )->alias(
			static fn( $url, $fallback ) => 0 === strpos( $url, 'https://shop.test/' ) ? $url : $fallback
		);
		$_REQUEST[ LoginGuard::REDIRECT_PARAM ] = rawurlencode( 'https://shop.test/shop/widgets/' );

		$redirect = ( new LoginGuard() )->filter_login_redirect( 'https://shop.test/my-account/' );

		$this->assertSame( 'https://shop.test/shop/widgets/', $redirect );
	}

	public function test_login_redirect_rejects_foreign_hosts(): void {
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_validate_redirect' )->alias(
			static fn( $url, $fallback ) => 0 === strpos( $url, 'https://shop.test/' ) ? $url : $fallback
		);
		$_REQUEST[ LoginGuard::REDIRECT_PARAM ] = rawurlencode( 'https://evil.test/phish' );

		$redirect = ( new LoginGuard() )->filter_login_redirect( 'https://shop.test/my-account/' );

		$this->assertSame( 'https://shop.test/my-account/', $redirect );
	}

	public function test_login_redirect_untouched_without_param(): void {
		$redirect = ( new LoginGuard() )->filter_login_redirect( 'https://shop.test/my-account/' );
		$this->assertSame( 'https://shop.test/my-account/', $redirect );
	}
}
