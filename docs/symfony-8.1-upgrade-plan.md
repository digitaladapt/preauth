# Upgrade Plan: Symfony 7.4 → 8.1

**Status:** Planning
**Target:** Symfony `8.1.*` (all symfony components)
**Current:** Symfony `7.4.*`
**Prepared:** 2026-09-07

---

## 1. Why we can leapfrog 8.0

Symfony 7.4 and 8.0 were released simultaneously (Nov 2025) and are
feature-identical — 8.0 is simply 7.4 with the deprecated code removed.
Because preauth is **already on 7.4**, we are on the last LTS bridge
release. The only gating question for 8.x is whether we still trigger
any deprecations. If `composer test` runs clean under 7.4 with
`SYMFONY_DEPRECATIONS_HELPER` strict, upgrading straight to 8.1 is safe
and avoids a double-bump of `composer.json` / `composer.lock`.

Symfony 8.1 (May 2026 cycle) also brings a runtime improvement we
directly benefit from (see §3).

Prerequisites:

- ✅ PHP: Symfony 8.x requires PHP **>= 8.4**; composer.json already
  requires `>= 8.4`, Docker and CI run 8.5. No PHP work needed.
- ⚠️ Deprecations: must be inventoried and fixed before the version bump
  (see Phase 0).

## 2. The `runtime/frankenphp-symfony` removal

We currently use the community runtime package for FrankenPHP worker
mode, wired in two places in `composer.json`:

```json
"require": {
    "runtime/frankenphp-symfony": "^1.0.0",
},
"extra": {
    "runtime": {
        "class": "Runtime\\FrankenPhpSymfony\\Runtime"
    },
}
```

As of Symfony 7.4+, `symfony/runtime` ships its own
`Symfony\Component\Runtime\Runner\FrankenPhpWorkerRunner`, and **in 8.1
the runtime handles FrankenPHP worker mode natively** (including new
8.1 support for returning a `Response` from worker mode). The
community package is redundant.

**Actions:**

1. `composer remove runtime/frankenphp-symfony` (as part of the 8.1 bump
   in §4 — do it in the same `composer update` to keep one lockfile diff).
2. Delete the entire `extra.runtime` block from `composer.json` so the
   default `Symfony\Component\Runtime\GenericRuntime` is used; the
   built-in `FrankenPhpWorkerRunner` is auto-selected when
   `frankenphp_handle_request()` exists (i.e. inside FrankenPHP worker
   mode). Falling back to plain `APP_RUNTIME=Symfony\...\Runtime` env
   override is possible but should not be needed.
3. Verify `symfony.lock` — Flex should drop the
   `runtime/frankenphp-symfony` entry automatically on removal.
4. `public/index.php` needs **no change** — it already just returns the
   Kernel closure via `autoload_runtime.php`.

**Note on loop_max:** the old package exposed
`FRANKENPHP_LOOP_MAX` (default 500). The built-in runner does not
read that env var. We never set it, so behavior is unchanged — but
check staging memory usage under worker mode and, if ever needed,
control restarts via FrankenPHP's own `worker ... num N` / max-requests
options in the Caddyfile instead.

## 3. composer.json changes

### `require`

| Package                  | From       | To      |
|--------------------------|------------|---------|
| `symfony/cache`          | `7.4.*`    | `8.1.*` |
| `symfony/console`        | `7.4.*`    | `8.1.*` |
| `symfony/framework-bundle`| `7.4.*`   | `8.1.*` |
| `symfony/mime`           | `7.4.*`    | `8.1.*` |
| `symfony/rate-limiter`   | `7.4.*`    | `8.1.*` |
| `symfony/runtime`        | `7.4.*`    | `8.1.*` |
| `symfony/twig-bundle`    | `7.4.*`    | `8.1.*` |
| `symfony/uid`            | `7.4.*`    | `8.1.*` |
| `symfony/yaml`           | `7.4.*`    | `8.1.*` |
| ~~`runtime/frankenphp-symfony`~~ | `^1.0.0` | **removed** |

`symfony/flex` (`^2.11`), `bacon/bacon-qr-code` (^3) and
`spomky-labs/otphp` (^11) are compatible with 8.x — no change expected,
but let composer confirm during the update.

### `require-dev`

| Package                  | From    | To      |
|--------------------------|---------|---------|
| `symfony/browser-kit`    | `7.4.*` | `8.1.*` |
| `symfony/css-selector`   | `7.4.*` | `8.1.*` |

`phpunit/phpunit ^13.2` and `friendsofphp/php-cs-fixer` already support
PHP 8.5 / Symfony 8.

### `extra`

```diff
 "extra": {
-    "runtime": {
-        "class": "Runtime\\FrankenPhpSymfony\\Runtime"
-    },
     "symfony": {
         "allow-contrib": false,
-        "require": "7.4.*"
+        "require": "8.1.*"
     }
 }
```

### One-shot command

```bash
composer update \
  "symfony/*" \
  --with-all-dependencies
# plus explicit remove of runtime/frankenphp-symfony beforehand
```

(Or edit composer.json, then `composer update` wholesale — the repo has
few non-Symfony deps, so a full update is low-risk.)

## 4. Config / recipes to re-sync

After the bump, run `composer recipes:update` (or
`symfony console recipes:update`) and review diffs for:

- `symfony/framework-bundle` — check `config/packages/framework.yaml`
  for new/changed defaults (session, cache, http_method_override, etc.).
  Our `config/reference.php` dump is generated from 7.4 config; it
  **must be regenerated** after upgrade
  (`bin/console config:dump-reference` equivalents) or it will document
  stale defaults.
- `symfony/twig-bundle`, `symfony/rate-limiter` — verify
  `config/packages/*.yaml` against new reference defaults.
- `symfony/runtime` — new recipe may update `public/index.php`; accept
  only if it's a no-op for our shape.

Also review `bundles.php` (only Framework + Twig today — no removals
expected in 8.x) and `config/preload.php`.

## 5. Code-level risk review

Preauth deliberately avoids the Security component (custom listeners +
`ConfigBag`), which removes the biggest 8.0 BC-break surface
(`security.yaml` reshaping, authenticator changes). Remaining surface:

- **Listeners** (`src/Listener/*`): built on HttpKernel events — stable
  API, but `KernelEvents` signatures gained native types in 8.0; our
  listeners already declare types, verify covariance after upgrade.
- **`Kernel.php`**: confirm no overridden methods whose signatures
  changed in 8.0 (MicroKernelTrait is stable; likely no-op).
- **`symfony/console`** (GenerateBackupCodesCommand): 8.0 removed
  command `setName()`/aliases-in-constructor legacy paths — we use
  `#[AsCommand]`, fine. `Command::execute()` must return `int` — verify.
- **`spomky-labs/otphp`** and **`bacon/bacon-qr-code`**: third-party;
  confirm versions resolved are marked Symfony-8 compatible.
- **PHPUnit 13**: no changes needed, but watch for deprecations printed
  after the Symfony bump (new `trigger_deprecation` calls in 8.1).

Canonical checklist: read `symfony/symfony` **UPGRADE-8.0.md** and
**UPGRADE-8.1.md** sections for the components we require
(cache, console, framework-bundle, mime, rate-limiter, runtime,
twig-bundle, uid, yaml) and tick each item against this codebase.

## 6. Docker / CI

- `Dockerfile`: no base-image change needed
  (`dunglas/frankenphp:php8.5-trixie` + `php:8.5-trixie` builder).
  Rebuild after composer.lock update; remove nothing — FrankenPHP itself
  stays.
- `Caddyfile`: unchanged (worker mode config is FrankenPHP-side, not
  runtime-package-side).
- `.gitea/workflows/tests.yaml`: PHP 8.5 already — unchanged.
- `composer dump-env prod --empty` step stays.

## 7. Rollout plan

| Phase | Step | Exit criteria |
|-------|------|---------------|
| 0 | **Deprecation sweep on 7.4**: run `SYMFONY_DEPRECATIONS_HELPER=max[total]=0 composer test` (or phpunit directly) + run the app in dev with the profiler/log; fix every direct deprecation. | Zero deprecations from `App\` code; only acceptable vendor ones documented. |
| 1 | **composer bump**: branch `feat/symfony-8.1`; edit composer.json per §3–§4; `composer remove runtime/frankenphp-symfony`; `composer update`; re-sync recipes. | Installs clean on PHP 8.5; `bin/console about` shows 8.1.x. |
| 2 | **Config refresh**: regenerate `config/reference.php`; review framework/twig/rate-limiter defaults; commit config changes. | `cache:clear` + warmup pass in dev & prod envs. |
| 3 | **Tests**: full phpunit suite + php-cs-fixer; fix failures (expected: minor — event/type related). | Suite green in CI. |
| 4 | **Staging smoke**: build image, run under FrankenPHP worker mode; verify TOTP login flow, backup codes, rate limiting (burst + teapot mode), public paths, central-auth subdomain flow; watch memory across >500 requests (old loop_max default no longer applies — see §2 note). | No state leaks across worker requests; healthcheck passes. |
| 5 | **Docs + release**: update readme/DESIGN_CONSIDERATIONS ("symfony 8.1, built-in FrankenPHP runtime"); tag a minor release per CHANGELOG conventions. | Release published; image rebuilt & pushed. |

**Rollback:** the upgrade is a single composer.lock + config diff.
Rollback = `git revert` the bump commit + redeploy previous image tag.
No data/schema migrations are involved (no database).

## 8. Open questions

- [ ] Confirm none of our listeners/services relied on implicit behavior
      of `Runtime\FrankenPhpSymfony\Runner` (e.g. per-request kernel
      reboot). The built-in runner reuses the kernel — our services must
      implement `ResetInterface` where they hold per-request state
      (`ConfigBag`, nonces, cache-touching services). Audit in Phase 0.
- [ ] Decide whether to pin `symfony/*` as `8.1.*` or `^8.1` going
      forward (current convention is minor-pinned — keep `8.1.*`).
- [ ] Regenerate `config/reference.php` — scripted or manual dump?
