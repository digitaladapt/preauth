#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# PreAuth Dev Server Script
#
# Manages a local PHP dev server for end-to-end development and testing.
# Binds to 0.0.0.0 so the app is accessible via a reverse proxy (Caddy) for
# browser-based visual verification.
#
# PreAuth is a TOTP-based authentication gateway. It uses APCu for nonce/cache
# and filesystem for session persistence — no database needed. The dev server
# runs with APP_ENV=dev and APP_DEBUG=1 for live troubleshooting.
#
# Self-bootstrapping: the `start` command checks for required system packages
# (PHP, extensions, tools), Composer, and project dependencies — installing
# them automatically if missing. This means the script works even after a
# terminal reset/reboot, embracing the self-cleaning container design.
#
# Usage:
#   bin/dev.sh start     Start the dev server (auto-installs deps if needed)
#   bin/dev.sh stop      Stop the dev server
#   bin/dev.sh status    Check if the dev server is running
#   bin/dev.sh restart   Stop and start the dev server
#
# Port assignment (P-R-E = 7-7-3):
#   8773 → https://preauth.lyra-dev.devgnome.com
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

# ── Configuration ───────────────────────────────────────────────────────────
PORT=8773
HOST="0.0.0.0"
ENV="dev"
DEV_SECRET="dev_secret_not_for_production_use_only"
PID_FILE="var/.dev-server.pid"
LOG_FILE="var/log/dev-server.log"

# Required PHP extensions (checked via php -m)
REQUIRED_PHP_EXTS=(
    ctype
    iconv
    mbstring
    apcu
    dom
    SimpleXML
    xml
)

# Apt packages for PHP + extensions
# Note: preauth uses Symfony 7.4 which requires PHP >=8.1.
# We install PHP 8.4 (available in Debian 13/Trixie) for consistency.
PHP_APT_PACKAGES=(
    php8.4-cli
    php8.4-common     # ctype, iconv
    php8.4-mbstring
    php8.4-xml        # dom, SimpleXML, xml
    php8.4-opcache
    php8.4-readline
    php8.4-apcu       # APCu — critical for nonce cache, rate limiter, sessions
)

# System tools needed
SYSTEM_TOOLS=(
    git
    unzip
    curl
)

# Resolve project root (script lives in bin/)
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

# Ensure var directory structure exists
mkdir -p var/log var/share

# ── Helpers ─────────────────────────────────────────────────────────────────

is_running() {
    if [[ ! -f "$PID_FILE" ]]; then
        return 1
    fi
    local pid
    pid="$(cat "$PID_FILE")"
    if [[ -z "$pid" ]] || ! kill -0 "$pid" 2>/dev/null; then
        return 1
    fi
    return 0
}

print_status() {
    if is_running; then
        local pid
        pid="$(cat "$PID_FILE")"
        echo "✅ PreAuth dev server is RUNNING"
        echo "   PID:     $pid"
        echo "   URL:     http://localhost:${PORT}"
        echo "   Exposed: http://${HOST}:${PORT}"
        echo "   Dev URL: https://preauth.lyra-dev.devgnome.com"
        echo "   Logs:    ${LOG_FILE}"
    else
        echo "⛔ PreAuth dev server is STOPPED"
    fi
}

# ── Bootstrap ───────────────────────────────────────────────────────────────
# Ensures all system packages, Composer, and project dependencies are present.
# Idempotent — if everything is already installed, checks are fast no-ops.
# This is what makes the script survive terminal resets/reboots.

bootstrap() {
    local needed_packages=()

    # ── Check system tools ──
    for tool in "${SYSTEM_TOOLS[@]}"; do
        if ! command -v "$tool" &>/dev/null; then
            needed_packages+=("$tool")
        fi
    done

    # ── Check PHP and required extensions ──
    local php_needs_install=false
    if ! command -v php &>/dev/null; then
        php_needs_install=true
    else
        for ext in "${REQUIRED_PHP_EXTS[@]}"; do
            if ! php -m 2>/dev/null | grep -iq "^${ext}$"; then
                php_needs_install=true
                break
            fi
        done
    fi

    if [[ "$php_needs_install" == "true" ]]; then
        needed_packages+=("${PHP_APT_PACKAGES[@]}")
    fi

    # ── Install missing packages ──
    if [[ ${#needed_packages[@]} -gt 0 ]]; then
        echo "→ Installing missing system packages: ${needed_packages[*]}…"
        sudo apt-get update -qq
        sudo apt-get install -y -qq "${needed_packages[@]}"
    fi

    # ── Ensure APCu is enabled for CLI ──
    # PreAuth's console commands need APCu; the Dockerfile sets apc.enable_cli=1
    local apcu_ini="/etc/php/8.4/mods-available/apcu.ini"
    if [[ -f "$apcu_ini" ]] && ! grep -q 'apc.enable_cli' "$apcu_ini" 2>/dev/null; then
        echo "→ Enabling APCu CLI support…"
        echo 'apc.enable_cli=1' | sudo tee -a "$apcu_ini" >/dev/null
    fi

    # ── Ensure Composer is available ──
    if ! command -v composer &>/dev/null; then
        echo "→ Installing Composer…"
        curl -sS https://getcomposer.org/installer | php
        sudo mv composer.phar /usr/local/bin/composer
        sudo chmod +x /usr/local/bin/composer
    fi

    # ── Ensure project dependencies are installed ──
    if [[ ! -d "vendor/" ]]; then
        echo "→ Installing Composer dependencies…"
        APP_ENV=dev composer install --no-interaction
    fi
}

# ── Commands ────────────────────────────────────────────────────────────────

start() {
    if is_running; then
        echo "⚠️  Dev server is already running (PID $(cat "$PID_FILE"))"
        print_status
        exit 0
    fi

    echo "→ Starting PreAuth dev server on ${HOST}:${PORT}…"

    # Self-bootstrap: ensure all dependencies are present
    bootstrap

    echo "→ Clearing dev cache…"
    APP_ENV="$ENV" \
    APP_DEBUG=1 \
    APP_SECRET="$DEV_SECRET" \
    php bin/console cache:clear 2>&1 | tail -3

    echo "→ Starting PHP dev server…"
    APP_ENV="$ENV" \
    APP_DEBUG=1 \
    APP_SECRET="$DEV_SECRET" \
    APP_SHARE_DIR="${PROJECT_ROOT}/var/share" \
    nohup php -S "${HOST}:${PORT}" -t public/ > "$LOG_FILE" 2>&1 &

    local pid=$!
    echo "$pid" > "$PID_FILE"

    # Give it a moment to boot
    sleep 2

    if is_running; then
        echo ""
        print_status
    else
        echo "❌ Failed to start dev server. Check logs:"
        echo "   ${LOG_FILE}"
        tail -20 "$LOG_FILE" 2>/dev/null || true
        rm -f "$PID_FILE"
        exit 1
    fi
}

stop() {
    if ! is_running; then
        echo "⚠️  Dev server is not running."
        rm -f "$PID_FILE"
        exit 0
    fi

    local pid
    pid="$(cat "$PID_FILE")"
    echo "→ Stopping dev server (PID ${pid})…"
    kill "$pid" 2>/dev/null || true

    # Wait for graceful shutdown
    local count=0
    while kill -0 "$pid" 2>/dev/null && [[ $count -lt 10 ]]; do
        sleep 0.5
        count=$((count + 1))
    done

    # Force kill if still alive
    if kill -0 "$pid" 2>/dev/null; then
        echo "→ Process didn't exit gracefully, sending SIGKILL…"
        kill -9 "$pid" 2>/dev/null || true
    fi

    rm -f "$PID_FILE"
    echo "✅ Dev server stopped."
}

restart() {
    stop
    sleep 1
    start
}

# ── Main ────────────────────────────────────────────────────────────────────

usage() {
    echo "Usage: bin/dev.sh {start|stop|status|restart}"
    echo ""
    echo "Commands:"
    echo "  start     Start the dev server (auto-installs deps if needed)"
    echo "  stop      Stop the dev server"
    echo "  status    Check if the dev server is running"
    echo "  restart   Restart the dev server"
    exit 1
}

case "${1:-}" in
    start)   start ;;
    stop)    stop ;;
    status)  print_status ;;
    restart) restart ;;
    *)       usage ;;
esac
