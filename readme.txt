=== Woo B2B Pro ===
Contributors: sourovcodes
Tags: woocommerce, b2b, hide prices, private store, organization
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

B2B / B2Edu toolkit for WooCommerce: hide prices from guests, lock the store behind login, and enforce organization billing addresses.

== Description ==

Woo B2B Pro turns a WooCommerce store into a B2B or B2Edu (business/education) storefront:

* **Hide prices from guests** — product prices are replaced with a customizable "Sign in to see prices" link and products cannot be purchased until the visitor logs in. Prices are also stripped from product structured data and Store API responses so nothing leaks to search engines or headless clients.
* **Require login to browse** — every storefront page redirects logged-out visitors to the WooCommerce My Account login/registration page. After logging in the visitor lands back on the page they originally requested. The privacy policy and terms pages remain public.
* **Organizations** — create organizations (companies or educational institutions) under WooCommerce → Organizations and give each one a billing address with country-aware state/district fields. Add members right on the organization screen via customer search (or from the user profile); a customer belongs to exactly one organization, so adding someone who belongs to another organization moves them.
* **Require organization membership to order** (optional) — guests and accounts without an organization cannot add products to the cart or check out; an explanatory notice is shown instead of the purchase button.
* **Enforced organization billing** — members of an organization always bill to the organization address:
    * My Account shows the organization billing address read-only; the edit form is blocked server-side.
    * Checkout hides the billing form entirely and shows a summary of the organization billing address instead. The member only enters a shipping address.
    * The billing address is written onto every order server-side, on both the classic checkout and the Store API, so it cannot be spoofed by manipulating the request.

Every feature is toggleable under **WooCommerce → Settings → B2B**.

== Frequently Asked Questions ==

= Does the customer's name change on invoices? =

No. The billing first/last name stay personal to the customer; the organization supplies the company line, address, and (when set) the billing email and phone.

= What happens if an organization has no billing address yet? =

Enforcement is skipped for that organization until a usable address (street, city, postcode, or country) is saved, so members are never locked out of checkout with an empty address.

= Does it work with the block (Store API) checkout? =

The billing address is enforced server-side on Store API orders too. The tailored checkout UI (hidden billing form with an address summary) targets the classic shortcode checkout.

= Is any data removed on uninstall? =

Only if you enable "Remove data on uninstall" in the settings. Otherwise organizations, assignments, and settings are preserved.

== Changelog ==

= 1.0.0 =
* Initial release: guest price hiding, forced login, organizations with enforced billing addresses, optional members-only ordering.
