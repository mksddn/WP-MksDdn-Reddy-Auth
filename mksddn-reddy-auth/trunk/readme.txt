=== MksDdn Reddy Auth ===
Contributors: mksddn
Tags: authentication, otp, rest-api, login
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Authenticate WordPress users via Reddy OTP flow for monolith and REST API clients.

== Description ==

MksDdn Reddy Auth provides OTP-based authentication with:

- WordPress cookie session login for the frontend.
- Optional Bearer token issuing for REST clients.
- Rate limiting and one-time OTP verification.
- Optional site and REST API protection for unauthenticated visitors.

The plugin maps each Reddy ID to a WordPress user and can create an account automatically on first successful login.

== Installation ==

1. Upload the plugin to the `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Open **Settings > Reddy Auth** and configure bot token and security options.
4. Create a login page and add the shortcode `[mksddn_reddy_login]`.
5. If site protection is enabled, select that page in the **Login page** setting.

== Getting Started ==

= 1. Configure the Reddy bot token =

For production, define the token in `wp-config.php`:

`define( 'MKSDDN_REDDY_BOT_TOKEN', 'your-bot-token' );`

For local development you can store the token in **Settings > Reddy Auth** instead. Use **Bot connection test** to verify delivery to a Reddy user.

= 2. Set up the login page =

Create a WordPress page (for example, `/login/`) and insert:

`[mksddn_reddy_login]`

Users enter their Reddy ID, receive a one-time code in Reddy, and sign in through the form.

= 3. Review protection settings =

By default, both options are enabled:

* **Protect site content** — redirects unauthenticated visitors to the login page.
* **Protect all REST API content** — returns `401` for protected REST routes.

Public auth routes remain available without login:

* `POST /wp-json/mksddn-reddy-auth/v1/auth/send-code`
* `POST /wp-json/mksddn-reddy-auth/v1/auth/login`

= 4. Use REST API for headless clients =

Typical flow:

1. `POST /auth/send-code` with `{ "reddy_id": "123456" }`
2. `POST /auth/login` with `{ "reddy_id": "123456", "code": "111111", "issue_token": true }`
3. Call protected routes with `Authorization: Bearer <token>` or use the WordPress cookie session.
4. `GET /auth/me` to read the current user.
5. `POST /auth/logout` to end the session and revoke the Bearer token.

Download OpenAPI and Postman files from **Settings > Reddy Auth > Developer Resources**.

== Frequently Asked Questions ==

= Where should I store the bot token? =

Use the `MKSDDN_REDDY_BOT_TOKEN` constant in `wp-config.php` on production sites. The settings page field is a development fallback and should not replace secure server-side configuration in live environments.

= Why am I stuck in a redirect loop? =

This usually happens when **Protect site content** is enabled but no valid login page is selected. Create a page with `[mksddn_reddy_login]`, choose it in **Login page**, and save settings.

= Does this plugin replace `/wp-login.php`? =

No. It adds a Reddy OTP login flow via shortcode and REST endpoints. Standard WordPress login may still be available unless you restrict it separately.

= Are WordPress users created automatically? =

Yes. On first successful OTP login the plugin creates a WordPress user mapped to the Reddy ID and stores the mapping in user meta.

= How do REST clients authenticate? =

Send `issue_token: true` in the login request, then pass the returned token in the `Authorization: Bearer` header. Cookie-based WordPress sessions also work for browser clients.

= Why do I get HTTP 429? =

The plugin rate-limits OTP send and login attempts per Reddy ID and client IP. Wait for the limit window to expire or adjust limits in **Settings > Reddy Auth**.

= Which data does the plugin store? =

Settings in WordPress options, Reddy ID mapping in user meta, Bearer token hashes in a custom database table, and OTP/rate-limit state in transients. Raw OTP codes and raw tokens are not stored.

= What happens on uninstall? =

If uninstall cleanup runs, plugin-owned options, user meta, custom tables, and transients are removed according to `uninstall.php`.

== Changelog ==

= 0.1.1 =
* Updated plugin metadata (GitHub URIs, license, WordPress and PHP requirements).
* Tested up to WordPress 6.9.
* Hardened REST middleware: sanitize request URI before route checks.
* Clearer rate limit labels and field descriptions in settings.
* Improved uninstall cleanup and WPCS compliance across core files.
* Removed redundant textdomain loader (WordPress auto-loads plugin translations).

= 0.1.0 =
* Initial MVP release.
