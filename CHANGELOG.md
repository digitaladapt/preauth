# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased] — v1.1

### Added
- **Public rate-limited access** — Select paths can now be made publicly
  accessible without TOTP authentication, with separate per-IP rate limiting.
  This is useful for exposing public content (e.g., public Gitea repositories)
  while protecting server resources from bot traffic.
  - New `PUBLIC_PATHS` env var: comma-separated path patterns with `*` (single
    segment) and `**` (cross-segment) wildcard support. Optional host prefix
    (e.g., `code.example.com/public/**`). When empty (default), the feature
    is fully disabled.
  - New `PUBLIC_BURST_COUNT` / `PUBLIC_BURST_TIME` env vars for burst rate
    limiting (default: 100 requests per 60 seconds).
  - New `PUBLIC_UPPER_COUNT` / `PUBLIC_UPPER_TIME` env vars for sustained
    rate limiting (default: 500 requests per 3600 seconds).
  - Authenticated users bypass the public rate limiter entirely.
  - Over-limit responses include a `Retry-After` header.
  - New `PublicPathMatcher` service for path pattern matching.
  - New `PublicAccessListener` (priority 84) in the request pipeline.

## [1.0.0] — v1.0 Release

### Security
- Made `Remote-User` header value configurable via `REMOTE_USER` environment
  variable with four modes: `session` (default), `static`, `mapped`, and `none`.
  This allows deployments to prevent user-controlled header values from reaching
  backend services.
- Added `SecurityHeadersListener` to set `X-Content-Type-Options`, `X-Frame-Options`,
  `Content-Security-Policy`, `Referrer-Policy`, and `Strict-Transport-Security`
  headers on all responses.
- Replaced `document.write()` with `document.documentElement.innerHTML` in login
  page JavaScript to avoid CSP violations.
- Added CSS escaping (`|e('css')`) to environment-configured color values in
  the login page template to prevent CSS injection.
- Documented CSRF protection model: the nonce system provides CSRF protection
  for POST form logins (server-generated, single-use, 120s TTL).
- Reduced TOTP verification window from 10 periods (±5 minutes) to 1 period
  (±30 seconds) to reduce brute-force attack surface.
- Removed hardcoded `APP_SECRET` from `bin/franken.sh` (now uses environment
  variable or generates a random secret).
- Removed backup code values from debug log output.
- Added `.env` to `.gitignore`.
- Expanded TLD list in `DomainManager` with many missing multi-part TLDs
  (`.com.au`, `.co.jp`, `.com.br`, `.co.kr`, `.com.tw`, `.co.za`, etc.)
  to prevent open redirect vulnerabilities from incorrect domain matching.
- Lowercased host before TLD lookup to fix case-sensitivity issue.

### Fixed
- Fixed `$payload->json` access on possibly-null `$payload` in `LoginListener`
  using null-safe operator (`?->`).
- Fixed `validReturn()` not checking `false` return from `parse_url()`, which
  could cause a `TypeError` on malformed URLs.
- Added `isHit()` race condition check in `AcceptListener` and `AllowListener`
  between `hasItem()` and `getItem()` calls.
- Added `try/finally` in `Kernel::terminate()` so `parent::terminate()` always
  runs even if `persist()` throws an exception.
- Added input validation to `GenerateBackupCodesCommand` — rejects count < 1.

### Changed
- Disabled unused Symfony sessions in `framework.yaml` (preauth implements its
  own cookie/cache-based session management).
- Standardized git tag format to use `v` prefix (`v1.0.0` instead of `1.0.0`).
- Updated CI workflows to use `v*.*.*` tag pattern and strip `v` prefix for
  Docker image tags.
- Removed stale `develop` branch from CI triggers.
- Fixed `publish.yaml` to use `git remote set-url` on re-runs instead of
  failing when the remote already exists.
- Explicitly install `curl` in the Docker final image (needed for healthcheck).
- Added `declare(strict_types=1)` to all interface files.
- Added `#[AsCommand]` attribute to `GenerateBackupCodesCommand`.
- Fixed `BackupCodeInterface` default count to match implementation (10).
- Used `Response::HTTP_INTERNAL_SERVER_ERROR` constant in `GetTotpTrait`
  instead of literal `500`.

## [0.10.0] - 2026-08-11

### Added
- PHP-CS-Fixer with PSR-12 configuration and CI check.

## [0.9.0] - 2026-07-15

### Added
- PHPUnit test suite — 222 tests, 100% code coverage (lines, methods, classes).

## [0.8.1] - 2026-05-30

### Fixed
- Bug fixes and cleanup from develop branch merge.

## [0.8.0] - 2026-05-29

### Changed
- Renamed form fields for clarity.
- Fixed invalid login bug.

## [0.7.0] - 2026-05-29

### Added
- Single-use backup codes via `app:generate-backup-codes` console command.
- Cache persistence improvement — only write changed keys to file storage.

### Removed
- Static password and lookup token (security risks).

### Changed
- Updated to PHP 8.5, updated dependencies.

## [0.6.0] - 2026-02-10

### Added
- Optional (disabled by default) ability to lookup token by static password.

## [0.5.0] - 2026-01-17

### Added
- Optional (disabled by default) ability to use a static password as backup auth.

### Changed
- Nonce-related cleanup.

## [0.4.1] - 2025-12-26

### Fixed
- Bug which can occur if cache files are deleted.

## [0.4.0] - 2025-12-26

### Changed
- Massive rewrite to listener-based architecture instead of controllers.
- Login payload sent via `X-Preauth` header instead of GET request parameters.
- Enhanced cookie security.
- Removed icon system and asset system.

## [0.3.0] - 2025-12-15

### Changed
- **Breaking:** Default port and transport changed to HTTP on port 80.
- **Breaking:** Environment variable names have changed.
- Refactored to Symfony 7.4 with FrankenPHP.

## [0.2.0] - 2025-12-03

### Added
- Login rate limiting (burst + upper window).
- Error page for rate-limited clients ("too many requests").
- Example Docker Compose file.

## [0.1.0] - 2025-11-14

### Added
- Docker image published to Docker Hub.
- PHP-FPM based, code in `src/`, templates in separate files.

## [0.0.1] - 2024-06-26

### Notes
- Started as a single-file script in Caddy config. Hardcoded TOTP secret,
  zero flexibility, but functional. Ran quietly in production for about a
  year before any real development began.
