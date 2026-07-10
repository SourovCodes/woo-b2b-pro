<?php
/**
 * Lock the storefront behind the WooCommerce login page.
 *
 * @package WooB2B
 */

namespace WooB2B;

defined( 'ABSPATH' ) || exit;

/**
 * Redirects logged-out visitors to the My Account login/registration page.
 * The My Account page itself (including its endpoints such as
 * lost-password), the privacy policy page and the terms page stay
 * reachable so visitors can actually log in and read legal documents.
 */
class LoginGuard {

	/**
	 * Query arg carrying the originally requested URL through login.
	 */
	public const REDIRECT_PARAM = 'wb2b_redirect';

	/**
	 * Hook everything.
	 */
	public function register(): void {
		// Priority 20: after WC AJAX (0) and WC form handlers (10) so
		// login/lost-password POSTs are processed before we redirect.
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 20 );
		add_filter( 'woocommerce_login_redirect', array( $this, 'filter_login_redirect' ), 10 );
	}

	/**
	 * Redirect the visitor when the guard applies.
	 */
	public function maybe_redirect(): void {
		$target = $this->redirect_url();
		if ( null === $target ) {
			return;
		}
		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Compute the login URL to redirect to, or null when no redirect
	 * should happen. Split from maybe_redirect() for testability.
	 */
	public function redirect_url(): ?string {
		if ( ! Settings::enabled( Settings::OPTION_FORCE_LOGIN ) || is_user_logged_in() ) {
			return null;
		}

		// Never interfere with machine endpoints.
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || wp_is_serving_rest_request() ) {
			return null;
		}

		if ( function_exists( 'is_robots' ) && is_robots() ) {
			return null;
		}
		if ( function_exists( 'is_favicon' ) && is_favicon() ) {
			return null;
		}

		$account_page_id = (int) wc_get_page_id( 'myaccount' );
		if ( $account_page_id <= 0 ) {
			// Fail open: without a login page a redirect would 404 or loop.
			return null;
		}

		if ( $this->is_exempt( $account_page_id ) ) {
			return null;
		}

		$login_url = wc_get_page_permalink( 'myaccount' );
		if ( ! $login_url ) {
			return null;
		}

		$requested = $this->current_url();
		if ( '' !== $requested ) {
			$login_url = add_query_arg( self::REDIRECT_PARAM, rawurlencode( $requested ), $login_url );
		}

		return $login_url;
	}

	/**
	 * After a successful login, honor the URL the visitor originally
	 * requested (validated against local hosts).
	 *
	 * @param string $redirect Default redirect.
	 * @return string
	 */
	public function filter_login_redirect( $redirect ) {
		// The login form posts back to the URL it was rendered on, so the
		// query arg added by redirect_url() is present in the request.
		if ( empty( $_REQUEST[ self::REDIRECT_PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect hint, validated below.
			return $redirect;
		}
		$requested = rawurldecode( wp_unslash( $_REQUEST[ self::REDIRECT_PARAM ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by wp_validate_redirect().
		$validated = wp_validate_redirect( $requested, '' );

		return '' !== $validated ? $validated : $redirect;
	}

	/**
	 * Whether the current request targets a page that must remain public.
	 *
	 * @param int $account_page_id My Account page ID.
	 */
	private function is_exempt( int $account_page_id ): bool {
		$exempt_ids = array( $account_page_id );

		$privacy_page_id = (int) get_option( 'wp_page_for_privacy_policy', 0 );
		if ( $privacy_page_id > 0 ) {
			$exempt_ids[] = $privacy_page_id;
		}

		if ( function_exists( 'wc_terms_and_conditions_page_id' ) ) {
			$terms_page_id = (int) wc_terms_and_conditions_page_id();
			if ( $terms_page_id > 0 ) {
				$exempt_ids[] = $terms_page_id;
			}
		}

		/**
		 * Filter the page IDs that stay reachable while the store is
		 * locked behind login.
		 *
		 * @param int[] $exempt_ids Page IDs.
		 */
		$exempt_ids = apply_filters( 'wb2b_login_guard_exempt_pages', $exempt_ids );

		$exempt = is_page( $exempt_ids );

		/**
		 * Filter the final exemption decision for the current request.
		 *
		 * @param bool $exempt Whether the request is exempt from the login redirect.
		 */
		return (bool) apply_filters( 'wb2b_login_guard_is_exempt', $exempt );
	}

	/**
	 * Best-effort absolute URL of the current request, for post-login redirect.
	 */
	private function current_url(): string {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}
		return home_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated via wp_validate_redirect() before use.
	}
}
