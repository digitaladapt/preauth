# Design Considerations — Preauth

## Summary

Preauth is a well-architected TOTP-based authentication gateway that has evolved from a single-file script into a clean, event-listener-driven Symfony application with 100% test coverage. The codebase demonstrates strong security fundamentals (host-prefixed cookies, nonce-based replay protection, rate limiting, backup code system) and thoughtful operational design (dual-layer cache with change tracking, FrankenPHP worker mode). The following observations focus on areas where modern best practices could further strengthen the project, organized by category and prioritized by impact.

---

## 1. Security

### 1.1 Missing Security Response Headers [HIGH PRIORITY]

**Current state:** Responses are sent without standard security headers. There is no `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Content-Security-Policy`, `Referrer-Policy`, or `Permissions-Policy` header on any response — whether the login page HTML, JSON API responses, or plain-text auth-success responses.

**Suggestion:** Add a simple event listener (or a `ResponseEvent` subscriber) that sets these headers on all responses. A baseline set would be:
```
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Content-Security-Policy: default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'
Referrer-Policy: no-referrer
```

**Why:** As an authentication gateway, preauth's responses are seen by every unauthenticated client. Without `X-Frame-Options: DENY`, the login page could be embedded in an iframe for clickjacking. Without `X-Content-Type-Options: nosniff`, browsers may MIME-sniff responses and misinterpret content. The inline `<script>` and `<style>` in the templates mean a CSP with `'unsafe-inline'` for script-src and style-src is the strictest practical policy today; moving scripts/styles to external files would allow a stricter CSP later. This is low-effort, high-value hardening.

### 1.2 Remote-User Header Value is User-Controlled [HIGH PRIORITY]

**Current state:** The `Remote-User` header sent back to Caddy (and forwarded to the protected backend) is set to the `id` field from the user's login payload. This value is sanitized via `makeCacheKey()` (which restricts to `[A-Za-z0-9_.]` and truncates to 128 chars), but it is otherwise arbitrary — a user who passes TOTP authentication can set their `Remote-User` to `admin`, `root`, or any other value.

**Suggestion:** If this is a single-user gate (which it currently is), document this explicitly: "The Remote-User header identifies the session, not a system user. Backend services must not use it for authorization decisions." If multi-user support is added (as planned in the ROADMAP), the `id` field should be validated against a registered user list before being echoed as `Remote-User`.

**Why:** A backend service that trusts `Remote-User` for access control (e.g., granting admin privileges to `Remote-User: admin`) would be trivially exploitable by any authenticated preauth user. This is the most important architectural caveat to document, even if it's intentional for the current single-user model.

### 1.3 No CSRF Protection on POST Form Login [MEDIUM PRIORITY]

**Current state:** When using the auth subdomain mode, the login form submits via `method="post"`. There is no CSRF token. The nonce system provides some replay protection, but a malicious site could craft a POST form submission to the auth subdomain (a "login CSRF" attack), potentially logging the victim into the attacker's session.

**Suggestion:** Add a CSRF token to the POST form (Symfony's CSRF component is available via `framework.csrf_protection`). Alternatively, since the AJAX-based flow already works without POST, consider whether the POST form is necessary — it could be replaced with a JavaScript-disabled fallback that still uses the `X-Preauth` header.

**Why:** Login CSRF is a real attack vector where an attacker submits a login form on behalf of a victim, potentially causing the victim to use the attacker's session. The SameSite=Strict cookie helps, but the POST form itself has no CSRF defense. The ROADMAP already identifies this gap.

### 1.4 TOTP Verification Leeway May Be Too Generous [LOW PRIORITY]

**Current state:** TOTP verification uses `$this->getTotp()->verify($payload->token, null, 10)`, which sets a 10-second leeway. With the default 30-second TOTP period, this means a code is valid for up to 50 seconds (the current window plus 10 seconds on each side). 

**Suggestion:** Consider reducing the leeway to 5 seconds (one window of ±5s), or at minimum document why 10 seconds was chosen. The OTPHP library default leeway is 0, and RFC 6238 doesn't mandate a specific leeway.

**Why:** A 50-second validity window per code gives an attacker more time to brute-force or replay a intercepted code. Since the rate limiter allows 2 attempts per 30 seconds, a 10-second leeway means up to 4 different codes could be valid at any moment. For a personal auth gate this is likely acceptable, but it's worth reviewing.

### 1.5 Backup Code Logging Reveals Code Value [LOW PRIORITY]

**Current state:** In `BackupCodeManager::verifyAndConsume()`, the debug log includes the full backup key name: `"checking backup code 'backup_abc123': HIT & VALID"`. While this is at debug level and the key includes a `backup_` prefix, the actual code value is embedded in the log message.

**Suggestion:** Log only the first few characters or a hash of the code, even at debug level. For example: `"checking backup code 'backup_ab...': HIT & VALID"`.

**Why:** If debug logging is enabled in production (by setting `SHELL_VERBOSITY=3`), backup codes would be written to logs in plaintext. Since backup codes are security credentials, logging them — even at debug level — is a risk if logs are shared, shipped to a logging service, or stored long-term.

### 1.6 TOTP Object Reconstructed on Every Verification [LOW PRIORITY]

**Current state:** `GetTotpTrait::getTotp()` calls `OTHP\Factory::loadFromProvisioningUri()` on every invocation. This parses the OTP URI string and constructs a new TOTP object each time a token is verified.

**Suggestion:** Cache the TOTP object instance (e.g., as a memoized property in the trait, or as a shared service).

**Why:** This is a minor performance concern — URI parsing and TOTP object construction happen on every login attempt. In a FrankenPHP worker process that handles many requests, this adds unnecessary overhead. It's not a security issue, but it's an easy optimization.

---

## 2. Architecture & Code Quality

### 2.1 Trait-Based Dependency Injection Pattern [MEDIUM PRIORITY]

**Current state:** Several traits (`HasLoggerTrait`, `GetTotpTrait`, `MakeNonceTrait`) use `#[Required]` attribute for setter injection into `readonly` classes. For example, `LoginManager` receives `$config`, `$logger`, and `$nonceCache` via traits rather than through its constructor. The constructor only accepts three parameters; the rest are wired via setter methods called by the service container after construction.

**Suggestion:** Move these dependencies into the constructors of the classes that use them. If multiple classes share the same dependencies, that's fine — PHP constructors can accept many parameters, and it makes the dependency graph explicit. Alternatively, create a shared `Dependencies` value object that bundles logger, config, and nonce cache.

**Why:** The trait-based setter injection pattern makes it non-obvious what dependencies a class has — you have to look at both the constructor and all the traits it uses. It also creates a temporal coupling issue: the object exists in a partially-constructed state between construction and setter calls. With `readonly` classes, this works only because the trait properties are declared in the trait, not the class, which is a subtle language detail that could confuse future maintainers. Standard constructor injection is more explicit, testable, and conventional in Symfony.

### 2.2 Duplicated Cookie Logic Between LoginManager and InterceptListener [MEDIUM PRIORITY]

**Current state:** `LoginManager::setCookie()` and `InterceptListener::pruneInvalidCookie()` contain mirrored logic for determining the cookie name and domain. The code even includes a comment: `"changes here must be reflected in InterceptListener::pruneInvalidCookie()"`. Both methods check `$this->domainManager->authBase()` to decide between `__Host-Http-Preauth` and `__Http-Domain-Preauth`, and both use `$this->domainManager->matchesAuth($host)` for the domain attribute.

**Suggestion:** Extract cookie creation and clearing into a dedicated `CookieManager` service (or a method on `DomainManager`) that both classes can call. This eliminates the duplication and the risk of them getting out of sync.

**Why:** The "remember to update both places" pattern is fragile. If someone adds a new cookie attribute (e.g., `SameSite=Lax` for a specific mode) and only updates one location, the login and cookie-pruning flows would diverge, potentially causing subtle bugs like cookies that can't be cleared.

### 2.3 MonitorCacheKeys Instantiated Multiple Times for Same Pool [MEDIUM PRIORITY]

**Current state:** `MonitorCacheKeys` is a decorator that tracks cache key changes. It's instantiated independently in `PersistCache`, `LoginManager`, and `BackupCodeManager`, each wrapping the same underlying `CacheItemPoolInterface`. The key list (`__key_list`) and change list (`__chg_list`) are stored in the cache itself, so the instances share state — but each instance calls `initialize()` in its constructor if the lists don't exist yet, and each `save()`/`saveDeferred()` call triggers additional metadata writes.

**Suggestion:** Register `MonitorCacheKeys` as a decorated service in the DI container (using Symfony's `decorates` feature) so there's a single instance per cache pool. Or, make `MonitorCacheKeys` a stateless service that's injected once, rather than having each consumer create its own wrapper.

**Why:** Multiple instances wrapping the same pool is wasteful — each `save()` call triggers a cascade of metadata operations (update key list, log change, commit). With three instances, a single cache write could trigger nine additional cache operations. A single decorator service would be more efficient and would make the lifecycle clearer.

### 2.4 Payload Base64url Decoding Has Broken Padding [MEDIUM PRIORITY]

**Current state:** `Payload::decode()` attempts to restore base64 padding with:
```php
str_pad(strtr($base64url, '-_', '+/'), strlen($base64url) % 4, '=')
```
The second argument to `str_pad` is the desired *total length* of the output, not the number of padding characters. Since `strlen($base64url) % 4` is always 0–3 and the string is already much longer than that, `str_pad` is a no-op — no padding is ever added.

**Suggestion:** Replace with correct padding:
```php
$base64 = strtr($base64url, '-_', '+/');
$base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
```

**Why:** This works today only because PHP's `base64_decode()` is lenient about missing padding (even in strict mode). But the code's intent is clearly to add padding, and the implementation is wrong. If a future PHP version tightens `base64_decode` behavior, or if the code is ported, this would break. It's also a correctness issue that could confuse reviewers.

### 2.5 Symfony Sessions Enabled But Unused [LOW PRIORITY]

**Current state:** `config/packages/framework.yaml` has `session: true`, which enables Symfony's session subsystem. However, preauth implements its own cookie-based session management entirely through cache lookups — it never reads from or writes to Symfony's session.

**Suggestion:** Set `session: false` (or remove the `session` key) in `framework.yaml`. If session is needed for a future feature (e.g., CSRF tokens), it can be re-enabled at that time.

**Why:** Enabling sessions adds overhead (session cookie middleware, session storage initialization) that isn't used. It also creates a `PHPSESSID` cookie on responses that include session-related operations, which could confuse the auth flow or create an unexpected cookie surface area for a security-focused application.

### 2.6 config/reference.php Committed to Repository [LOW PRIORITY]

**Current state:** `config/reference.php` is an 844-line auto-generated file produced by Symfony Flex. It provides IDE autocompletion for configuration but changes between Symfony versions and adds noise to diffs.

**Suggestion:** Add `config/reference.php` to `.gitignore`. It will be regenerated by Symfony Flex when dependencies are installed.

**Why:** Auto-generated files in version control create unnecessary diff noise when Symfony is updated. The file provides no runtime value — it's only for developer experience and is regenerated automatically. Most Symfony projects gitignore it.

### 2.7 Public Properties on Payload DTO [LOW PRIORITY]

**Current state:** `Payload` uses public properties (`$id`, `$token`, `$nonce`, `$json`, `$scope`) with no encapsulation. The object is mutable after construction.

**Suggestion:** Consider making `Payload` a `readonly` class (PHP 8.4+ supports `readonly` classes natively) with a constructor that takes all fields, or use Symfony's `Stringable`/value object patterns. Since `LoginManager` mutates `$payload->scope` (downgrading IP to Cookie), the current design requires mutability — but this could be handled by returning a new instance instead.

**Why:** Immutable DTOs are safer to pass around, especially in an event-driven system where the same object might be referenced by multiple listeners. The current mutation in `LoginManager::checkToken()` (changing `$payload->scope`) is a side effect that's not obvious from the method signature.

---

## 3. Testing

### 3.1 No Tests for Concurrent Access / Race Conditions [LOW PRIORITY]

**Current state:** The test suite is excellent — 222 tests, 100% coverage, good edge case coverage. However, there are no tests for concurrent access scenarios, such as two requests using the same nonce simultaneously, or cache initialization race conditions in `MonitorCacheKeys`.

**Suggestion:** Add a few integration tests that simulate concurrent access (e.g., using process forks or mock caches with delays). At minimum, document that concurrent access is expected to be handled by APCu's atomic operations.

**Why:** `MonitorCacheKeys::initialize()` checks if key lists exist and creates them if not — under concurrent startup, two instances could both see missing lists and both call `initialize()`. This is likely fine because APCu operations are atomic, but it's worth having a test or at least a documented assumption.

### 3.2 No Security-Focused Test Suite [LOW PRIORITY]

**Current state:** Security behaviors (nonce replay, backup code reuse, rate limiting) are tested as part of the functional and unit tests, but there's no dedicated security test suite that systematically probes for common vulnerabilities.

**Suggestion:** Consider adding a `tests/Security/` directory with tests for: XSS attempts in the username field, header injection via the `return` parameter, cookie attribute verification (Secure, HttpOnly, SameSite), and response header presence (once security headers are added).

**Why:** For an authentication gateway, security testing deserves its own focused suite that's easy to find and extend. This also makes it easier for security reviewers to understand what's been tested.

---

## 4. Docker & Deployment

### 4.1 Healthcheck Depends on curl Which May Not Be Installed [MEDIUM PRIORITY]

**Current state:** The Dockerfile's `HEALTHCHECK` uses `curl http://localhost || exit 1`. The final image is `dunglas/frankenphp:php8.5-trixie`. While the build stage installs `unzip` and `git`, neither `curl` nor `wget` is explicitly installed in the final image. Whether curl is available depends on the base image's pre-installed packages.

**Suggestion:** Either install `curl` explicitly in the final image, or use a PHP-based healthcheck: `php -r 'exit(@file_get_contents("http://localhost/") ? 0 : 1);'`. Better yet, add a dedicated `/health` route that returns 200 OK only when the cache is operational.

**Why:** If curl is not in the final image, the healthcheck will always fail, causing Docker/orchestration tools to mark the container as unhealthy and potentially restart it. This is a deployment reliability issue.

### 4.2 Typo in bin/franken.sh: "digtialadapt" [LOW PRIORITY]

**Current state:** `bin/franken.sh` contains `docker build . -t digtialadapt/preauth:dev` — "digtialadapt" instead of "digitaladapt". The Docker Hub image name is `digitaladapt/preauth` (as seen in `docs/compose.yaml`).

**Suggestion:** Fix the typo. Also consider removing this script from the repo since it's a personal dev utility, or move it to a `bin/dev/` directory with a note that it's not for production use.

**Why:** Anyone using this script as a template for local development would build the wrong image tag, and the container would fail to pull in other contexts.

### 4.3 No .dockerignore File [LOW PRIORITY]

**Current state:** There is no `.dockerignore` file. The Docker build context includes `vendor/`, `.git/`, `var/`, `tests/`, `docs/`, and other files not needed in the Docker image.

**Suggestion:** Add a `.dockerignore` that excludes at least: `.git/`, `vendor/`, `var/`, `tests/`, `.phpunit.cache/`, `docs/`, `*.md`, `.env`, `.env.test`.

**Why:** Without `.dockerignore`, the Docker build context is unnecessarily large, slowing down builds and potentially leaking sensitive local configuration (like `.env` with a real `TOTP_URI`) into the build context. While the Dockerfile only `COPY`s specific directories, the entire context is still sent to the Docker daemon.

### 4.4 Dockerfile Uses PHP 8.5 Which Is Bleeding Edge [LOW PRIORITY]

**Current state:** The Dockerfile uses `php:8.5-trixie` for the build stage and `dunglas/frankenphp:php8.5-trixie` for the final image. `composer.json` requires `php >= 8.4`. The CI workflow in `tests.yaml` also uses PHP 8.5.

**Suggestion:** This is a deliberate choice and likely fine for a personal project. If broader compatibility is desired, consider testing against both PHP 8.4 and 8.5 in CI. The `composer.json` already allows 8.4+.

**Why:** PHP 8.5 is very new. Using it as both the development and production runtime means fewer community resources and potential early-bug issues. However, since the project explicitly targets 8.4+ and uses modern PHP features (readonly classes, enum, etc.), this is a reasonable choice — just worth being aware of.

---

## 5. Error Handling

### 5.1 Cache Exceptions Propagate as 500 Errors [MEDIUM PRIORITY]

**Current state:** Most methods declare `@throws InvalidArgumentException` from PSR-6 cache operations. If a cache operation fails (e.g., APCu is full, filesystem is read-only), the exception propagates up through the listener to Symfony's default error handler, resulting in a 500 Internal Server Error.

**Suggestion:** Add try-catch blocks in the listeners (or a global exception handler) that catch cache exceptions and return an appropriate error response. For an auth gateway, the safe default should be to deny access (return 401 or 503) rather than expose a 500 error.

**Why:** A cache failure should not crash the auth gateway. If APCu is unavailable, the gateway should fail closed (deny access) with a clean error page, not a Symfony stack trace. This is especially important in production where `APP_DEBUG=0` will show a generic error page, but the behavior should be explicit, not incidental.

### 5.2 No Global Exception Handling for Auth Flow [LOW PRIORITY]

**Current state:** There is no `ExceptionListener` or `ErrorController` configured. Symfony's default error handling will produce a generic error page for uncaught exceptions. In dev mode (`APP_DEBUG=1`), this shows a full stack trace.

**Suggestion:** Add a simple exception listener that catches exceptions from the auth flow and returns a clean 401 or 503 response with the login page or error template. Alternatively, configure `framework.error_controller` to use a custom controller that renders the error template.

**Why:** For an auth gateway, every response should be intentional. A raw Symfony error page (even in production mode) doesn't match the styled login/error pages and could leak information about the internal architecture.

---

## 6. Frontend

### 6.1 document.write() in Login Script [MEDIUM PRIORITY]

**Current state:** The `_script.html.twig` template uses `document.write(html)` to replace the entire page content when an HTML response is received from the login AJAX call. `document.write()` is deprecated and can cause issues with already-parsed pages.

**Suggestion:** Replace `document.open(); document.write(html); document.close();` with `document.documentElement.innerHTML = html;` or, better, parse the response and update only the relevant parts of the page.

**Why:** `document.write()` after page load can cause the browser to clear the entire document and reparse, which is slower and can break JavaScript state. It's also flagged by linters and security scanners. While the HTML comes from the server's own template (so XSS isn't a direct concern), the pattern is fragile and could become dangerous if the response content ever includes user input.

### 6.2 No Input Sanitization in Username Echo [LOW PRIORITY]

**Current state:** In `login.html.twig`, the username is echoed back into the input value: `value="{{ username }}"`. The username comes from the sanitized `makeCacheKey()` output, which restricts to `[A-Za-z0-9_.]`, so HTML injection is not possible with the current sanitization.

**Suggestion:** Add Twig's `escape` filter explicitly for defense-in-depth: `value="{{ username|e('html_attr') }}"`. Also consider whether the `message` variable in `<p id="preauth-message">{{ message|default }}</p>` could ever contain user input.

**Why:** While the current sanitization prevents XSS, relying on `makeCacheKey()` for HTML safety is an implicit coupling between cache key logic and output safety. If `makeCacheKey()` were ever relaxed to allow more characters, the template would become vulnerable. Twig auto-escaping is on by default, but `html_attr` escaping is more appropriate for attribute contexts.

---

## 7. Configuration

### 7.1 No Validation of Environment Variables [LOW PRIORITY]

**Current state:** Environment variables are consumed directly from `.env` via Symfony's parameter system. `COOKIE_TTL` is cast to `int` by Symfony's env var processors (via `%env(int:...)%` — wait, actually it uses `%env(COOKIE_TTL)%` without a type cast). The `ConfigBag` constructor types it as `int`, but Symfony's env var resolution passes strings. The `$ipTtl` is typed as `?int` but receives a string from env.

**Suggestion:** Use Symfony's env var processors for type casting: `%env:int:COOKIE_TTL)%`, `%env:int:IP_TTL)%`, `%env:bool:TEAPOT)%`, `%env:bool:SUBDOMAIN_REDIRECT)%`. This ensures invalid values fail fast at container compilation rather than at runtime.

**Why:** If someone sets `COOKIE_TTL=thirty-days` in their `.env`, the error would only surface when the parameter is used (at runtime), with a confusing type error. Using env processors catches this at container build time with a clear error message. The `rate_limiter.yaml` already uses `%env(int:BURST_COUNT)%` — this pattern should be applied consistently.

### 7.2 APP_SECRET Not Used Meaningfully [LOW PRIORITY]

**Current state:** `APP_SECRET` is configured in `framework.yaml` and is required by Symfony. However, preauth doesn't use Symfony sessions, CSRF tokens, or signed cookies — the main uses of `APP_SECRET`. It's essentially dead configuration.

**Suggestion:** This is fine — Symfony requires it. But note in documentation that `APP_SECRET` doesn't affect security in preauth's current architecture, since session cookies are random ULIDs looked up in cache, not signed tokens.

**Why:** Users might assume `APP_SECRET` is critical for security and worry about changing it. Clarifying that it's a Symfony formality (unused by preauth's auth mechanism) reduces confusion.

---

## 8. Documentation

### 8.1 Missing Security Model Documentation [MEDIUM PRIORITY]

**Current state:** The README explains the high-level concept and the ROADMAP documents the architecture, but there's no dedicated security model document that explains: what trusts what, what the threat model is, what the `Remote-User` header means, and what backend services should and shouldn't do with it.

**Suggestion:** Add a `docs/SECURITY.md` that covers:
- Threat model: what preauth protects against (unauthorized access to web services) and what it doesn't (not a replacement for the service's own auth)
- The `Remote-User` header: it identifies the preauth session, not a system user; backends must not use it for authorization
- TOTP is a shared secret: all users who know the TOTP secret are equivalent
- Rate limiting behavior and the teapot option
- Cookie security attributes and their implications
- What happens if the cache is lost (all sessions are invalidated)

**Why:** For a security-focused tool, the security model should be explicitly documented. This helps users deploy it correctly and helps reviewers assess its fitness for purpose. The information is scattered across the README, ROADMAP, and code comments — a single document would be valuable.

---

## What's Done Well

- **Listener-based architecture** is a good fit for this use case — each listener has a single responsibility, and the priority chain creates a clear request processing pipeline.
- **Cookie security** is excellent: `__Host-` prefix, `Secure`, `HttpOnly`, `SameSite=Strict`, and a separate non-prefixed cookie for domain-scoped central auth.
- **Nonce-based replay protection** with single-use, TTL-limited nonces and collision retry is well-designed.
- **Rate limiting** with compound sliding windows (burst + sustained) and the humorous teapot option is practical and well-implemented.
- **Test suite** is exemplary: 100% coverage, good use of test helpers, functional tests that exercise the full kernel, and edge cases like ULID collisions and nonce reuse.
- **Dual-layer cache** (APCu + filesystem with change tracking) is a clever solution for persistence without a database.
- **Backup code system** with single-use enforcement, case-insensitivity, and audit trail (keeping consumed codes with `false` value) is well thought out.
- **Interfaces** (`LoginInterface`, `DomainInterface`, `BackupCodeInterface`) enable clean mocking in tests.
- **FrankenPHP worker mode** via the Caddyfile and Dockerfile is a modern, performant serving strategy.
