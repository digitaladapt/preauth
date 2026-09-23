#!/usr/bin/env bash
#
# conformance.sh — checks a Symfony project against the shared standard.
#
# Usage:
#   conformance.sh --profile=web-app|auth-gateway|api-gateway [--json] [path]
#
# Design rules (GUIDING-LIGHT §8.2):
#   1. It only CHECKS. It never fixes anything. No remediation logic to maintain.
#   2. Checks are ADDED, never removed. The script can only get stricter. If a
#      check is wrong, fix the check — don't delete it from a repo.
#   3. Every check names the document section it comes from, so a failure tells
#      you WHY the rule exists, not just that you broke it.
#
# Exit codes: 0 = all passed, 1 = at least one failure.
#
# DEPENDENCIES: bash + coreutils + grep for everything except one check.
# `controls-16px-min-css` shells out to css-control-size.py because resolving
# rem/em/font-shorthand units correctly is not something grep can do — and
# getting it wrong silently misses the single most important regression in
# this codebase (vital-pulse's 0.95rem inputs). python3 is present on every
# GitHub/Gitea runner and in the setup-php images, so this is a safe
# dependency; if it is ever missing, that one check is SKIPPED with a warning
# rather than failed, so the rest of the suite still runs.

set -uo pipefail

PROFILE=""
OUTPUT_JSON=false
TARGET=""

# Directory this script lives in, so helper tools can be located regardless of
# the CWD the caller is in (CI runs it from the project root).
CONFORMANCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
export CONFORMANCE_DIR

for arg in "$@"; do
  case "$arg" in
    --profile=*) PROFILE="${arg#*=}" ;;
    --json)      OUTPUT_JSON=true ;;
    -h|--help)
      sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'
      exit 0
      ;;
    -*) echo "Unknown flag: $arg" >&2; exit 2 ;;
    *)  TARGET="$arg" ;;
  esac
done

PROFILE="${PROFILE:-web-app}"
TARGET="${TARGET:-.}"
cd "$TARGET" || { echo "Cannot cd to $TARGET" >&2; exit 2; }

case "$PROFILE" in
  web-app|auth-gateway|api-gateway) ;;
  *) echo "Invalid --profile: $PROFILE (expected web-app, auth-gateway, or api-gateway)" >&2; exit 2 ;;
esac

PASS=0
FAIL=0
SKIP=0
declare -a FAILURES=()
declare -a SKIPPED=()

# check <id> <description> <doc-section> <test-command...>
# The test command must exit 0 to pass.
check() {
  local id="$1" desc="$2" section="$3"
  shift 3
  if "$@" >/dev/null 2>&1; then
    PASS=$((PASS + 1))
    $OUTPUT_JSON || printf '  \033[32m✓\033[0m %s\n' "$id"
  else
    FAIL=$((FAIL + 1))
    FAILURES+=("$id|$desc|$section")
    $OUTPUT_JSON || printf '  \033[31m✗\033[0m %s — %s  [%s]\n' "$id" "$desc" "$section"
  fi
}

# check_opt <id> <desc> <section> <prereq-cmd> <real-cmd...>
#
# For checks that depend on optional tooling. If the prerequisite is missing
# the check is reported as SKIPPED — its own state, never a green tick.
# A silently-passing check is the most dangerous outcome here: it makes a repo
# look compliant while the check never actually ran.
check_opt() {
  local id="$1" desc="$2" section="$3" prereq="$4"
  shift 4
  # `prereq` is a SHELL EXPRESSION STRING, evaluated with eval.
  #
  # It must not be a multi-word command: `$prereq` is a single variable, so
  # passing `bash -c '...'` would bind only `bash` and run it bare — and a
  # bare `bash` reads stdin and BLOCKS FOREVER. That turns a broken check into
  # a hung CI job (which only surfaces when the job timeout kills it).
  if ! eval "$prereq" >/dev/null 2>&1; then
    SKIP=$((SKIP + 1))
    SKIPPED+=("$id|$desc|$section")
    $OUTPUT_JSON || printf '  \033[33m–\033[0m %s — SKIPPED (prerequisite unavailable)  [%s]\n' "$id" "$section"
    return
  fi
  check "$id" "$desc" "$section" "$@"
}

# has_file <path>
has_file()        { [ -f "$1" ]; }
# not_has_file <path>
not_has_file()    { [ ! -f "$1" ]; }
# file_contains <path> <pattern>   (silently false if path missing)
file_contains()   { [ -f "$1" ] && grep -qE "$2" "$1"; }
# any_file_contains <pattern> <path...>
#
# NOTE: matches anywhere in the file, INCLUDING comments. That is correct for
# rules about the mere presence of a string, and WRONG for rules about a
# directive. When checking a directive ("CI calls X"), anchor the pattern to
# the line form that the directive actually takes — see ci-reusable-workflows
# below for why that matters.
any_file_contains() {
  local pat="$1"; shift
  grep -rlE "$pat" "$@" >/dev/null 2>&1
}
# no_file_contains <ext-glob> <pattern> — searches source trees only
no_match_in_sources() {
  local pattern="$1"; shift
  grep -rEl "$pattern" "$@" >/dev/null 2>&1 && return 1 || return 0
}
# dir_exists
dir_exists()      { [ -d "$1" ]; }

$OUTPUT_JSON || {
  echo ""
  echo "Conformance check — profile: $PROFILE — $(pwd)"
  echo "Standard: GUIDING-LIGHT.md"
  echo ""
}

# ─────────────────────────────────────────────────────────────────────────────
# UNIVERSAL — every repo, every archetype
# ─────────────────────────────────────────────────────────────────────────────
$OUTPUT_JSON || echo "PHP & framework baseline"

check "php-85" \
  "composer.json requires PHP 8.5 (use ^8.5, not >=8.4)" \
  "§1.1" \
  file_contains composer.json '"php"[[:space:]]*:[[:space:]]*"\^8\.5'

check "php-not-open-ended" \
  "PHP constraint is not open-ended (>=8.4 allows PHP 9)" \
  "§1.1" \
  bash -c '! grep -qE "\"php\"[[:space:]]*:[[:space:]]*\">=" composer.json'

check "platform-pinned" \
  "config.platform is set in composer.json (prevents silent version drift)" \
  "§1.2" \
  file_contains composer.json '"platform"'

check "symfony-81" \
  "Symfony pinned to 8.1" \
  "§1" \
  file_contains composer.json 'symfony/framework-bundle":[[:space:]]*"8\.1\.'

$OUTPUT_JSON || echo ""
$OUTPUT_JSON || echo "Toolchain"

check "phpstan-config" \
  "phpstan.neon.dist present" \
  "§2.2" \
  has_file phpstan.neon.dist

check "phpstan-level" \
  "PHPStan level >= 6 declared" \
  "§2.2" \
  bash -c 'grep -qE "level:[[:space:]]*[6-9]|level:[[:space:]]*max" phpstan.neon.dist 2>/dev/null'

check "cs-fixer-config" \
  ".php-cs-fixer.dist.php present" \
  "§2.5" \
  has_file .php-cs-fixer.dist.php

check "cs-fixer-pinned" \
  "friendsofphp/php-cs-fixer pinned to ^3.95 (not \"*\")" \
  "§2.5" \
  bash -c '! grep -qE "php-cs-fixer\"[[:space:]]*:[[:space:]]*\"\*\"" composer.json'

check "phpunit-config-name" \
  "PHPUnit config named phpunit.dist.xml" \
  "§2.4" \
  has_file phpunit.dist.xml

check "editorconfig" \
  ".editorconfig present" \
  "§8.10" \
  has_file .editorconfig

$OUTPUT_JSON || echo ""
$OUTPUT_JSON || echo "CI & supply chain"

check "ci-composer-audit" \
  "CI runs 'composer audit'" \
  "§8.1" \
  any_file_contains 'composer audit' .gitea/workflows

check "ci-coverage" \
  "CI measures test coverage" \
  "§2.3" \
  any_file_contains 'coverage' .gitea/workflows

check "ci-composer-validate" \
  "CI runs 'composer validate --strict'" \
  "§8.3" \
  any_file_contains 'composer validate' .gitea/workflows

# Anchored to a REAL `uses:` line, not the string anywhere in the file.
#
# The previous form matched the string anywhere, which gave it a false-positive
# mode that made it worse than useless: a repo that INLINED the shared workflow
# still carries a header comment saying it was "inlined from
# private/ci/.gitea/workflows/...", so the check went green in exactly the repos
# that had drifted. It reported compliance precisely where compliance was absent.
#
# `uses:` is what the description always claimed to test.
check "ci-reusable-workflows" \
  "CI calls shared workflows from private/ci (not five drift surfaces)" \
  "§8.2" \
  any_file_contains '^[[:space:]]*uses:[[:space:]]*private/ci/\.gitea/workflows' .gitea/workflows

$OUTPUT_JSON || echo ""
$OUTPUT_JSON || echo "Hygiene & layout"

check "dockerignore-env" \
  ".env is excluded in .dockerignore (prevents secrets in images)" \
  "§6.1" \
  file_contains .dockerignore '^/?\.env$'

check "dockerignore-present" \
  ".dockerignore present" \
  "§6" \
  has_file .dockerignore

check "gitignore-var" \
  "/var/ excluded in .gitignore" \
  "§5.2" \
  file_contains .gitignore '^/?var/?$'

check "dockerfile-nonroot" \
  "Dockerfile drops privileges with USER" \
  "§6.4" \
  file_contains Dockerfile '^USER '

check "dockerfile-pinned-base" \
  "Dockerfile base image is not :latest" \
  "§6.4" \
  bash -c '! grep -qE "^FROM[^ ]*:latest" Dockerfile'

check "docs-examples" \
  "docs/examples/ present" \
  "§4.4" \
  dir_exists docs/examples

check "boilerplate-security" \
  "SECURITY.md present" \
  "§7.1" \
  has_file SECURITY.md

check "license-file" \
  "LICENSE file present" \
  "§7.2" \
  has_file LICENSE

check "license-mit" \
  "LICENSE is MIT (uniform MIT decided §7.2)" \
  "§7.2" \
  bash -c 'head -1 LICENSE 2>/dev/null | grep -qi "^MIT License"'

# A composer.json license that contradicts the shipped LICENSE file is worse
# than declaring none: tooling trusts the metadata, humans read the file.
# task-weaver declared "proprietary" while shipping no file at all.
check "license-declared-matches" \
  "composer.json declares MIT, matching the LICENSE file" \
  "§7.2" \
  file_contains composer.json '"license"[[:space:]]*:[[:space:]]*"MIT"'

check "no-cdn-references" \
  "No CDN script/link references (zero-CDN goal)" \
  "§3.5" \
  bash -c '! grep -rElE "(cdn\.jsdelivr|cdnjs\.cloudflare|unpkg\.com|cdn\.tailwindcss)" templates public assets config 2>/dev/null | head -1 | grep -q .'

# ─────────────────────────────────────────────────────────────────────────────
# NOT API-GATEWAY — anything that renders HTML
# ─────────────────────────────────────────────────────────────────────────────
if [ "$PROFILE" != "api-gateway" ]; then
  $OUTPUT_JSON || echo ""
  $OUTPUT_JSON || echo "Accessibility (§3.3a) — the iOS zoom root cause"

  check "viewport-not-zoom-locked" \
    "Viewport does not disable pinch-zoom (WCAG 1.4.4)" \
    "§3.3a" \
    bash -c '! grep -rElE "(user-scalable=no|maximum-scale=1)" templates public 2>/dev/null | head -1 | grep -q .'

  check "viewport-fit-cover" \
    "Viewport declares viewport-fit=cover (safe areas)" \
    "§3.3a" \
    bash -c 'grep -rElE "viewport-fit=cover" templates public 2>/dev/null | head -1 | grep -q .'

  # Two checks, because there are two ways to get this wrong and one grep
  # cannot see both:
  #   (a) raw CSS with a small font-size — needs unit resolution (rem/em/shorthand)
  #   (b) Tailwind-style utility classes on the control — not CSS at all
  # Requires python3 AND the vendored helper. If either is absent this is
  # SKIPPED (reported as its own state), never a green tick.
  check_opt "controls-16px-min-css" \
    "No form control renders below 16px (root cause of iOS auto-zoom)" \
    "§3.3a" \
    'command -v python3 >/dev/null 2>&1 && [ -f "$CONFORMANCE_DIR/css-control-size.py" ]' \
    bash -c '
      mapfile -t files < <(find templates assets public -type f \( -name "*.css" -o -name "*.twig" -o -name "*.html" \) 2>/dev/null)
      [ "${#files[@]}" -eq 0 ] && exit 0
      exec "$CONFORMANCE_DIR/css-control-size.py" 16 "${files[@]}"
    '

  check "controls-16px-min-utility" \
    "No small text utility class on a form control (Tailwind text-xs/text-sm)" \
    "§3.3a" \
    bash -c '
      ! grep -rPzoE "<(input|select|textarea)[^>]*class=\"[^\"]*(text-xs|text-sm)[^\"]*\"" templates 2>/dev/null | head -c1 | grep -q .
    '

  check "security-headers" \
    "Security headers configured (Symfony listener or Caddy)" \
    "§8.9" \
    bash -c 'grep -rElE "X-Content-Type-Options" src config docker Caddyfile 2>/dev/null | head -1 | grep -q .'

  check "no-deprecated-xss-header" \
    "X-XSS-Protection not used (deprecated)" \
    "§8.9" \
    bash -c '! grep -rElE "X-XSS-Protection" src config docker Caddyfile 2>/dev/null | head -1 | grep -q .'
fi

# ─────────────────────────────────────────────────────────────────────────────
# WEB-APP ONLY — installable PWA surface
# ─────────────────────────────────────────────────────────────────────────────
if [ "$PROFILE" = "web-app" ]; then
  $OUTPUT_JSON || echo ""
  $OUTPUT_JSON || echo "PWA (§3.3b)"

  check "webmanifest" \
    "Web app manifest present" \
    "§3.3b" \
    bash -c 'ls public/*.webmanifest public/manifest.json 2>/dev/null | head -1 | grep -q .'

  check "manifest-display-standalone" \
    "Manifest declares display: standalone" \
    "§3.3b" \
    bash -c 'grep -qE "\"display\"[[:space:]]*:[[:space:]]*\"standalone\"" public/*.webmanifest public/manifest.json 2>/dev/null'

  check "manifest-start-url" \
    "Manifest declares start_url" \
    "§3.3b" \
    bash -c 'grep -qE "\"start_url\"" public/*.webmanifest public/manifest.json 2>/dev/null'

  check "service-worker" \
    "Service worker present (required for installability)" \
    "§3.3b" \
    bash -c 'ls public/sw.js public/service-worker.js 2>/dev/null | head -1 | grep -q .'

  check "theme-color" \
    "theme-color meta present (status bar theming)" \
    "§3.3a" \
    bash -c 'grep -rElE "theme-color" templates public 2>/dev/null | head -1 | grep -q .'

  check "touch-action" \
    "touch-action: manipulation used (removes 300ms tap delay)" \
    "§3.3a" \
    bash -c 'grep -rElE "touch-action" templates assets public 2>/dev/null | head -1 | grep -q .'
fi

# ─────────────────────────────────────────────────────────────────────────────
# AUTH-GATEWAY ONLY — preauth-specific security invariants
# ─────────────────────────────────────────────────────────────────────────────
if [ "$PROFILE" = "auth-gateway" ]; then
  $OUTPUT_JSON || echo ""
  $OUTPUT_JSON || echo "Auth gateway security (§3.3d)"

  check "no-service-worker" \
    "Auth gateway has NO service worker (must never replay a cached session)" \
    "§3.3d" \
    bash -c '! ls public/sw.js public/service-worker.js 2>/dev/null | head -1 | grep -q .'

  check "no-store-present" \
    "no-store cache directive present somewhere (login flow anti-cache guard)" \
    "§3.3d" \
    bash -c 'grep -rEli "no-store" src config 2>/dev/null | head -1 | grep -q .'

  check "rate-limiter" \
    "symfony/rate-limiter required" \
    "§8.11" \
    file_contains composer.json 'rate-limiter'
fi

# ─────────────────────────────────────────────────────────────────────────────
# REPORT
# ─────────────────────────────────────────────────────────────────────────────
TOTAL=$((PASS + FAIL + SKIP))

# JSON strings must be escaped. Without this, a description containing a double
# quote (e.g. 'pinned to ^3.95 (not "*")') produces invalid JSON and silently
# breaks every consumer of --json.
json_escape() {
  local s="$1"
  s="${s//\\/\\\\}"   # backslash first
  s="${s//\"/\\\"}"   # then double quote
  s="${s//$'\n'/\\n}"
  s="${s//$'\t'/\\t}"
  s="${s//$'\r'/}"
  printf '%s' "$s"
}

if $OUTPUT_JSON; then
  printf '{"profile":"%s","passed":%d,"failed":%d,"skipped":%d,"total":%d,"failures":[' \
    "$(json_escape "$PROFILE")" "$PASS" "$FAIL" "$SKIP" "$TOTAL"
  first=true
  for f in "${FAILURES[@]:-}"; do
    [ -z "$f" ] && continue
    IFS='|' read -r fid fdesc fsec <<< "$f"
    $first || printf ','
    printf '{"id":"%s","description":"%s","section":"%s"}' \
      "$(json_escape "$fid")" "$(json_escape "$fdesc")" "$(json_escape "$fsec")"
    first=false
  done
  printf '],"skipped_checks":['
  first=true
  for f in "${SKIPPED[@]:-}"; do
    [ -z "$f" ] && continue
    IFS='|' read -r fid fdesc fsec <<< "$f"
    $first || printf ','
    printf '{"id":"%s","description":"%s","section":"%s"}' \
      "$(json_escape "$fid")" "$(json_escape "$fdesc")" "$(json_escape "$fsec")"
    first=false
  done
  printf ']}\n'
else
  echo ""
  echo "─────────────────────────────────────────────"
  if [ "$SKIP" -gt 0 ]; then
    printf '  \033[33m%d of %d checks SKIPPED\033[0m (missing prerequisites — NOT a pass):\n' "$SKIP" "$TOTAL"
    for f in "${SKIPPED[@]}"; do
      IFS='|' read -r fid fdesc fsec <<< "$f"
      printf '    · [%s] %s\n' "$fsec" "$fid"
    done
    echo ""
  fi
  if [ "$FAIL" -eq 0 ]; then
    printf '  \033[32mAll %d runnable checks passed.\033[0m\n' "$((TOTAL - SKIP))"
  else
    printf '  \033[31m%d of %d checks failed.\033[0m\n\n' "$FAIL" "$TOTAL"
    echo "  Guidance:"
    for f in "${FAILURES[@]}"; do
      IFS='|' read -r fid fdesc fsec <<< "$f"
      printf '    • [%s] %s\n      %s\n' "$fsec" "$fdesc" "$fid"
    done
  fi
  echo "─────────────────────────────────────────────"
  echo ""
fi

[ "$FAIL" -eq 0 ] && exit 0 || exit 1
