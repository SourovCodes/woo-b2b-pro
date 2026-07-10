<?php

namespace WooB2B\Tests\Unit;

use WooB2B\Settings;
use WooB2B\Tests\TestCase;

class SettingsTest extends TestCase {

	public function test_enabled_returns_true_for_yes(): void {
		$this->stub_options( array( Settings::OPTION_HIDE_PRICES => 'yes' ) );
		$this->assertTrue( Settings::enabled( Settings::OPTION_HIDE_PRICES ) );
	}

	public function test_enabled_returns_false_for_no(): void {
		$this->stub_options( array( Settings::OPTION_HIDE_PRICES => 'no' ) );
		$this->assertFalse( Settings::enabled( Settings::OPTION_HIDE_PRICES ) );
	}

	public function test_company_billing_defaults_to_enabled(): void {
		$this->stub_options( array() ); // Option never saved.
		$this->assertTrue( Settings::enabled( Settings::OPTION_ORGANIZATION_BILLING ) );
	}

	public function test_hide_prices_defaults_to_disabled(): void {
		$this->stub_options( array() );
		$this->assertFalse( Settings::enabled( Settings::OPTION_HIDE_PRICES ) );
	}

	public function test_price_placeholder_falls_back_to_default_text(): void {
		$this->stub_options( array( Settings::OPTION_PRICE_PLACEHOLDER => '   ' ) );
		$this->assertSame( 'Sign in to see prices', Settings::price_placeholder() );
	}

	public function test_price_placeholder_uses_saved_text(): void {
		$this->stub_options( array( Settings::OPTION_PRICE_PLACEHOLDER => 'Members only' ) );
		$this->assertSame( 'Members only', Settings::price_placeholder() );
	}
}
