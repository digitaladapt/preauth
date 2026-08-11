# Preauth — Project Roadmap

## Project Overview

Preauth is a pre-authentication gate for self-hosted services. It sits
between a reverse proxy (Caddy's `forward_auth`) and your web service,
requiring a TOTP code (or backup code) before traffic ever reaches the
protected application. It is **not** a replacement for the service's own
authentication — it's a gate that prevents outsiders from even seeing
what service is running.

- **Location:** `projects/preauth/`
- **Framework:** Symfony 7.4 (PHP ≥ 8.4)
- **Serving:** FrankenPHP (Docker image)
- **Cache:** Dual-layer — APCu (in-memory) + file-based persistence
- **Auth:** TOTP (single secret) + single-use backup codes
- **Production status:** Running in production since June 2024

### Current Production Use

| Service     | Purpose                                          |
|-------------|--------------------------------------------------|
| Bitwarden   | Password manager — always accessible, invisible to the world |
| Microbin    | Sharing text blobs and small files across devices |
| Gitea       | Code hosting — some DNS configs must be public   |

---

## Architecture

### Request Flow

```
Client → Caddy → forward_auth → Preauth listeners (priority order) → 200/401/418
```

1. **AcceptListener** (priority 99) — Checks for valid session cookie.
   If found → `200 OK` + `Remote-User` header → Caddy proxies to backend.
2. **AllowListener** (priority 88) — If `IP_TTL` is enabled, checks for
   valid IP-based session. If found → `200 OK` + `Remote-User`.
3. **RejectListener** (priority 77) — Rate-limiting gate. If IP has
   exceeded login attempt threshold → `418 I'm a Teapot` (or `429`).
4. **LoginListener** (priority 66) — Detects login attempts via
   `X-Preauth` header (base64url JSON) or POST form on auth subdomain.
   Validates TOTP/backup codes through `LoginManager`.
5. **InterceptListener** (priority 55) — Fallback: if no listener has
   set a response, either redirects to auth subdomain (central auth) or
   renders the Twig login page with a fresh nonce.

### Key Design Decisions

- **No controllers** — Entirely event-listener-driven. Clean separation
  of concerns, each listener handles one stage of the auth flow.
- **Dual-layer cache** — APCu for fast in-memory lookups, file-based
  storage for persistence across container restarts. `MonitorCacheKeys`
  wraps the PSR-6 pool to track key changes for efficient persistence
  (only write what changed).
- **`__Host-` prefixed cookies** — `SameSite=Strict`, `Secure`,
  `HttpOnly`. Central auth mode uses a separate `__Http-Domain-Preauth`
  cookie name (domain-scoped, no `__Host-` prefix).
- **Nonce system** — 15-byte random nonces, single-use, 120s TTL, with
  retry-on-collision (up to 3 attempts).
- **TOTP with ±1 period leeway (±30 seconds)** — Accommodates clock drift.
- **Backup codes** — Case-insensitive alphanumeric, single-use, stored
  in cache with year-2999 expiry. Generated via console command.
- **Domain awareness** — `DomainManager` handles multi-part TLDs
  (`.co.uk`, `.com.au`, etc.) with a built-in TLD lookup table.
- **Interfaces** — `LoginInterface`, `DomainInterface`,
  `BackupCodeInterface` extracted to support testing (mockable).

---

## Test Suite Status

### Current Results

| Metric       | Value                          |
|--------------|--------------------------------|
| **Tests**    | 222                            |
| **Assertions** | 469                          |
| **Pass**     | 222 (100%)                     |
| **Fail**     | 0                              |
| **Errors**   | 0                              |
| **Warnings** | 0                              |
| **Time**     | ~0.56s (without coverage)     |
|              | ~1.31s (with coverage)        |

### Code Coverage

| Metric   | Percentage          |
|----------|---------------------|
| **Lines**   | **100.00%** (442/442) |
| **Methods** | **100.00%** (83/83)   |
| **Classes** | **100.00%** (21/21)   |

Every class, method, and line in `src/` is covered.

### Source → Test Mapping

| Source File                              | Test File                                          | Type     |
|------------------------------------------|----------------------------------------------------|----------|
| `Clock.php`                              | `Unit/ClockTest.php`                               | Unit     |
| `ConfigBag.php`                          | `Unit/ConfigBagTest.php`                           | Unit     |
| `Kernel.php`                             | (covered via functional tests)                     | Functional |
| `MonitorCacheKeys.php`                   | `Unit/MonitorCacheKeysTest.php`                    | Unit     |
| `PersistCache.php`                       | `Unit/PersistCacheTest.php`                        | Unit     |
| `Utilities.php`                          | `Unit/UtilitiesTest.php`                           | Unit     |
| `Command/GenerateBackupCodesCommand.php` | `Unit/Command/GenerateBackupCodesCommandTest.php`  | Unit     |
| `Data/Payload.php`                       | `Unit/Data/PayloadTest.php`                        | Unit     |
| `Enum/Scope.php`                         | `Unit/Enum/ScopeTest.php`                          | Unit     |
| `Listener/AcceptListener.php`            | `Unit/Listener/AcceptListenerTest.php`             | Unit     |
| `Listener/AllowListener.php`             | `Unit/Listener/AllowListenerTest.php`              | Unit     |
| `Listener/InterceptListener.php`         | `Unit/Listener/InterceptListenerTest.php`          | Unit     |
| `Listener/LoginListener.php`             | `Unit/Listener/LoginListenerTest.php`              | Unit     |
| `Listener/RejectListener.php`            | `Unit/Listener/RejectListenerTest.php`             | Unit     |
| `Service/BackupCodeManager.php`          | `Unit/Service/BackupCodeManagerTest.php`           | Unit     |
| `Service/DomainManager.php`              | `Unit/Service/DomainManagerTest.php`               | Unit     |
| `Service/LoginManager.php`               | `Unit/Service/LoginManagerTest.php`                | Unit     |
| `Trait/CookieNameTrait.php`              | `Unit/Trait/CookieNameTraitTest.php`               | Unit     |
| `Trait/GetTotpTrait.php`                 | `Unit/Trait/GetTotpTraitTest.php`                  | Unit     |
| `Trait/HasLoggerTrait.php`               | `Unit/Trait/HasLoggerTraitTest.php`                | Unit     |
| `Trait/MakeNonceTrait.php`               | `Unit/Trait/MakeNonceTraitTest.php`                | Unit     |
| `Trait/StringTrait.php`                  | `Unit/Trait/StringTraitTest.php`                   | Unit     |
| *(All listeners + services)*             | `Functional/AuthenticationFlowTest.php`            | Functional |

### Test Quality Assessment

**Strengths:**
- **100% coverage** — every line, method, and class.
- **Well-structured test hierarchy** — Unit tests per class, functional
  tests for the full HTTP kernel flow. Two support traits
  (`TotpTestHelper`, `ListenerTestHelper`) provide reusable fixtures
  (frozen clock, deterministic TOTP, Twig environment, mock rate
  limiters).
- **Edge cases well-covered** — ULID collision handling, nonce collision
  retries, spent nonces, invalid payloads (bad base64, non-object JSON,
  arrays, null, booleans), empty/whitespace fields, field truncation,
  multibyte characters in cache keys, multi-part TLD domain matching,
  cookie pruning on invalid sessions.
- **Both positive and negative paths** — Every listener tests both
  success and failure scenarios.
- **Security-conscious testing** — Backup code single-use enforcement,
  case-insensitivity, character stripping, rate limit teapot vs.
  too-many-requests, return URL validation (prevents open redirect),
  cookie security attributes.
- **Realistic functional tests** — `AuthenticationFlowTest` goes through
  the actual Symfony kernel: fetches nonces from rendered HTML, submits
  TOTP codes, verifies cookies are set, tests the full login →
  authenticated access cycle.
- **Smart test infrastructure** — `KernelBrowser::disableReboot()` used
  in functional tests so nonces persist across requests (matching
  production APCu behavior).

**Status: Test suite goal is met.** 222 tests, 100% coverage, all passing.

---

## Roadmap

### Phase 1 — Public but Rate-Limited Access ✦

**Goal:** Allow select services to be publicly accessible (no TOTP
required) but with aggressive per-IP rate limiting to prevent bot
traffic from overwhelming the server.

**Context:** The user previously made Gitea semi-public (view but no
login), but bot traffic slowed the server and consumed all household
bandwidth, forcing it back to fully private. The solution isn't more
authentication — it's bandwidth/resource protection for public-facing
services.

**Design:**

- New config variables:
  - `PUBLIC_MODE=false` — Enable public access for specific services
  - `PUBLIC_RATE_LIMIT=10` — Max requests per minute from a single IP
    on public paths
  - `PUBLIC_RATE_WINDOW=60` — Sliding window in seconds
  - `PUBLIC_BURST=20` — Allow short bursts above the sustained rate

- New listener: **PublicListener** (priority 95, between AcceptListener
  and AllowListener):
  - Checks if the request matches a public path pattern (configured per
    service via Caddy's `forward_auth` URI or a header like
    `X-Preauth-Public: true`).
  - If public mode is enabled for this request, applies aggressive
    per-IP rate limiting (separate from the login rate limiter).
  - If within rate limit → `200 OK` (no `Remote-User` header, or a
    `Remote-User: public` marker).
  - If over rate limit → `429 Too Many Requests` with `Retry-After`
    header.

- Caddy config would use different `forward_auth` snippets for public
  vs. protected services:
  ```caddyfile
  # Protected service — requires TOTP
  bitwarden.example.com {
      forward_auth preauth { copy_headers Remote-User }
      reverse_proxy bitwarden:80
  }
  
  # Public but rate-limited service
  git.example.com {
      forward_auth preauth/public { copy_headers Remote-User }
      reverse_proxy gitea:3000
  }
  ```

- Consider integration with Caddy's own rate limiting as a second layer
  of defense (rate limit at the reverse proxy before traffic even hits
  preauth).

- [ ] Design public path detection mechanism (URI-based or header-based)
- [ ] Implement `PublicListener` with separate rate limiter pool
- [ ] Add config variables and defaults
- [ ] Update Caddyfile example with public service snippet
- [ ] Tests for public mode (within limit, over limit, burst behavior)
- [ ] Documentation in README

### Phase 2 — Session Management & Audit

**Goal:** Give visibility into who has access and when it was granted.

- [ ] **Active sessions view** — Console command or simple API endpoint
  to list active sessions (cookie-based and IP-based), showing:
  - Session ID / username
  - IP address
  - First auth timestamp
  - Last seen timestamp
  - Scope (cookie vs. IP)
- [ ] **Session revocation** — Console command to revoke a specific
  session by ID or revoke all sessions for an IP.
- [ ] **Audit log** — Log every successful and failed authentication
  attempt to a persistent store (file-based JSONL, similar to the email
  integration's audit log):
  ```json
  {
    "timestamp": "2025-01-15T14:23:01Z",
    "ip": "192.168.1.50",
    "action": "login_success",
    "username": "mom",
    "method": "totp"
  }
  ```
- [ ] Tests for all new commands and endpoints

### Phase 2b — Backup Code System Completion

**Goal:** Finish the backup code system — the core logic is solid but
the management surface is incomplete.

**What already exists:**
- ✅ `BackupCodeManager::generate()` — Creates codes, saves to cache
  with year-2999 expiry
- ✅ `BackupCodeManager::expire()` — Deletes all `backup_` prefixed
  keys from cache
- ✅ `BackupCodeManager::verifyAndConsume()` — Validates and marks code
  as used (sets value to `false`, keeps the key for audit trail)
- ✅ `app:generate-backup-codes [count]` console command
- ✅ Tests for all of the above (100% coverage)

**What's missing:**

- [ ] **`app:list-backup-codes` command** — Show backup code status:
  - Total codes generated
  - How many are still valid (unused)
  - How many have been spent (and optionally when)
  - Output format: table with status column (✅ valid / ⛔ used)
  - Note: spent codes are kept in cache with value `false`, so we can
    distinguish "used" from "never existed" — this is good design

- [ ] **`app:expire-backup-codes` command** — Wrap the existing
  `BackupCodeManager::expire()` method in a console command. Should:
  - Show how many codes are being expired before confirmation
  - Support `--force` flag to skip confirmation prompt
  - Call `persistCache->boot()` and `persistCache->persist()` like the
    generate command does (since `Kernel::terminate()` doesn't run in
    CLI)

- [ ] **Notification on backup code use** — When
  `verifyAndConsume()` consumes a backup code, fire a notification
  through configurable channels:
  - Discord webhook (we already have the `discord.sh` infrastructure)
  - ntfy
  - Email (once email integration is available)
  - Webhook (generic HTTP POST for future integrations)
  - Config variables:
    - `BACKUP_CODE_NOTIFY=discord,ntfy` — comma-separated channels
    - `BACKUP_CODE_NOTIFY_WEBHOOK=''` — generic webhook URL
  - Message should include: timestamp, IP address, username, and how
    many valid codes remain
  - Architecture: `BackupCodeManager` dispatches an event
    (e.g. `BackupCodeUsedEvent`) after consuming a code. A listener
    handles the notification dispatch. This keeps the notification
    logic out of the backup code manager itself.

- [ ] **Low-codes warning** — If backup codes fall below a threshold
  (e.g. 3 remaining), include a warning in the notification and/or
  surface it in the `list-backup-codes` command output

- [ ] Tests for all new commands and notification dispatch

### Phase 2c — Passkey Authentication

**Goal:** Add WebAuthn/FIDO2 passkey support as an alternative
authentication method alongside TOTP and backup codes.

**Context:** Passkeys are the modern standard for passwordless auth.
They're phishing-resistant (domain-bound), use biometrics or device
PINs, and are significantly more user-friendly than typing 6-digit
codes. For a pre-auth gate that friends and family use, passkeys would
be a major UX improvement — especially for non-technical users who
struggle with TOTP apps.

**Design considerations:**

- Passkeys are **per-device**, not shared secrets. Unlike TOTP (one
  secret shared with all devices), each device registers its own
  passkey. This is actually better for a family-use gate — you can
  register mom's phone separately from dad's laptop.

- WebAuthn requires a **challenge-response flow**:
  1. Client requests a challenge (preauth generates and stores a
     challenge nonce, similar to the existing nonce system)
  2. Browser prompts for biometric/PIN, creates a signed assertion
  3. Server verifies the assertion against the registered credential

- This is a **two-step flow** unlike TOTP's single-step, which means
  the login page JS and `LoginListener` need to handle an additional
  round-trip. The existing nonce + AJAX pattern in `_script.html.twig`
  is a good foundation — extend it with a "use passkey" button that
  initiates the `navigator.credentials.get()` flow.

- Library: `web-auth/webauthn-framework` (PHP WebAuthn library,
  Symfony bundle available). Would add registration ceremony (console
  command or initial-setup flow to register a passkey).

- [ ] Research `web-auth/webauthn-framework` integration with Symfony
      7.4 and FrankenPHP
- [ ] Design passkey registration flow (console command? first-visit
      setup? separate registration endpoint?)
- [ ] Implement challenge generation and storage (extend existing
      nonce/cache infrastructure)
- [ ] Implement assertion verification in a new `PasskeyManager`
      service (implements a shared `AuthMethodInterface`?)
- [ ] Add passkey option to login page JS (`navigator.credentials.get()`)
- [ ] Handle multiple registered passkeys (per-device)
- [ ] Console command: `app:list-passkeys` — show registered devices
- [ ] Console command: `app:remove-passkey` — revoke a passkey
- [ ] Config: `PASSKEY_ENABLED=false` — enable/disable passkey auth
- [ ] Tests for registration, authentication, and revocation
- [ ] Consider: should passkeys be a *replacement* for TOTP or an
      *alternative*? (Probably alternative — keep TOTP as fallback)

### Phase 3 — Multi-User Support

**Goal:** Support multiple TOTP users for household/family access.

*Note: This is a significant feature that changes the single-secret
model. It should only be pursued if the single-secret + backup codes
approach proves insufficient for the use case.*

- [ ] Multiple TOTP secrets, each with a label (e.g., "mom", "dad",
      "friend")
- [ ] Per-user backup codes
- [ ] Per-user session tracking (the `username` field in Payload already
      supports this — sessions are already tagged with an ID)
- [ ] Console command to add/remove/list users
- [ ] Consider: should the login page ask for a username, or should all
      TOTP codes be tried against all secrets? (Username is better —
      it's already in the payload.)
- [ ] Tests for multi-user scenarios

### Phase 4 — Polish & Hardening

**Goal:** Production hardening and quality-of-life improvements.

- [ ] **Docker image improvements:**
  - Multi-arch builds (amd64 + arm64 for Raspberry Pi)
  - Smaller image size (alpine-based if feasible)
  - Better health check (actual endpoint, not just `curl localhost`)
- [ ] **GitHub/Gitea repository polish:**
  - ✅ Comprehensive README with setup guide, architecture overview, and
    configuration reference
  - Contributing guidelines
  - ✅ Changelog formalised (CHANGELOG.md)
  - ✅ CI workflows (tests + php-cs-fixer on push/PR, Docker image on tag)
- [ ] **Security review:**
  - ✅ CSRF protection on the POST form login — nonce system documented
  - ✅ Security headers added (X-Content-Type-Options, X-Frame-Options, CSP, etc.)
  - Review nonce entropy and cache key collision space
  - Consider session fixation protections
- [ ] **Frontend improvements:**
  - Mobile-responsive login page audit
  - Accessibility audit (ARIA labels, keyboard navigation)
  - Dark mode (if not already — the teal background suggests it might
    already be dark-themed)
- [ ] **Logging improvements:**
  - Structured logging (JSON format option) for easier parsing
  - Log rotation configuration
  - Debug mode documentation

---

## Feature Thoughts

Based on the review, here are features that might be missing or worth
considering, keeping in mind that preauth is a **gate**, not a full
identity provider:

### High Value

1. **Public but rate-limited mode** (Phase 1) — Directly solves the
   Gitea bot traffic problem. This is the most impactful missing
   feature.

2. **Passkey authentication** (Phase 2c) — Phishing-resistant,
   passwordless auth that's far more user-friendly than TOTP for
   non-technical family members. The modern standard for this kind
   of gate.

3. **Backup code notifications** (Phase 2b) — When a backup code is
   used, you should know about it immediately. This is a security-critical
   event — it means someone lost their device or is locked out of their
   TOTP app. Discord/ntfy/email notification should fire automatically.

4. **Backup code management commands** (Phase 2b) — The `generate`
   command exists, but `list` and `expire` commands are missing despite
   the underlying methods (`expire()`) already being implemented.

5. **Session visibility and revocation** (Phase 2) — Currently there's
   no way to see who has access or revoke a session without clearing
   the entire cache. For a security tool, this is important.

6. **Audit log** (Phase 2) — For a security gate, not having an audit
   trail of logins (successful and failed) is a gap. The data is logged
   at debug level, but not persisted in a queryable format.

### Medium Value

4. **Health check endpoint** — The Dockerfile has a `HEALTHCHECK` that
   just `curl`s localhost, but a dedicated `/health` endpoint that
   verifies cache connectivity would be more meaningful.

5. **Graceful degradation** — If the file-based cache is corrupted or
   unavailable, does preauth fail open or closed? Should be documented
   and tested. (Currently the `PersistCache` handles this in `boot()`,
   but edge cases around partial corruption could be explored.)

6. **Rate limit headers** — Adding `X-RateLimit-Remaining` and
   `Retry-After` headers to rate-limited responses would help legitimate
   clients back off gracefully.

### Lower Value (Nice to Have)

7. **WebSocket support** — If protected services use WebSocket
   connections, does `forward_auth` handle the upgrade handshake? This
   is likely a Caddy configuration concern, but worth documenting.

8. **Theming presets** — Beyond the current env-var colour config,
   preset themes or custom CSS upload could be nice for personalisation.

9. **TOTP secret rotation** — Console command to generate a new TOTP
   secret and invalidate all existing sessions. Useful if a device is
   lost or compromised.

10. **Per-service authentication policies** — Different services could
    require different authentication strength (e.g., Bitwarden requires
    TOTP + recent login, Microbin accepts any valid session). This would
    need Caddy configuration support to pass the policy to preauth.

---

## Branch Status

| Branch | Status | Notes |
|--------|--------|-------|
| `main` (0.10.0) | Production | Current stable release |

All feature branches have been pruned. Development uses a feature-branch + PR workflow into `main`.

---

## Relationship to Other Projects

| Project | Integration |
|---------|-------------|
| MCP server | Preauth could be registered as an MCP command for session management ("revoke all sessions", "who's logged in?") |
| Email integration | Audit log entries could be included in morning summary ("2 failed login attempts from 203.0.113.50 overnight") |
| Discord/ntfy | Alert on backup code usage, suspicious activity (rate limit triggered, multiple failed attempts from new IP), low backup code count |

---

*Prepared by Lyra, your office-side assistant. ✨*
