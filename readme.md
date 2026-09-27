# Preauth

A lightweight TOTP authentication gateway for self-hosted web services.

Preauth sits between your reverse proxy (Caddy) and your web service,
requiring a TOTP code before traffic ever reaches the protected application.
It is **not** a replacement for your service's own authentication — it's a
gate that prevents outsiders from even seeing what service is running.

For when you want a belt and suspenders.

## Features

- **TOTP authentication** — Time-based one-time passwords (compatible with
  Google Authenticator, Authy, 1Password, etc.)
- **Backup codes** — Single-use backup codes for when TOTP devices are lost
- **Caddy native** — Designed for Caddy's `forward_auth` directive
- **Docker-first** — Single container, persistent volumes, no database
- **Rate limiting** — Per-IP burst and sustained limits (cannot be disabled)
- **Public rate-limited access** — Optional, allow unauthenticated access
  to specific paths with separate rate limiting (e.g., public Gitea repos)
- **Central auth** — Optional subdomain-based SSO across multiple services
- **IP-based bypass** — Optional, for services that don't handle cookies
- **Customizable** — Colors, labels, messages, and error text via env vars
- **Teapot mode** — Respond with `418 I'm a Teapot` when rate-limited
  (because it's more fun than `429 Too Many Requests`)
- **Cookie security** — `__Host-` prefixed cookies with `SameSite=Strict`,
  `Secure`, and `HttpOnly`
- **Nonce system** — Single-use nonces prevent replay and CSRF attacks
- **Dual-layer cache** — APCu for speed, file-based persistence for restarts

## Quick Start

### 1. Pull the Docker image

```bash
docker pull digitaladapt/preauth:latest
```

### 2. Create your environment file

```bash
# Generate a TOTP secret to get started
openssl rand -base64 30
```

Create a `.env` file (see `docs/examples/.env.example` for all options):

```env
APP_SECRET=your-random-secret-here
TOTP_URI=otpauth://totp/Preauth?secret=YOUR_SECRET
COOKIE_TTL=2592000
```

> If `TOTP_URI` is left blank, the app will generate one on first run
> and print it to the container logs. Copy it to your `.env` file.

### 3. Start the container

```bash
docker compose up -d
```

See `docs/examples/compose.yaml` for an example Docker Compose file.

### 4. Configure Caddy

```caddyfile
service.example.com {
    forward_auth preauth {
        uri {uri}
        copy_headers Remote-User

        # keep the login flow out of browser/proxy caches
        header_down Cache-Control "no-cache, no-store, must-revalidate, proxy-revalidate, max-age=0, s-maxage=0"
        header_down Pragma "no-cache"
        header_down Expires "0"
        header_down Surrogate-Control "no-store"
        header_down Vary "*"
    }
    reverse_proxy your-service:80
}
```

See `docs/examples/Caddyfile` for more examples, including path-specific protection
and central auth subdomain configuration. The `header_down` lines above are
optional — preauth already sends these headers itself — but they guarantee
at the edge that no part of the login flow is ever cached. (2xx auth
responses are consumed by `forward_auth` and never reach the browser, so
your service's own cache headers are unaffected.)

### 5. Generate backup codes (optional)

```bash
docker exec -t preauth bin/console app:generate-backup-codes [count=10]
```

## Requirements

- **Docker** — Preauth runs as a Docker container
- **Caddy** — As your reverse proxy (uses `forward_auth` directive)
- **A web service** — The application you want to protect

Other reverse proxies with similar `forward_auth` / `auth_request`
capabilities may work, but only Caddy is officially supported.

## Configuration

All configuration is via environment variables. See `docs/examples/.env.example`
for the complete reference.

### Main Options

| Variable | Default | Description |
|----------|---------|-------------|
| `TOTP_URI` | _(empty)_ | TOTP provisioning URI. If blank, one is generated on first run. |
| `COOKIE_TTL` | `2592000` | Session duration in seconds (default: 30 days). |
| `SUBDOMAIN_REDIRECT` | `0` | Enable central auth across subdomains (boolean). |
| `AUTH_SUBDOMAIN` | _(empty)_ | Hostname for central auth (e.g., `auth.example.com`). |

### Extra Options

| Variable | Default | Description |
|----------|---------|-------------|
| `IP_TTL` | `0` | Seconds to allow all traffic from an IP after login (0 = disabled). |
| `TEAPOT` | `1` | Respond with 418 instead of 429 when rate-limited (boolean). |
| `MAX_REQUESTS` | `500` | Restart each FrankenPHP worker thread after this many requests to contain memory growth (`0` = unlimited). Maps to the Caddyfile `max_requests` directive. |

### Remote-User Header

The `Remote-User` header sent to backends on successful auth is configurable:

| Variable | Default | Description |
|----------|---------|-------------|
| `REMOTE_USER` | `session` | Mode: `session`, `static`, `mapped`, or `none`. |
| `REMOTE_USER_STATIC` | `authenticated` | Value sent when mode is `static`. |
| `REMOTE_USER_MAP` | _(empty)_ | Comma-separated map for `mapped` mode (e.g. `alice:admin,bob:user`). |

- **`session`** (default): Sends the session id. Backward-compatible.
- **`static`**: Sends a fixed string for all authenticated requests.
- **`mapped`**: Looks up the session id in the map; falls back to session id if not found.
- **`none`**: Omits the header entirely (Caddy still accepts based on status code).

### Rate Limiting

Rate limiting **cannot be disabled**. It uses a compound sliding window:

| Variable | Default | Description |
|----------|---------|-------------|
| `BURST_COUNT` | `2` | Max attempts per burst window. |
| `BURST_TIME` | `30` | Burst window in seconds. |
| `UPPER_COUNT` | `10` | Max attempts per upper window. |
| `UPPER_TIME` | `3600` | Upper window in seconds (1 hour). |

### Passkey Authentication

Passkeys (WebAuthn) can replace the TOTP code for everyday logins, while the
code remains the way a new device is enrolled.

**Two prerequisites, both enforced.** The feature refuses to operate without
them rather than degrading quietly:

1. **Central auth must be configured** (`SUBDOMAIN_REDIRECT=true` and a real
   `AUTH_SUBDOMAIN`). A passkey is scoped to one relying party, so there has to
   be a single shared domain for the whole family of services. Without it,
   passkeys are switched off — they would otherwise be scoped to a single host
   and confuse users with multiple, unrelated passkeys.
2. **HTTPS is required, in development too.** There is no `http://localhost`
   exemption and no setting that re-enables one, because such an exemption is
   exactly how the same weakness ends up enabled in production. To exercise
   passkeys locally, see the TLS note in `docs/examples/Caddyfile`.

`localhost` therefore cannot be used for passkeys: it has no base domain, so
central auth cannot be configured at all.

| Variable | Default | Description |
|----------|---------|-------------|
| `PASSKEY_ENABLED` | `0` | Master switch. Enabling it without the prerequisites above makes the container fail at startup, rather than offering a feature that cannot work. |
| `PASSKEY_RP_NAME` | `TITLE` | Name shown in the authenticator prompt. |
| `PASSKEY_USER_VERIFICATION` | `required` | `required`, `preferred`, or `discouraged`. An unrecognised value falls back to `required`, never to something weaker. |
| `PASSKEY_TIMEOUT` | `60000` | Ceremony timeout in milliseconds. |
| `PASSKEY_BUTTON_NAME` | `Sign in with a passkey` | Label for the sign-in button. |
| `PASSKEY_REGISTER_NAME` | `Register this device as a passkey` | Label for the registration checkbox. |
| `PASSKEY_BEGIN_BURST_COUNT` | `30` | Ceremonies one caller may start per window. A resource guard, not part of the login budget. |
| `PASSKEY_BEGIN_BURST_TIME` | `60` | Window for the above, in seconds. |

**Registering a device.** Log in with your code as usual, tick *Register this
device as a passkey*, and approve the prompt. The TOTP check in that same
submission is what authorises the registration — there is no separate enrolment
token and no CLI command, so a passkey cannot be created without already holding
a valid code.

**Logging in.** Once registered, *Sign in with a passkey* signs you in with a
fingerprint, face, or device PIN instead of typing a code. Failed passkey
attempts count against the same rate-limit budget as wrong codes, so passkeys
cannot be used to sidestep a lockout, and after the limit is reached *every*
method is blocked equally.

> **On signature counters.** Many passkeys (anything synchronised through a
> keychain) report a constant counter, so a counter-based clone check would lock
> users out of their own credentials. Preauth accepts an unchanged counter and
> rejects only one that moves *backwards*. Clone detection is deliberately not a
> property this feature claims — see `SECURITY.md`.

### Public Rate-Limited Access

Preauth can provide rate-limited unauthenticated access to select public
paths. This is useful for exposing public content (e.g., public repositories
in Gitea) without requiring TOTP authentication, while protecting server
resources from bot traffic.

When `PUBLIC_PATHS` is configured, requests to matching paths from
unauthenticated users are allowed through with a separate rate limiter.
Authenticated users bypass the public rate limiter entirely.

| Variable | Default | Description |
|----------|---------|-------------|
| `PUBLIC_PATHS` | `''` (disabled) | Comma-separated path patterns. See below. |
| `PUBLIC_BURST_COUNT` | `100` | Max requests per burst window per IP. |
| `PUBLIC_BURST_TIME` | `60` | Burst window in seconds. |
| `PUBLIC_UPPER_COUNT` | `500` | Max requests per sustained window per IP. |
| `PUBLIC_UPPER_TIME` | `3600` | Sustained window in seconds (1 hour). |

**Path pattern syntax:**

- Patterns are matched against the request path only (query string ignored).
- Patterns must start with `/`.
- `*` matches one or more characters within a single path segment (not crossing `/`).
- `**` matches zero or more characters including `/` (crosses path segments).
- An optional host prefix can restrict a pattern to a specific host
  (e.g., `code.example.com/public/**`).

| Pattern | Matches | Does NOT match |
|---------|---------|----------------|
| `/public` | `/public` | `/public/`, `/public/repo` |
| `/public/*` | `/public/repo` | `/public`, `/public/a/b` |
| `/public/**` | `/public/repo`, `/public/a/b/c` | `/public` |
| `host.com/api/**` | `host.com/api/v1/status` | `other.com/api/v1/status` |

**Example:** Allow public access to Gitea's `/public/` paths:

```env
PUBLIC_PATHS=/public/**
PUBLIC_BURST_COUNT=100
PUBLIC_BURST_TIME=60
PUBLIC_UPPER_COUNT=500
PUBLIC_UPPER_TIME=3600
```

When a visitor exceeds the rate limit, they receive a `429 Too Many Requests`
response with a `Retry-After` header. When within limits, they receive a
`200 OK` response (with no `Remote-User` header). Authenticated users receive
`200 OK` with their `Remote-User` header as normal.

### Styling

All UI text and colors are configurable:

| Variable | Default | Description |
|----------|---------|-------------|
| `TITLE` | `Pre-Authentication System` | Page title. |
| `BG_COLOR` | `#029386` | Background color. |
| `FG_COLOR` | `#ffffff` | Foreground (text) color. |
| `ERROR_COLOR` | `#ffb16d` | Error message color. |
| `ID_NAME` | `Session ID` | Label for the ID field. |
| `TOKEN_NAME` | `Authentication Token` | Label for the TOTP field. |
| `SUBMIT_NAME` | `Submit` | Submit button text. |
| `ERROR_MESSAGE` | `Unsuccessful login attempt` | Failed login message. |
| `TEAPOT_TITLE` | `I'm a teapot` | Title when rate-limited (teapot mode). |
| `TEAPOT_MESSAGE` | `I refuse to brew coffee` | Message when rate-limited (teapot mode). |
| `TOO_MANY_TITLE` | `Too many requests` | Title when rate-limited (non-teapot). |
| `TOO_MANY_MESSAGE` | `Try again later` | Message when rate-limited (non-teapot). |

## Architecture

```
Client → Caddy → forward_auth → Preauth listeners → 200/401/418
```

Preauth is entirely event-listener-driven (no controllers). Each request
passes through a priority-ordered chain of listeners:

1. **AcceptListener** (priority 99) — Checks for valid session cookie.
2. **AllowListener** (priority 88) — Checks for valid IP-based session.
3. **PublicAccessListener** (priority 84) — If public paths are configured,
   allows rate-limited unauthenticated access to matching paths.
4. **RejectListener** (priority 77) — Rate-limiting gate.
5. **PasskeyListener** (priority 70) — WebAuthn ceremonies, when enabled.
6. **LoginListener** (priority 66) — Processes login attempts.
7. **InterceptListener** (priority 55) — Renders login page or redirects.
8. **SecurityHeadersListener** (response) — Adds security headers.

`PasskeyListener` sits deliberately between the rate-limit gate and the login
handler: **after** `RejectListener`, so a blocked IP never reaches a ceremony;
and **before** `LoginListener`, because that listener treats any POST to the auth
subdomain as a login attempt, and a ceremony request carries no code — it would
otherwise be counted as a failed login and burn rate-limit budget on every
legitimate passkey sign-in.

### Security Model

- **Cookies**: `__Host-` prefixed, `SameSite=Strict`, `Secure`, `HttpOnly`
- **Nonces**: 15-byte random, single-use, 120-second TTL
- **TOTP**: ±1 period leeway (±30 seconds) for clock drift
- **Backup codes**: Case-insensitive, single-use, alphanumeric
- **Rate limiting**: Per-IP, compound sliding window, cannot be disabled
- **Security headers**: CSP, X-Frame-Options, X-Content-Type-Options,
  Referrer-Policy, HSTS
- **No cacheable login flow**: The login page, failed logins, redirects,
  and rate-limit pages are sent with strict anti-caching headers
  (`no-store, no-cache, must-revalidate, proxy-revalidate, max-age=0,
  s-maxage=0` plus `Pragma`, `Expires`, `Surrogate-Control`, and
  `Vary: *`), and the login form's `fetch()` opts out of the HTTP cache.
  Successful (2xx) responses are deliberately excluded — they are
  consumed by the proxy's `forward_auth` check and never reach the
  browser, so a protected service's own caching is not affected.
  Passkey ceremony replies are the one exception: they are the only 2xx
  this application returns straight to a browser, so they carry the same
  anti-caching headers.
- **Passkeys**: registerable only after a valid TOTP code; challenge is
  server-issued and single-use; RP ID is always the base domain; only the
  derived `https://` origin is ever accepted; a failed attempt is
  indistinguishable from a wrong code and shares its rate-limit budget.

### Cache

Preauth uses a dual-layer cache:
- **APCu** (in-memory) — Fast session and nonce lookups
- **Filesystem** — Persistent storage for container restarts

`MonitorCacheKeys` wraps the PSR-6 cache pool to track changes, so only
modified items are persisted to disk on shutdown.

## Development

### Code Style

This project follows [PSR-12](https://www.php-fig.org/psr/psr-12/) and
includes `php-cs-fixer` as a dev dependency.

```bash
# Check for style violations
vendor/bin/php-cs-fixer fix --dry-run --diff

# Auto-fix
vendor/bin/php-cs-fixer fix
```

### Running Tests

```bash
vendor/bin/phpunit
```

The test suite includes 293 tests with 100% code coverage (lines, methods,
and classes). Both unit tests and functional tests (full HTTP kernel flow)
are included.

### Requirements

- PHP 8.4+
- Composer
- Xdebug (for coverage reports)

## License

MIT — see `license.txt`.

## Project Status

Running in production since June 2024, protecting multiple self-hosted
services. The core authentication gate is complete and battle-tested.

See `ROADMAP.md` for planned features and `CHANGELOG.md` for version history.
