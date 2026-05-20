=== MksDdn Reddy Auth ===
Contributors: mksddn
Tags: authentication, otp, rest-api, login
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Authenticate WordPress users via Reddy OTP flow for monolith and REST API clients.

== Description ==

MksDdn Reddy Auth provides OTP-based authentication with:

- WordPress cookie session login.
- Optional Bearer token issuing for REST clients.
- Rate limiting and one-time OTP verification.

== Installation ==

1. Upload the plugin to the `/wp-content/plugins/` directory.
2. Activate the plugin through the `Plugins` menu in WordPress.
3. Configure plugin options under `Settings > Reddy Auth`.
4. Add shortcode `[mksddn_reddy_login]` to a page for monolith login.

== Changelog ==

= 0.1.0 =
* Initial MVP release.
