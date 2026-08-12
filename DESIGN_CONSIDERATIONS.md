# Design Considerations — Preauth

## Summary

Preauth is a well-architected TOTP-based authentication gateway that has evolved from a single-file script into a clean, event-listener-driven Symfony application with 100% test coverage. The codebase demonstrates strong security fundamentals (host-prefixed cookies, nonce-based replay protection, rate limiting, backup code system) and thoughtful operational design (dual-layer cache with change tracking, FrankenPHP worker mode).

This document was originally prepared as a design review. Items that have been addressed are marked with ✅ and include a reference to the commit or change that resolved them. Items still open are marked with ⬜ and remain as recommendations for future work.

---

## 1. Security

### 1.1 Missing Security Response Headers [HIGH PRIORITY] ✅ Addressed

**Current state:** Fixed. A `SecurityHeadersListener` (response event, priority 0) now sets the following headers on all main-request responses:

```
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Content-Security-Policy: default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'
Referrer-Policy: strict-origin-when-cross-origin
Strict-Transport-Security: max-age=31536000
```

The inline `<script>` and `<style>` in the templates mean a CSP with `'unsafe-inline'` for `script-src` and `style-src` is the strictest practical policy today. Moving scripts/styles to external files would allow a stricter CSP in the future.

### 1.2 Remote-User Header Value is User-Controlled [HIGH PRIORITY] ✅ Addressed

**Current state:** Fixed. The `Remote-User` header value is now configurable via the `REMOTE_USER` environment variable, which supports four modes:

- **`session`** (default, backward-compatible): Sends the session id, as before. The value is still sanitized via `makeCacheKey()`.
- **`static`**: Sends a fixed string (configurable via `REMOTE_USER_STATIC`, default `authenticated`) for all authenticated requests. This eliminates the user-controlled header issue entirely.
- **`mapped`**: Looks up the session id in a configured map (`REMOTE_USER_MAP`, format: `id1:user1,id2:user2`) and sends the mapped value. Falls back to the session id if not found in the map. This is the path to multi-user support.
- **`none`**: Omits the `Remote-User` header entirely. Caddy's `forward_auth` still accepts the request based on the 200 status code.

The `RemoteUserMode` enum (`src/Enum/RemoteUserMode.php`) encapsulates the modes. `StringTrait::authSuccessResponse()` resolves the header value based on the configured mode, and `ConfigBag` handles parsing the map string and validating the mode (invalid values fall back to `session`). `AcceptListener` now receives `ConfigBag` as a constructor dependency to support this.

### 1.3 No CSRF Protection on POST Form Login [MEDIUM PRIORITY] ✅ Addressed

**Current state:** Resolved through documentation and analysis. The nonce system provides CSRF protection for the POST form path: nonces are server-generated, single-use, and have a 120-second TTL. An attacker cannot forge a POST request without first loading the login page to obtain a valid nonce, which requires being on the auth subdomain. The `LoginListener` class docblock and `login.html.twig` template comment now explicitly document this CSRF protection model. The AJAX (header) path embeds the nonce in the base64url payload.

### 1.4 TOTP Verification Leeway May Be Too Generous [LOW PRIORITY] ✅ Addressed

**Current state:** Fixed. The TOTP verification window has been reduced from 10 periods (±5 minutes) to 1 period (±30 seconds). With the default 30-second TOTP period, a code is now valid for at most 90 seconds (the current window plus one window on each side), down from the previous 50 seconds per window with 10-period leeway. The ROADMAP has been updated to reflect this change.

### 1.5 Backup Code Logging Reveals Code Value [LOW PRIORITY] ✅ Addressed

**Current state:** Fixed. The debug log in `BackupCodeManager::verifyAndConsume()` no longer includes the backup key name. It now logs only the hit/miss and valid/invalid status: `"checking backup code: HIT & VALID"` or `"checking backup code: miss & invalid"`.

### 1.6 TOTP Object Reconstructed on Every Verification [LOW PRIORITY] ⬜ Open

**Current state:** `GetTotpTrait::getTotp()` calls `OTHP\Factory::loadFromProvisioningUri()` on every invocation. This parses the OTP URI string and constructs a new TOTP object each time a token is verified.

**Note:** An attempt was made to memoize the TOTP object within the request cycle, but PHP 8.4's `readonly` class constraint prevents traits from defining mutable properties in `readonly` classes (`LoginManager` and `BackupCodeManager` are both `final readonly`). Resolving this would require either removing `readonly` from these classes, using a separate memoization service, or refactoring `GetTotpTrait` into a dedicated injectable service.

**Why:** This is a minor performance concern — URI parsing and TOTP object construction happen on every login attempt. In a FrankenPHP worker process that handles many requests, this adds unnecessary overhead. It's not a security issue, but it's an easy optimization if the readonly constraint is relaxed.

### 1.7 CSS Injection in Style Template [MEDIUM PRIORITY] ✅ Addressed

**Current state:** Fixed. Environment-configured color values (`bg_color`, `fg_color`, `error_color`) in `_style.html.twig` are now escaped with Twig's `|e('css')` filter to prevent CSS injection from malicious environment variable values.

### 1.8 $payload->json Access on Possibly-Null Payload [HIGH PRIORITY] ✅ Addressed

**Current state:** Fixed. In `LoginListener::onKernelRequest()`, the `$payload->json` access on a possibly-null `$payload` has been replaced with `$payload?->json ?? true`, and `$payload->id` with `$payload?->id ?? ''`. This prevents a crash when a login attempt is detected (e.g., via the `X-Preauth` header) but the payload is invalid (malformed base64, non-object JSON, etc.).

### 1.9 validReturn() Doesn't Check false from parse_url [MEDIUM PRIORITY] ✅ Addressed

**Current state:** Fixed. `DomainManager::validReturn()` now checks for `false` and empty string in addition to `null` when examining the return value of `parse_url($url, PHP_URL_HOST)`. This prevents a `TypeError` on malformed URLs that `filter_var(FILTER_VALIDATE_URL)` accepts but `parse_url` cannot parse.

### 1.10 Incomplete TLD List in DomainManager [HIGH PRIORITY] ✅ Addressed

**Current state:** Fixed. The TLD lookup table in `DomainManager` has been significantly expanded with many previously missing multi-part TLDs, including `.com.au`, `.co.jp`, `.com.br`, `.co.kr`, `.com.tw`, `.co.za`, and dozens more. Without these entries, domains like `evil.com.au` would incorrectly match `auth.example.com.au` (both would resolve to base `com.au`), creating an open redirect vulnerability. The host is also now lowercased before TLD lookup to fix a case-sensitivity issue.

---

## 2. Architecture & Code Quality

### 2.1 Trait-Based Dependency Injection Pattern [MEDIUM PRIORITY] ⬜ Open

**Current state:** Several traits (`HasLoggerTrait`, `GetTotpTrait`, `MakeNonceTrait`) use `#[Required]` attribute for setter injection into `readonly` classes. For example, `LoginManager` receives `$config`, `$logger`, and `$nonceCache` via traits rather than through its constructor. The constructor only accepts three parameters; the rest are wired via setter methods called by the service container after construction.

**Recommendation:** Move these dependencies into the constructors of the classes that use them. If multiple classes share the same dependencies, that's fine — PHP constructors can accept many parameters, and it makes the dependency graph explicit. Alternatively, create a shared `Dependencies` value object that bundles logger, config, and nonce cache.

**Why:** The trait-based setter injection pattern makes it non-obvious what dependencies a class has — you have to look at both the constructor and all the traits it uses. It also creates a temporal coupling issue: the object exists in a partially-constructed state between construction and setter calls. With `readonly` classes, this works only because the trait properties are declared in the trait, not the class, which is a subtle language detail that could confuse future maintainers. Standard constructor injection is more explicit, testable, and conventional in Symfony.

### 2.2 Duplicated Cookie Logic Between LoginManager and InterceptListener [MEDIUM PRIORITY] ✅ Addressed

**Current state:** Resolved. The duplicated cookie name and domain selection logic has been extracted into two shared methods on `CookieNameTrait`:

- `sessionCookieName(DomainInterface $domainManager): string` — Returns the appropriate cookie name (`__Host-Http-Preauth` or `__Http-Domain-Preauth`) based on whether central auth is active.
- `sessionCookieDomain(DomainInterface $domainManager, string $host): ?string` — Returns the cookie domain for central auth mode, or null for single-domain mode.

`LoginManager::setCookie()`, `AcceptListener::onKernelRequest()`, and `InterceptListener::pruneInvalidCookie()` all now use these shared methods. The fragile "changes here must be reflected in InterceptListener::pruneInvalidCookie()" comment has been removed.

### 2.3 MonitorCacheKeys Instantiated Multiple Times for Same Pool [MEDIUM PRIORITY] ⬜ Open

**Current state:** `MonitorCacheKeys` is a decorator that tracks cache key changes. It's instantiated independently in `PersistCache`, `LoginManager`, and `BackupCodeManager`, each wrapping the same underlying `CacheItemPoolInterface`. The key list (`__key_list`) and change list (`__chg_list`) are stored in the cache itself, so the instances share state — but each instance calls `initialize()` in its constructor if the lists don't exist yet, and each `save()`/`saveDeferred()` call triggers additional metadata writes.

**Recommendation:** Register `MonitorCacheKeys` as a decorated service in the DI container (using Symfony's `decorates` feature) so there's a single instance per cache pool. Or, make `MonitorCacheKeys` a stateless service that's injected once, rather than having each consumer create its own wrapper.

**Why:** Multiple instances wrapping the same pool is wasteful — each `save()` call triggers a cascade of metadata operations (update key list, log change, commit). With three instances, a single cache write could trigger nine additional cache operations. A single decorator service would be more efficient and would make the lifecycle clearer.

### 2.4 Payload Base64url Decoding Has Broken Padding [MEDIUM PRIORITY] ✅ Already Correct

**Current state:** Not an issue. The code correctly uses:
```php
$base64 = strtr($base64url, '-_', '+/');
$base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
```
This was fixed in a prior commit ("Fix docs, add .dockerignore, fix base64url padding, fix typo"). The original review incorrectly reported the use of `str_pad`; the implementation now correctly uses `str_repeat` to add the proper number of `=` padding characters.

### 2.5 Symfony Sessions Enabled But Unused [LOW PRIORITY] ✅ Addressed

**Current state:** Fixed. `config/packages/framework.yaml` now has `session: false` with a comment explaining that preauth implements its own cookie/cache-based session management and does not use Symfony's session subsystem.

### 2.6 config/reference.php Committed to Repository [LOW PRIORITY] ✅ Already Handled

**Current state:** Not an issue. `config/reference.php` is already listed in `.gitignore` under the project-specific section and is not tracked in version control.

### 2.7 Public Properties on Payload DTO [LOW PRIORITY] ⬜ Open

**Current state:** `Payload` uses public properties (`$id`, `$token`, `$nonce`, `$json`, `$scope`) with no encapsulation. The object is mutable after construction.

**Recommendation:** Consider making `Payload` a `readonly` class (PHP 8.4+ supports `readonly` classes natively) with a constructor that takes all fields, or use Symfony's `Stringable`/value object patterns. Since `LoginManager` mutates `$payload->scope` (downgrading IP to Cookie), the current design requires mutability — but this could be handled by returning a new instance instead.

**Why:** Immutable DTOs are safer to pass around, especially in an event-driven system where the same object might be referenced by multiple listeners. The current mutation in `LoginManager::checkToken()` (changing `$payload->scope`) is a side effect that's not obvious from the method signature.

### 2.8 Duplicated "hi $id" Response Construction [LOW PRIORITY] ✅ Addressed

**Current state:** Fixed. The duplicated `new Response("hi $id", headers: ['Content-Type' => 'text/plain', 'Remote-User' => $id])` pattern in `AcceptListener`, `AllowListener`, and `LoginManager` has been extracted into `StringTrait::authSuccessResponse(string $id): Response`, which all three classes now use.

### 2.9 Duplicated Constants [LOW PRIORITY] ✅ Addressed

**Current state:** Fixed. The duplicated `'2999-12-31'` far-future date string (previously in `Utilities::makeTotp()` and `BackupCodeManager::verifyAndConsume()`/`saveCodes()`) and the `128` max input length (previously in `StringTrait::makeCacheKey()` and `Payload::create()`) have been extracted into `AppConstants::FAR_FUTURE_DATE` and `AppConstants::MAX_INPUT_LENGTH` respectively.

---

## 3. Testing

### 3.1 No Tests for Concurrent Access / Race Conditions [LOW PRIORITY] ⬜ Open

**Current state:** The test suite is excellent — 222 tests, 100% coverage, good edge case coverage. However, there are no tests for concurrent access scenarios, such as two requests using the same nonce simultaneously, or cache initialization race conditions in `MonitorCacheKeys`.

**Recommendation:** Add a few integration tests that simulate concurrent access (e.g., using process forks or mock caches with delays). At minimum, document that concurrent access is expected to be handled by APCu's atomic operations.

**Why:** `MonitorCacheKeys::initialize()` checks if key lists exist and creates them if not — under concurrent startup, two instances could both see missing lists and both call `initialize()`. This is likely fine because APCu operations are atomic, but it's worth having a test or at least a documented assumption. The race condition between `hasItem()` and `getItem()` in `AcceptListener` and `AllowListener` is now handled with an `isHit()` check, but is not tested.

### 3.2 No Security-Focused Test Suite [LOW PRIORITY] ⬜ Open

**Current state:** Security behaviors (nonce replay, backup code reuse, rate limiting) are tested as part of the functional and unit tests, but there's no dedicated security test suite that systematically probes for common vulnerabilities.

**Recommendation:** Consider adding a `tests/Security/` directory with tests for: XSS attempts in the username field, header injection via the `return` parameter, cookie attribute verification (Secure, HttpOnly, SameSite), and response header presence (now that security headers are added).

**Why:** For an authentication gateway, security testing deserves its own focused suite that's easy to find and extend. This also makes it easier for security reviewers to understand what's been tested.

---

## 4. Docker & Deployment

### 4.1 Healthcheck Depends on curl Which May Not Be Installed [MEDIUM PRIORITY] ✅ Addressed

**Current state:** Fixed. The Dockerfile now explicitly installs `curl` in the final image with `apt-get install -y --no-install-recommends curl` and cleans up the apt lists to keep the image small.

### 4.2 Typo in bin/franken.sh [LOW PRIORITY] ✅ Already Fixed / Addressed

**Current state:** Fixed. The typo (`digtialadapt` → `digitaladapt`) was corrected in a prior commit. The script has since been further improved: the hardcoded `APP_SECRET` has been removed (now uses the `APP_SECRET` environment variable or generates a random secret), and the `docker container rm` command now suppresses errors when the container doesn't exist.

### 4.3 No .dockerignore File [LOW PRIORITY] ✅ Already Handled

**Current state:** Not an issue. A `.dockerignore` file exists and excludes `.git/`, `.gitignore`, `var/`, `vendor/`, `tests/`, `.phpunit.cache/`, `docs/`, `*.md`, `.env`, `.env.test`, `.env.local`, and `composer.phar` from the Docker build context. This was added in a prior commit.

### 4.4 Dockerfile Uses PHP 8.5 Which Is Bleeding Edge [LOW PRIORITY] ⬜ Open (Deliberate)

**Current state:** The Dockerfile uses `php:8.5-trixie` for the build stage and `dunglas/frankenphp:php8.5-trixie` for the final image. `composer.json` requires `php >= 8.4`. The CI workflow in `tests.yaml` also uses PHP 8.5.

**Recommendation:** This is a deliberate choice and likely fine for a personal project. If broader compatibility is desired, consider testing against both PHP 8.4 and 8.5 in CI. The `composer.json` already allows 8.4+.

---

## 5. Error Handling

### 5.1 Cache Exceptions Propagate as 500 Errors [MEDIUM PRIORITY] ✅ Addressed

**Current state:** Fixed. Cache operations in `AcceptListener::onKernelRequest()` and `AllowListener::onKernelRequest()` are now wrapped in try/catch blocks that catch `Psr\Cache\InvalidArgumentException`. On a cache error, the listener logs the error at `error` level and returns without setting a response — causing the request to fall through to the next listener, which will eventually present the login page. This is a "fail closed" approach: if the cache is unavailable, the user is not authenticated.

**Note:** `LoginManager::checkToken()` and `BackupCodeManager::verifyAndConsume()` still declare `@throws InvalidArgumentException`. These are called from `LoginListener`, which does not catch the exception. A cache failure during login verification would still result in a 500 error. This is a lower-priority concern since login failures already result in a 401 response path.

### 5.2 No Global Exception Handling for Auth Flow [LOW PRIORITY] ⬜ Open

**Current state:** There is no `ExceptionListener` or `ErrorController` configured. Symfony's default error handling will produce a generic error page for uncaught exceptions. In dev mode (`APP_DEBUG=1`), this shows a full stack trace.

**Recommendation:** Add a simple exception listener that catches exceptions from the auth flow and returns a clean 401 or 503 response with the login page or error template. Alternatively, configure `framework.error_controller` to use a custom controller that renders the error template.

**Why:** For an auth gateway, every response should be intentional. A raw Symfony error page (even in production mode) doesn't match the styled login/error pages and could leak information about the internal architecture.

### 5.3 Kernel::terminate() Not Using try/finally [MEDIUM PRIORITY] ✅ Addressed

**Current state:** Fixed. `Kernel::terminate()` now wraps `$this->persistCache->persist()` in a `try` block with a `finally` block that calls `parent::terminate()`. This ensures that the Symfony kernel termination always runs, even if the cache persistence throws an exception.

---

## 6. Frontend

### 6.1 document.write() in Login Script [MEDIUM PRIORITY] ✅ Addressed

**Current state:** Fixed. The `document.open(); document.write(html); document.close();` pattern in `_script.html.twig` has been replaced with `document.documentElement.innerHTML = html;`. This avoids the deprecated `document.write()` call and is compatible with the Content-Security-Policy now set by `SecurityHeadersListener`.

### 6.2 No Input Sanitization in Username Echo [LOW PRIORITY] ⬜ Open

**Current state:** In `login.html.twig`, the username is echoed back into the input value: `value="{{ username }}"`. The username comes from the sanitized `makeCacheKey()` output, which restricts to `[A-Za-z0-9_.]`, so HTML injection is not possible with the current sanitization. Twig's auto-escaping is also on by default.

**Recommendation:** Add Twig's `escape` filter explicitly for defense-in-depth: `value="{{ username|e('html_attr') }}"`. Also consider whether the `message` variable in `<p id="preauth-message">{{ message|default }}</p>` could ever contain user input.

**Why:** While the current sanitization prevents XSS, relying on `makeCacheKey()` for HTML safety is an implicit coupling between cache key logic and output safety. If `makeCacheKey()` were ever relaxed to allow more characters, the template would become vulnerable. Twig auto-escaping handles HTML body context, but `html_attr` escaping is more appropriate for attribute contexts.

---

## 7. Configuration

### 7.1 No Validation of Environment Variables [LOW PRIORITY] ✅ Addressed

**Current state:** Fixed. Environment variables in `config/services.yaml` now use Symfony's env var processors for type casting:

- `app.cookie_ttl: '%env(int:COOKIE_TTL)%'`
- `app.subdomain_redirect: '%env(bool:SUBDOMAIN_REDIRECT)%'`
- `app.ip_ttl: '%env(int:IP_TTL)%'`
- `app.teapot: '%env(bool:TEAPOT)%'`

This ensures invalid values fail fast at container compilation rather than at runtime with a confusing type error. The `rate_limiter.yaml` already used `%env(int:...)%` — this pattern is now applied consistently.

### 7.2 APP_SECRET Not Used Meaningfully [LOW PRIORITY] ✅ Addressed (Documented)

**Current state:** `APP_SECRET` is configured in `framework.yaml` and is required by Symfony. Preauth doesn't use Symfony sessions (now explicitly disabled), CSRF tokens, or signed cookies — the main uses of `APP_SECRET`. The README now documents that `APP_SECRET` is a Symfony requirement and that session cookies are random ULIDs looked up in cache, not signed tokens. The hardcoded `APP_SECRET` in `bin/franken.sh` has also been removed.

---

## 8. Documentation

### 8.1 Missing Security Model Documentation [MEDIUM PRIORITY] ✅ Addressed

**Current state:** Addressed. The README now includes a comprehensive "Security Model" section under "Architecture" that covers:

- Cookie security attributes (`__Host-` prefix, `SameSite=Strict`, `Secure`, `HttpOnly`)
- Nonce system (15-byte random, single-use, 120s TTL)
- TOTP verification window (±1 period / ±30 seconds)
- Backup codes (case-insensitive, single-use, alphanumeric)
- Rate limiting (per-IP, compound sliding window, cannot be disabled)
- Security headers (CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, HSTS)

A dedicated `docs/SECURITY.md` with the full threat model and `Remote-User` guidance (see item 1.2) could still be valuable as a standalone document.

### 8.2 Missing CHANGELOG.md [MEDIUM PRIORITY] ✅ Addressed

**Current state:** Fixed. A `CHANGELOG.md` has been created following the [Keep a Changelog](https://keepachangelog.com/) format, with full version history from v0.0.1 through the unreleased v1.0 changes. The version history was previously inline in the README.

### 8.3 Missing CONTRIBUTING.md [LOW PRIORITY] ✅ Addressed

**Current state:** Fixed. A `CONTRIBUTING.md` has been created with development setup instructions, code style guidelines, testing requirements, PR process, commit message conventions, and architecture overview.

### 8.4 Stale Branch References in ROADMAP [LOW PRIORITY] ✅ Addressed

**Current state:** Fixed. The ROADMAP's branch status table has been updated to reflect that all feature branches have been pruned and development uses a feature-branch + PR workflow into `main`. Completed security review items are now checked off, and the TOTP leeway description has been updated from "10-second leeway" to "±1 period leeway (±30 seconds)".

---

## 9. CI & Workflows

### 9.1 Inconsistent Tag Format [MEDIUM PRIORITY] ✅ Addressed

**Current state:** Fixed. Git tags are now standardized on the `v` prefix (e.g., `v1.0.0` instead of `1.0.0`). The Docker workflow (`docker.yaml`) now triggers on `v*.*.*` tag patterns and includes a step to extract the version number without the `v` prefix for the Docker image tag. The existing un-prefixed tags (`0.7.0` through `0.10.0`) remain in the repository but all future releases will use the `v` prefix.

### 9.2 Stale develop Branch in CI Triggers [LOW PRIORITY] ✅ Addressed

**Current state:** Fixed. The `tests.yaml` and `develop.yaml` workflows no longer reference the `develop` branch, which has been pruned. CI now triggers on `main` only (for push) and `main` only (for pull requests).

### 9.3 publish.yaml Fails on Re-run [LOW PRIORITY] ✅ Addressed

**Current state:** Fixed. The GitHub sync workflow (`publish.yaml`) now uses `git remote add ... 2>/dev/null || git remote set-url ...` instead of bare `git remote add`, which would fail if the remote already existed from a previous run.

---

## What's Done Well

- **Listener-based architecture** is a good fit for this use case — each listener has a single responsibility, and the priority chain creates a clear request processing pipeline.
- **Cookie security** is excellent: `__Host-` prefix, `Secure`, `HttpOnly`, `SameSite=Strict`, and a separate non-prefixed cookie for domain-scoped central auth. Cookie name and domain selection logic is now shared via `CookieNameTrait::sessionCookieName()` and `sessionCookieDomain()`.
- **Nonce-based replay protection** with single-use, TTL-limited nonces and collision retry is well-designed. The nonce also serves as CSRF protection for the POST form path.
- **Rate limiting** with compound sliding windows (burst + sustained) and the humorous teapot option is practical and well-implemented.
- **Test suite** is exemplary: 100% coverage, good use of test helpers, functional tests that exercise the full kernel, and edge cases like ULID collisions and nonce reuse.
- **Dual-layer cache** (APCu + filesystem with change tracking) is a clever solution for persistence without a database.
- **Backup code system** with single-use enforcement, case-insensitivity, and audit trail (keeping consumed codes with `false` value) is well thought out. Backup code values are no longer logged.
- **Interfaces** (`LoginInterface`, `DomainInterface`, `BackupCodeInterface`) enable clean mocking in tests. All now have `declare(strict_types=1)`.
- **FrankenPHP worker mode** via the Caddyfile and Dockerfile is a modern, performant serving strategy.
- **Security headers** are now set on all responses via `SecurityHeadersListener`.
- **Error handling** in cache-dependent listeners now fails closed (denies access on cache errors) rather than propagating 500 errors.

---

*Originally prepared as a design review. Updated to reflect the state of the `fix/v1.0-must-fix` branch.*
