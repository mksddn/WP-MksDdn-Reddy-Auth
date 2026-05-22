# MksDdn Reddy Auth: Plugin Architecture

## Purpose

`mksddn-reddy-auth` provides OTP-based authentication through Reddy bot and supports:

- WordPress monolith login via cookie session.
- API clients via opaque Bearer tokens.

## Main Flows

### 1) Send Code

- Endpoint: `POST /mksddn-reddy-auth/v1/auth/send-code`
- Input: `reddy_id`
- Process:
  - Validate and sanitize input.
  - Check send rate limit.
  - Generate one-time OTP with TTL.
  - Store only OTP hash in transient.
  - Trigger Reddy delivery through `ReddyClient`.

### 2) Login

- Endpoint: `POST /mksddn-reddy-auth/v1/auth/login`
- Input: `reddy_id`, `code`, optional `issue_token`
- Process:
  - Verify OTP (one-time, TTL, login rate limit).
  - Resolve user via `IdentityService` (auto-create on first login if missing).
  - Start WP cookie session via `SessionService`.
  - Optionally issue Bearer token via `TokenService`.

### 3) Current User

- Endpoint: `GET /mksddn-reddy-auth/v1/auth/me`
- Auth:
  - WP cookie session, or
  - Bearer token through REST auth middleware.

### 4) Logout

- Endpoint: `POST /mksddn-reddy-auth/v1/auth/logout`
- Process:
  - Destroy WP cookie session.
  - Revoke Bearer token if provided in `Authorization` header.

### 5) Monolith UI

- Shortcode: `[mksddn_reddy_login]`
- Handlers: nonce-protected `admin-post` actions:
  - `mksddn_reddy_send_code`
  - `mksddn_reddy_login`

## Core Modules

- `Mksddn_Reddy_Auth_Reddy_Client`
  - Sends OTP through upstream bot transport.
  - Reads bot token from `MKSDDN_REDDY_BOT_TOKEN` or dev fallback option.
- `Mksddn_Reddy_Auth_Otp_Service`
  - OTP generation, hashing, TTL, one-time validation, rate limiting.
- `Mksddn_Reddy_Auth_Identity_Service`
  - Maps `reddy_id` to WP user meta; creates WP user on first login.
- `Mksddn_Reddy_Auth_Session_Service`
  - WordPress cookie login/logout.
- `Mksddn_Reddy_Auth_Token_Service`
  - Opaque token issue/validate/revoke with hash-only storage.
- `Mksddn_Reddy_Auth_Token_Repository`
  - DB access layer for token records.
- `Mksddn_Reddy_Auth_Rest_Auth_Middleware`
  - Auth bridge for protected REST routes.
- `Mksddn_Reddy_Auth_Rest_Auth_Controller`
  - REST routes for auth flow.
- `Mksddn_Reddy_Auth_Login_Shortcode`
  - Minimal login UI for monolith mode.
- `Mksddn_Reddy_Auth_Settings_Page`
  - Admin settings via Settings API.

## Data Storage

- Options:
  - `mksddn_reddy_auth_settings` (includes `allowed_urls` string array)
  - `mksddn_reddy_auth_bot_token` (dev fallback)
  - `mksddn_reddy_auth_version`
- User meta:
  - `_mksddn_reddy_id`
  - `_mksddn_reddy_profile_hash`
- Custom DB table:
  - `{prefix}mksddn_reddy_tokens`
- Transients:
  - OTP and rate limit state.

## Request URL Allowlist

- Setting: `allowed_urls` in `mksddn_reddy_auth_settings` (array of strings).
- Empty list: no source restriction (default, backward compatible).
- Non-empty list: only matching `Origin` or `Referer` may call plugin REST routes (`/mksddn-reddy-auth/v1/*`).
- Supported formats: `https://host`, optional path prefix (e.g. `https://app.example.com/admin`).
- Enforcement: `permission_callback` on plugin REST routes and `rest_pre_dispatch` via `Mksddn_Reddy_Auth_Request_Url_Guard` (HTTP 403).
- Soft guard only: headers are client-controlled and spoofable; use OTP, rate limits, and API lock for real protection.
- Server-to-server clients (curl, backends) without matching headers are blocked when the list is non-empty—leave empty or use the filter below.
- Extension filter: `mksddn_reddy_is_request_url_allowed` (always invoked; can deny even when the list is empty).
- Does not apply to monolith shortcode/admin-post login forms.

## Security Invariants

- Never store raw OTP in DB/options; compare hash values only.
- OTP is one-time and expires by TTL.
- Send and login flows are rate-limited with progressive backoff.
- Bearer tokens are stored only as HMAC hash.
- Nonces protect state-changing shortcode forms.
- Admin settings are restricted by `manage_options`.
- Uninstall cleanup removes only plugin-owned data.

## Update Policy

Update this document in the same task **only if plugin contracts changed**:

- REST endpoints or request/response shape,
- option keys or meta keys,
- token/OTP data schema and storage strategy,
- hooks (`do_action` / `apply_filters`),
- auth flow behavior visible to integrators.
