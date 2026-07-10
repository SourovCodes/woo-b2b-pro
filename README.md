# Woo B2B Pro

B2B / B2Edu toolkit for WooCommerce: hide prices from guests, lock the store behind login, and enforce organization billing addresses for their members.

## Features

- **Hide prices from guests** — replaces price HTML with a customizable login link, makes products non-purchasable, and strips prices from product structured data and Store API (`/wc/store/v1/products`) responses.
- **Require login to browse** — redirects logged-out visitors to the WooCommerce My Account login/registration page and returns them to the originally requested URL after login. The My Account, privacy policy, and terms pages stay public (filterable via `wb2b_login_guard_exempt_pages`).
- **Organizations** — a `wb2b_organization` post type (companies *or* institutions) managed under the WooCommerce admin menu, each with a WooCommerce-style billing address. Customers are assigned from their user profile; a customer belongs to exactly one organization.
- **Enforced organization billing** — members bill to the organization address everywhere:
  - `WC_Customer` billing getters live-read the organization address (no data syncing, no drift).
  - My Account shows the billing address read-only; the billing edit endpoint redirects away and saves are aborted server-side.
  - Checkout replaces the billing form with an address summary; only shipping fields are entered.
  - Order billing is written server-side on both classic checkout and Store API orders.

All features are toggleable under **WooCommerce → Settings → B2B**.

## Requirements

- WordPress 6.5+
- WooCommerce 8.0+
- PHP 7.4+

## Installation

Copy the plugin into `wp-content/plugins/` and activate it. No build step is required — the plugin autoloads its own classes when no Composer autoloader is present.

## Behavior notes

- The customer's billing first/last name stay personal; the organization supplies the company line, address, and (when set) billing email/phone.
- If an organization has no usable address yet, enforcement is skipped so members are not locked out of checkout.
- Deleting or unpublishing an organization automatically releases its members back to their personal billing addresses (lookups validate the organization on every read).

## Hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `wb2b_price_placeholder_html` | filter | Markup rendered in place of a hidden price. |
| `wb2b_login_guard_exempt_pages` | filter | Page IDs reachable without login. |
| `wb2b_login_guard_is_exempt` | filter | Final exemption decision per request. |

## Development

```bash
composer install
composer test        # PHPUnit 10 + Brain Monkey, no WordPress install needed
```

Tests live in `tests/Unit` and run against function mocks, so they are fast and CI-friendly.

## License

GPL-2.0-or-later.
