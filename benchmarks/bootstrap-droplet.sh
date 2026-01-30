#!/bin/sh
#
# One-shot benchmark runner for fresh Linux droplet
#
# Usage (as root on fresh Ubuntu 22.04/24.04):
#   curl -sL https://raw.githubusercontent.com/utopia-php/protocol-proxy/dev/benchmarks/bootstrap-droplet.sh | sudo bash
#
# Quick Docker test (no install needed):
#   docker run --rm --privileged phpswoole/swoole:php8.3-alpine sh -c '
#     apk add --no-cache git composer > /dev/null 2>&1
#     cd /tmp && git clone --depth 1 -b dev https://github.com/utopia-php/protocol-proxy.git
#     cd protocol-proxy && composer install --quiet
#     BACKEND_HOST=127.0.0.1 BACKEND_PORT=15432 php benchmarks/tcp-backend.php &
#     sleep 2 && BENCH_PORT=15432 BENCH_CONCURRENCY=100 BENCH_CONNECTIONS=5000 php benchmarks/tcp.php
#   '
#
set -e

echo "=== TCP Proxy Benchmark Bootstrap ==="
echo ""

# Check if running as root
if [ "$(id -u)" -ne 0 ]; then
    echo "Error: Run as root (sudo)"
    exit 1
fi

# Detect OS
if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS=$ID
else
    echo "Error: Cannot detect OS"
    exit 1
fi

echo "[1/6] Installing dependencies..."

case "$OS" in
    ubuntu|debian)
        export DEBIAN_FRONTEND=noninteractive
        apt-get update -qq
        # Add ondrej PPA for latest PHP
        apt-get install -y -qq software-properties-common > /dev/null 2>&1
        add-apt-repository -y ppa:ondrej/php > /dev/null 2>&1
        apt-get update -qq
        apt-get install -y -qq php8.3-cli php8.3-dev php8.3-xml php8.3-curl \
            php8.3-mbstring php8.3-zip php-pear git unzip curl > /dev/null 2>&1
        ;;
    fedora|rhel|centos|rocky|alma)
        dnf install -y -q php-cli php-devel php-xml php-mbstring php-zip \
            git unzip curl > /dev/null 2>&1
        ;;
    *)
        echo "Warning: Unknown OS '$OS', assuming PHP is installed"
        ;;
esac

echo "  - PHP $(php -v | head -1 | cut -d' ' -f2)"

echo "[2/6] Installing Swoole..."

# Check if Swoole already installed
if php -m 2>/dev/null | grep -q swoole; then
    echo "  - Swoole already installed"
else
    # Install Swoole via pecl (auto-answer prompts: sockets=yes, openssl=yes, others=no)
    printf "yes\nyes\nno\nno\nno\n" | pecl install swoole > /dev/null 2>&1 || {
        # Fallback: try without prompts
        pecl install -f swoole < /dev/null > /dev/null 2>&1 || true
    }

    # Enable the extension
    PHP_CONF_DIR=$(php -i 2>/dev/null | grep "Scan this dir" | cut -d'>' -f2 | tr -d ' ')
    if [ -n "$PHP_CONF_DIR" ] && [ -d "$PHP_CONF_DIR" ]; then
        echo "extension=swoole.so" > "$PHP_CONF_DIR/20-swoole.ini"
    else
        # Fallback locations
        echo "extension=swoole.so" > /etc/php/8.3/cli/conf.d/20-swoole.ini 2>/dev/null || \
        echo "extension=swoole.so" > /etc/php/8.2/cli/conf.d/20-swoole.ini 2>/dev/null || \
        echo "extension=swoole.so" >> /etc/php.ini 2>/dev/null || true
    fi
    echo "  - Swoole installed"
fi

# Verify Swoole
if ! php -m 2>/dev/null | grep -q swoole; then
    echo "Error: Swoole not loaded."
    echo "Debug: Checking extension..."
    php -i | grep -i swoole || echo "  (not found in php -i)"
    ls -la /usr/lib/php/*/swoole.so 2>/dev/null || echo "  (swoole.so not found)"
    exit 1
fi

echo "[3/6] Installing Composer..."

if command -v composer > /dev/null 2>&1; then
    echo "  - Composer already installed"
else
    curl -sS https://getcomposer.org/installer | php -- --quiet --install-dir=/usr/local/bin --filename=composer
    echo "  - Composer installed"
fi

echo "[4/6] Cloning protocol-proxy..."

WORKDIR="/tmp/protocol-proxy-bench"
rm -rf "$WORKDIR"

if [ -f "composer.json" ] && grep -q "protocol-proxy" composer.json 2>/dev/null; then
    # Already in the repo
    WORKDIR="$(pwd)"
    echo "  - Using current directory"
else
    git clone --depth 1 -b dev https://github.com/utopia-php/protocol-proxy.git "$WORKDIR" 2>/dev/null
    cd "$WORKDIR"
    echo "  - Cloned to $WORKDIR"
fi

echo "[5/6] Installing PHP dependencies..."

composer install --no-interaction --no-progress --quiet 2>/dev/null
echo "  - Dependencies installed"

echo "[6/6] Applying kernel tuning..."

# Apply benchmark tuning
./benchmarks/setup-linux.sh > /dev/null 2>&1 || {
    # Inline tuning if script fails
    sysctl -w fs.file-max=2000000 > /dev/null 2>&1 || true
    sysctl -w net.core.somaxconn=65535 > /dev/null 2>&1 || true
    sysctl -w net.core.rmem_max=134217728 > /dev/null 2>&1 || true
    sysctl -w net.core.wmem_max=134217728 > /dev/null 2>&1 || true
    sysctl -w net.ipv4.tcp_fastopen=3 > /dev/null 2>&1 || true
    sysctl -w net.ipv4.tcp_tw_reuse=1 > /dev/null 2>&1 || true
    sysctl -w net.ipv4.ip_local_port_range="1024 65535" > /dev/null 2>&1 || true
    ulimit -n 1000000 2>/dev/null || ulimit -n 100000 2>/dev/null || true
}
echo "  - Kernel tuned"

echo ""
echo "=== Bootstrap Complete ==="
echo ""
echo "System info:"
echo "  - CPU: $(nproc) cores"
echo "  - RAM: $(free -h | awk '/^Mem:/{print $2}')"
echo "  - PHP: $(php -v | head -1 | cut -d' ' -f2)"
echo "  - Swoole: $(php -r 'echo SWOOLE_VERSION;')"
echo ""
echo "Running benchmarks..."
echo ""

# Run benchmark
cd "$WORKDIR"

echo "=== TCP Proxy Benchmark (connection rate) ==="
BENCH_PAYLOAD_BYTES=0 \
BENCH_CONCURRENCY=4000 \
BENCH_CONNECTIONS=400000 \
php benchmarks/tcp.php

echo ""
echo "=== TCP Proxy Benchmark (throughput) ==="
BENCH_PAYLOAD_BYTES=65536 \
BENCH_TARGET_BYTES=8589934592 \
BENCH_CONCURRENCY=2000 \
php benchmarks/tcp.php

echo ""
echo "=== Done ==="
echo "Results above. Re-run with different settings:"
echo "  cd $WORKDIR"
echo "  BENCH_CONCURRENCY=8000 BENCH_CONNECTIONS=800000 php benchmarks/tcp.php"
