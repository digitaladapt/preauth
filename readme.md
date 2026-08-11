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

Create a `.env` file (see `docs/example.env` for all options):

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

See `docs/compose.yaml` for an example Docker Compose file.

### 4. Configure Caddy

```caddyfile
service.example.com {
    forward_auth preauth {
        uri {uri}
        copy_headers Remote-User
    }
    reverse_proxy your-service:80
}
```

See `docs/Caddyfile` for more examples, including path-specific protection
and central auth subdomain configuration.

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

All configuration is via environment variables. See `docs/example.env`
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

### Rate Limiting

Rate limiting **cannot be disabled**. It uses a compound sliding window:

| Variable | Default | Description |
|----------|---------|-------------|
| `BURST_COUNT` | `2` | Max attempts per burst window. |
| `BURST_TIME` | `30` | Burst window in seconds. |
| `UPPER_COUNT` | `10` | Max attempts per upper window. |
| `UPPER_TIME` | `3600` | Upper window in seconds (1 hour). |

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
3. **RejectListener** (priority 77) — Rate-limiting gate.
4. **LoginListener** (priority 66) — Processes login attempts.
5. **InterceptListener** (priority 55) — Renders login page or redirects.
6. **SecurityHeadersListener** (response) — Adds security headers.

### Security Model

- **Cookies**: `__Host-` prefixed, `SameSite=Strict`, `Secure`, `HttpOnly`
- **Nonces**: 15-byte random, single-use, 120-second TTL
- **TOTP**: ±1 period leeway (±30 seconds) for clock drift
- **Backup codes**: Case-insensitive, single-use, alphanumeric
- **Rate limiting**: Per-IP, compound sliding window, cannot be disabled
- **Security headers**: CSP, X-Frame-Options, X-Content-Type-Options,
  Referrer-Policy, HSTS

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

The test suite includes 222 tests with 100% code coverage (lines, methods,
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
