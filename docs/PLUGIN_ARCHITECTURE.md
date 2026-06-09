# MksDdn Reddy Auth: Plugin Architecture

## Purpose

`mksddn-reddy-auth` provides OTP-based authentication through Reddy bot and supports:

- WordPress monolith login via cookie session (shortcode or REST `issue_session: true`).
- API clients via opaque Bearer tokens (`issue_token: true`; cookie not set by default).

## Main Flows

### 1) Send Code

- Endpoint: `POST /mksddn-reddy-auth/v1/auth/send-code`
- Input: `reddy_id`
- Process:
  - Validate and sanitize input.
  - Check send rate limit.
  - Generate one-time OTP with TTL.
  - Store only OTP hash in transient.
  - When delivery mode is not `otp_only`:
    - Create login intent (`intent_id`, `intent_secret`).
    - Issue one-time magic link token (for legacy text link in message body).
    - Send Reddy message with authorize button (`type: action`, `data: intent_id`).
  - Trigger Reddy delivery through `ReddyClient`.
- Response:
  ```json
  { "success": true, "status": "code_sent", "message": "..." }
  ```
  When one-click is enabled, also includes:
  ```json
  { "intent_id": "...", "intent_secret": "..." }
  ```
  Sets signed HttpOnly cookie `mksddn_reddy_polling` with the same values (for browser shortcode flow).

### 1a) One-Click Authorization

The messenger button uses `type: action` — pressing it sends a `buttonAction` event to the configured webhook. **No browser window opens.**

- Button event receiver: `POST /mksddn-reddy-auth/v1/auth/button-callback`
  - Called by Reddy bot when user presses the authorize button in messenger.
  - Verifies `X-BotAPI-Sign` header signature (`sha256(body + bot_token)`).
  - Extracts `button.data` = `intent_id`, calls `Login_Intent_Service::approve()`.
  - Returns `{ "success": true }`.
  - **Must be configured as webhook URL in Reddy bot (BotMother) settings.** The URL is shown in plugin settings under One-Click Authorization.
- Intent polling: `GET /mksddn-reddy-auth/v1/auth/intent-status`
  - Accepts `intent_id` + `intent_secret` as query params (headless), or reads from cookie (browser shortcode).
  - Returns `{ "success": true, "status": "pending|approved" }`.
- Intent completion: `POST /mksddn-reddy-auth/v1/auth/complete-intent`
  - Accepts `intent_id` + `intent_secret` in body (headless), or reads from cookie (browser shortcode).
  - Optional: `issue_session` (bool), `issue_token` (bool).
  - Consumes approved intent and runs shared finalize-auth pipeline.
  - Response:
    ```json
    {
      "success": true,
      "status": "authenticated",
      "message": "...",
      "user": { "id": 1, "display_name": "...", "email": "..." },
      "access_token": "...",
      "token": "...",
      "expires_at": "...",
      "token_type": "Bearer"
    }
    ```
    Token fields present only when `issue_token: true`.
- Shortcode: after send-code redirect, `assets/js/login-shortcode.js` polls intent status and completes login in the original browser tab using cookie (no credentials in page JS).
- Fallback: `admin-post.php?action=mksddn_reddy_verify_link&token=...` still accepts magic link clicks from message text for backward compatibility.

### 2) Login (OTP)

- Endpoint: `POST /mksddn-reddy-auth/v1/auth/login`
- Input: `reddy_id`, `code`, optional `issue_token`, optional `issue_session`
- Process:
  - Verify OTP (one-time, TTL, login rate limit).
  - Resolve user via `IdentityService` (auto-create on first login if missing).
  - Start WP cookie session via `SessionService` only when `issue_session` is true (default false).
  - Optionally issue Bearer token via `TokenService` when `issue_token` is true.
- Response:
  ```json
  {
    "success": true,
    "status": "authenticated",
    "message": "...",
    "user": { "id": 1, "display_name": "...", "email": "..." },
    "access_token": "...",
    "token": "...",
    "expires_at": "...",
    "token_type": "Bearer"
  }
  ```
  Token fields present only when `issue_token: true`.
- Shortcode login always sets a WP cookie session; REST login does not unless `issue_session` is true.

### 3) Current User

- Endpoint: `GET /mksddn-reddy-auth/v1/auth/me`
- Auth:
  - WP cookie session (shortcode login or REST login with `issue_session: true`), or
  - Bearer token through REST auth middleware.

### 3a) Monolith vs REST protection

- **Monolith content lock** (`monolith_lock_enabled`): allows access when the visitor has a WordPress cookie session and `_mksddn_reddy_id` user meta. Does not read `Authorization: Bearer`.
- **REST API content lock** (`api_lock_enabled`): requires `Authorization: Bearer` with a valid plugin token tied to a Reddy-mapped user. Ignores cookie-only sessions. HTTP `OPTIONS` (CORS preflight) is not challenged; Bearer is enforced on the actual method (`GET`, `POST`, etc.).
- REST login with `issue_token: true` alone does not grant monolith site access. REST login with `issue_session: true` sets the cookie used by monolith lock.

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
  - `mksddn_reddy_verify_link` (one-click magic link, token-validated)

## Core Modules

- `Mksddn_Reddy_Auth_Auth_Flow_Service`
  - Orchestrates OTP send, login intent, and magic link issuance.
- `Mksddn_Reddy_Auth_Auth_Finalizer_Service`
  - Shared resolve/create user + session/token + `mksddn_reddy_after_login` pipeline.
- `Mksddn_Reddy_Auth_Magic_Link_Service`
  - One-time signed magic link tokens (hash-only transient, TTL, verify rate limit).
- `Mksddn_Reddy_Auth_Login_Intent_Service`
  - Cross-device pending/approved/consumed intent state for browser polling.
- `Mksddn_Reddy_Auth_Reddy_Client`
  - Sends OTP through upstream bot transport.
  - Reads bot token from `MKSDDN_REDDY_BOT_TOKEN` or dev fallback option.
  - Builds OTP and connection test message text from admin settings (`otp_message_template`, `magic_link_message_template`, `bot_test_message`).
  - Supports delivery modes: `otp_only`, `otp_plus_link`, `link_only`.
  - Sends authorize button with `type: action` and `intent_id` as data (triggers `buttonAction` webhook, no browser opens).
  - Custom transport via `mksddn_reddy_send_code_transport` bypasses the admin OTP template.
- `Mksddn_Reddy_Auth_Otp_Service`
  - OTP generation, hashing, TTL, one-time validation, rate limiting.
- `Mksddn_Reddy_Auth_Identity_Service`
  - Maps `reddy_id` to WP user meta; creates WP user on first login.
- `Mksddn_Reddy_Auth_Session_Service`
  - WordPress cookie login/logout.
- `Mksddn_Reddy_Auth_Token_Service`
  - Opaque token issue/validate/revoke with hash-only storage.
  - Validates Bearer tokens only for users with `_mksddn_reddy_id` meta.
  - Revokes all tokens for a user on WordPress user deletion.
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
  - `mksddn_reddy_auth_settings` (includes `allowed_urls`, `one_click_delivery_mode`, `magic_link_ttl_seconds`, `one_click_redirect_url`, `otp_message_template`, `magic_link_message_template`, `magic_link_button_label`, `bot_test_message`, lock flags, rate limits, TTLs)
  - `mksddn_reddy_auth_bot_token` (dev fallback)
  - `mksddn_reddy_auth_version`
- User meta:
  - `_mksddn_reddy_id`
  - `_mksddn_reddy_profile_hash`
- Custom DB table:
  - `{prefix}mksddn_reddy_tokens`
- Transients:
  - OTP, magic link, login intent, and rate limit state.

## Bot Message Texts

- Settings (Settings > Reddy Auth > Bot Messages):
  - `otp_message_template` — placeholders `{code}` (required for OTP modes), `{ttl}`, `{link}`.
  - `magic_link_message_template` — used when delivery mode is `link_only`; placeholders `{link}`, `{ttl}`.
  - `magic_link_button_label` — label for authorize button in messenger.
  - `bot_test_message` — text sent by the admin bot connection test action.
- One-click settings (Settings > Reddy Auth > One-Click Authorization):
  - `one_click_delivery_mode` — `otp_only` (disabled), `otp_plus_link`, `link_only`.
  - `magic_link_ttl_seconds` — magic link and intent TTL.
  - `one_click_redirect_url` — optional redirect after one-click login.
- Extension filters (applied after admin template resolution for OTP):
  - `mksddn_reddy_otp_message` (string `$message`, string `$reddy_id`, int `$ttl_seconds`)
  - `mksddn_reddy_magic_link_url` (string `$url`, string `$reddy_id`, string `$intent_id`)
  - `mksddn_reddy_send_payload` (array `$payload`, string `$reddy_id`, int `$ttl_seconds`)
  - `mksddn_reddy_bot_test_message` (string `$message`, string `$reddy_id`)

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

## Site and REST Protection Defaults

- Fresh install seeds `mksddn_reddy_auth_settings` with `api_lock_enabled` and `monolith_lock_enabled` set to `0` (off).
- Monolith lock runs whenever enabled. If no plugin login page is configured, unauthenticated visitors are redirected to `wp-login.php` as a safe fallback.
- Existing sites that already saved `1` for lock flags keep their behavior until an admin changes settings.
- When either lock is enabled, Reddy-authenticated users pass. WP users with `edit_posts` (administrator, editor) also bypass the lock so staff can preview content and call REST without Reddy OTP.
- Extension filter: `mksddn_reddy_content_lock_bypass` (bool `$exempt`, `WP_User $user`) — default follows `edit_posts`; return `true` to allow bypass for other roles.

## Credential lifecycle

- Shortcode login always calls `SessionService::login()` (WordPress auth cookie).
- REST login calls `SessionService::login()` only when `issue_session` is true (default false).
- Deleting a WordPress user triggers `delete_user`: revoke all Bearer tokens for that user ID and call `wp_destroy_user_sessions()` when available.
- Bearer validation rejects tokens when the mapped user is missing or has no `_mksddn_reddy_id` meta.
- Deleting a WordPress user does not block the Reddy ID permanently; the next successful OTP login can recreate the account via `IdentityService`.

## Headless / Cross-Domain Frontend Integration

Use this guide when the frontend runs on a different domain from WordPress (e.g. headless CMS, SPA, mobile app).

### Key differences from browser shortcode

| | Browser shortcode | Headless |
|---|---|---|
| Session auth | WP cookie (`issue_session: true`) | Bearer token (`issue_token: true`) |
| Intent binding | Signed HttpOnly cookie (automatic) | `intent_id` + `intent_secret` in request body/params |
| Credentials storage | Cookie, managed by browser | In-memory (do not use localStorage for `intent_secret`) |

### Authentication flow overview

```
Client                              WordPress
  │                                     │
  │── POST /send-code ────────────────► │  generates OTP + intent
  │◄─ { intent_id, intent_secret } ──── │
  │                                     │
  │   [user sees OTP in messenger]      │
  │                                     │
  ├── path A: user presses button ──────┤
  │   GET /intent-status (polling) ───► │
  │◄─ { status: "approved" } ─────────  │
  │── POST /complete-intent ──────────► │
  │                                     │
  ├── path B: user enters OTP ──────────┤
  │── POST /login ─────────────────────►│
  │                                     │
  │◄─ { access_token, user } ───────────│
  │                                     │
  │── GET /me (Authorization: Bearer) ─►│
```

Paths A and B run concurrently. Whichever resolves first wins — cancel the other.

### Base URL

```
https://your-wordpress.com/wp-json/mksddn-reddy-auth/v1
```

### Step 1 — Send code

```js
const BASE = 'https://your-wordpress.com/wp-json/mksddn-reddy-auth/v1';

async function sendCode(reddyId) {
  const res = await fetch(`${BASE}/auth/send-code`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ reddy_id: reddyId }),
  });

  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(err?.message ?? `HTTP ${res.status}`);
  }

  return res.json();
  // { success: true, status: "code_sent", intent_id: "...", intent_secret: "..." }
}
```

Store `intent_id` and `intent_secret` in component state / memory — never in `localStorage`.  
If `intent_id` is present in the response, start polling (path A) immediately alongside showing the OTP input (path B).

### Step 2a — One-click: poll intent status, then complete

```js
/**
 * Polls until the user presses the authorize button in the messenger.
 * Resolves with the auth response; rejects on timeout or abort.
 */
async function waitForButtonApproval({ intentId, intentSecret, signal }) {
  const INTERVAL_MS = 2_000;
  const TIMEOUT_MS  = 5 * 60_000; // 5 min
  const deadline    = Date.now() + TIMEOUT_MS;

  while (Date.now() < deadline) {
    if (signal?.aborted) throw new DOMException('Polling aborted', 'AbortError');

    const url = new URL(`${BASE}/auth/intent-status`);
    url.searchParams.set('intent_id',     intentId);
    url.searchParams.set('intent_secret', intentSecret);

    const res  = await fetch(url, { signal });
    const data = await res.json();

    if (!res.ok) throw new Error(data?.message ?? `HTTP ${res.status}`);

    if (data.status === 'approved') {
      return completeIntent({ intentId, intentSecret });
    }

    await sleep(INTERVAL_MS);
  }

  throw new Error('One-click authorization timed out');
}

async function completeIntent({ intentId, intentSecret }) {
  const res = await fetch(`${BASE}/auth/complete-intent`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      intent_id:     intentId,
      intent_secret: intentSecret,
      issue_token:   true,
    }),
  });

  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(err?.message ?? `HTTP ${res.status}`);
  }

  return res.json();
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
```

### Step 2b — OTP: manual code entry

```js
async function loginWithOtp({ reddyId, code }) {
  const res = await fetch(`${BASE}/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      reddy_id:    reddyId,
      code,
      issue_token: true,
    }),
  });

  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(err?.message ?? `HTTP ${res.status}`);
  }

  return res.json();
}
```

### Combining paths A and B with `Promise.race`

```js
async function authenticate(reddyId, getOtpFromUser) {
  const { intent_id: intentId, intent_secret: intentSecret } = await sendCode(reddyId);

  // AbortController for the losing path
  const abortA = new AbortController();
  const abortB = new AbortController();

  const pathA = intentId
    ? waitForButtonApproval({ intentId, intentSecret, signal: abortA.signal })
    : null;

  const pathB = getOtpFromUser().then(
    (code) => loginWithOtp({ reddyId, code }),
  );

  const candidates = pathA ? [pathA, pathB] : [pathB];

  try {
    const authData = await Promise.race(candidates);

    // Cancel the slower path
    abortA.abort();
    abortB.abort();

    return authData;
    /*
    {
      success:      true,
      status:       "authenticated",
      user:         { id: 1, display_name: "...", email: "..." },
      access_token: "...",
      token:        "...",
      expires_at:   "2026-07-01T00:00:00+00:00",
      token_type:   "Bearer"
    }
    */
  } catch (err) {
    abortA.abort();
    abortB.abort();
    throw err;
  }
}
```

### Step 3 — Call authenticated endpoints

```js
class AuthClient {
  #token = null;

  setToken(token) {
    this.#token = token;
  }

  async fetch(path, options = {}) {
    const headers = {
      'Content-Type': 'application/json',
      ...(this.#token ? { Authorization: `Bearer ${this.#token}` } : {}),
      ...options.headers,
    };

    const res = await fetch(`${BASE}${path}`, { ...options, headers });

    if (res.status === 401) {
      this.#token = null;
      throw new Error('Token invalid or expired — re-authenticate');
    }

    if (!res.ok) {
      const err = await res.json().catch(() => ({}));
      throw new Error(err?.message ?? `HTTP ${res.status}`);
    }

    return res.json();
  }
}

// Usage
const client = new AuthClient();
const authData = await authenticate('14963104048', promptUserForOtp);
client.setToken(authData.access_token);

const me = await client.fetch('/auth/me');
console.log(me.user.display_name);
```

### Step 4 — Logout

```js
async function logout(token) {
  await fetch(`${BASE}/auth/logout`, {
    method: 'POST',
    headers: {
      'Content-Type':  'application/json',
      Authorization: `Bearer ${token}`,
    },
  });
  // Discard token from memory regardless of response
}
```

### Webhook setup (required for one-click button)

The Reddy bot must POST `buttonAction` events to:

```
https://your-wordpress.com/wp-json/mksddn-reddy-auth/v1/auth/button-callback
```

Configure this URL in Reddy BotMother settings. The URL is also shown in **WP Admin → Settings → Reddy Auth → One-Click Authorization**.

### Error response shape

All endpoints return JSON with `success: false` on failure:

```json
{
  "success": false,
  "code":    "rest_invalid_param",
  "message": "Human-readable description",
  "data":    { "status": 400 }
}
```

Common status codes:

| Code | Meaning |
|------|---------|
| 400  | Validation error (missing/invalid field) |
| 403  | Forbidden — request origin not in allowlist |
| 429  | Rate limit exceeded — back off and retry |
| 401  | Bearer token invalid or expired |

### CORS

If WordPress and the frontend are on different origins, configure CORS headers in WordPress to allow the frontend origin. The plugin does not manage CORS itself.

## Security Invariants

- Never store raw OTP or magic link tokens in DB/options; compare hash values only.
- Magic link tokens are one-time, signed, and expire by TTL.
- Login intents require `intent_id` + `intent_secret` for polling and completion (headless), or a matching signed cookie (browser shortcode).
- OTP is one-time and expires by TTL.
- Send and login flows are rate-limited with progressive backoff.
- Bearer tokens are stored only as HMAC hash.
- Bearer tokens are revoked when the WordPress user is deleted.
- REST login must not set a cookie unless `issue_session` is true.
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
