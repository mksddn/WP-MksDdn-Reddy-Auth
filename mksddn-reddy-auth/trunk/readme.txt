=== MksDdn Reddy Auth ===
Contributors: mksddn
Tags: authentication, otp, rest-api, login
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Authenticate WordPress users via Reddy OTP flow for monolith and REST API clients.

== Description ==

MksDdn Reddy Auth provides OTP-based authentication with:

- WordPress cookie session login for the frontend (shortcode, or REST with `issue_session: true`).
- Optional Bearer token issuing for REST clients (`issue_token: true`; cookie not set by default on REST login).
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
2. `POST /auth/login` with `{ "reddy_id": "123456", "code": "111111", "issue_token": true }` for headless clients. Add `"issue_session": true` only when the browser must also receive a WordPress cookie (same-origin SPA).
3. Call protected REST routes with `Authorization: Bearer <token>`.
4. `GET /auth/me` to read the current user (Bearer or cookie session).
5. `POST /auth/logout` to end the cookie session and revoke the Bearer token when provided.

**Protect site content** checks the WordPress cookie session (shortcode login or REST login with `issue_session: true`). It does not accept Bearer tokens. **Protect all REST API content** requires a Bearer token and ignores cookie-only sessions.

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

Send `issue_token: true` in the login request, then pass the returned token in the `Authorization: Bearer` header. REST login does **not** set a WordPress cookie unless you also send `issue_session: true`. Use the login shortcode or `issue_session: true` when the browser needs access to **Protect site content** pages.

= What is the difference between issue_token and issue_session? =

`issue_token` returns a Bearer token for REST API clients. `issue_session` sets the WordPress auth cookie. Shortcode login always sets a cookie. REST login sets a cookie only when `issue_session` is true (default false). Headless integrations should use `issue_token` without `issue_session` so site content stays locked until an explicit cookie login.

= What happens when an administrator deletes a Reddy user? =

All plugin Bearer tokens for that WordPress user are revoked and WordPress session tokens are destroyed. The user must complete OTP login again. Deleting the WordPress account does not permanently block the Reddy ID; a successful OTP login can recreate the account.

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

= 0.1.3 =
* REST login no longer sets a WordPress cookie by default. Optional `issue_session` parameter (default false); use `issue_token` for Bearer auth. Shortcode login still sets a cookie.
* **Protect site content** uses cookie sessions only; **Protect all REST API content** requires Bearer tokens. Documented split between monolith and REST protection.
* Revoke all Bearer tokens and destroy WordPress sessions when a WordPress user is deleted.
* Bearer token validation requires an active `_mksddn_reddy_id` user meta mapping.
* Site and REST content lock: WP staff with `edit_posts` (administrator, editor) bypass Reddy-only lock without OTP.
* Filter `mksddn_reddy_content_lock_bypass` to customize lock bypass per user.
* More reliable login page detection for monolith content lock (configured page, URL path, shortcode fallback).
* REST content lock respects existing authentication errors before enforcing Reddy check.

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
