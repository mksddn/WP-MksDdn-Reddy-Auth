=== MksDdn Reddy Auth ===
Contributors: mksddn
Tags: authentication, otp, rest-api, login
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Authenticate WordPress users via Reddy OTP flow for monolith and REST API clients.

== Description ==

MksDdn Reddy Auth provides OTP-based authentication with:

- WordPress cookie session login for the frontend.
- Optional Bearer token issuing for REST clients.
- Rate limiting and one-time OTP verification.
- Optional site and REST API protection for unauthenticated visitors.
- Optional allowed request sources (Origin/Referer) for plugin REST endpoints.

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

By default, both protection options are **disabled** so your site stays accessible after activation. Enable them only after the login page is configured:

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

= 5. Optional: restrict REST callers by browser source =

In **Settings > Reddy Auth**, **Allowed request sources** limits plugin REST traffic (`/mksddn-reddy-auth/v1/*`) to listed `Origin` or `Referer` URLs. Leave empty to allow any client (recommended for server-to-server integrations). This is a soft guard for browser apps, not a secret key.

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

= What does Allowed request sources do? =

It optionally checks `Origin` or `Referer` on plugin REST routes only. Empty list = no restriction (default). Non-empty list = browser apps must call from a listed URL. It does not replace OTP, rate limits, or Bearer auth—headers can be spoofed.

= Why do I get HTTP 403 with "Request not allowed from this source"? =

**Allowed request sources** is configured and the request has no matching `Origin`/`Referer`. Add your frontend URL to the list, send a matching `Origin` header from server clients, leave the list empty for backends, or use the `mksddn_reddy_is_request_url_allowed` filter.

= Which data does the plugin store? =

Settings in WordPress options, Reddy ID mapping in user meta, Bearer token hashes in a custom database table, and OTP/rate-limit state in transients. Raw OTP codes and raw tokens are not stored.

= What happens on uninstall? =

If uninstall cleanup runs, plugin-owned options, user meta, custom tables, and transients are removed according to `uninstall.php`.

== External services ==

This plugin connects to the **Reddy bot API** at `https://bot.reddy.team` to deliver one-time passwords and optional admin connection test messages.

**What the service is used for**

* Deliver OTP codes to a Reddy user during login.
* Send an optional admin "bot connection test" message from **Settings > Reddy Auth**.

**What data is sent and when**

* **OTP send / login:** Reddy user ID (`userKey`) and message text containing the one-time code (and expiry hint). Sent when a user requests a code via the login form or REST API.
* **Bot connection test:** Reddy user ID (`userKey`) and a fixed test message. Sent only when an administrator runs **Bot connection test** in **Settings > Reddy Auth**.
* **Bot token:** Your bot token is included in the API request URL path (configured via `MKSDDN_REDDY_BOT_TOKEN` in `wp-config.php` or the development fallback field in settings). It is not sent to WordPress.org.

Data is transmitted only when OTP delivery or the connection test is triggered. The plugin does not send site content, post data, or WordPress user passwords to Reddy.

This service is provided by Reddy: terms of use and privacy policy at https://help.reddy.team/pages/user-agreement

No other third-party services are required for core plugin operation.

== Changelog ==

= 0.1.2 =
* Direct Reddy terms of use and privacy policy links in External services readme section.
* Require cookie session or Bearer token authentication for POST `/auth/logout` REST endpoint.

= 0.1.1 =
* **External services** disclosure in readme for Reddy bot API (OTP delivery).
* Safer defaults: site and REST protection disabled until explicitly enabled in settings.
* Monolith content lock skipped until a login page or shortcode page is configured.
* Admin setup notice after activation pointing to **Settings > Reddy Auth**.
* Uninstall cleanup removes plugin-owned transients (OTP and rate-limit state).
* Optional **Allowed request sources** for plugin REST endpoints (Origin/Referer allowlist, HTTP 403 when mismatched).
* Updated plugin metadata (GitHub URIs, license, WordPress and PHP requirements).
* Tested up to WordPress 7.0.
* Hardened REST middleware: sanitize request URI before route checks.
* Clearer rate limit labels and field descriptions in settings.
* Improved uninstall cleanup and WPCS compliance across core files.
* Removed redundant textdomain loader (WordPress auto-loads plugin translations).

= 0.1.0 =
* Initial MVP release.
