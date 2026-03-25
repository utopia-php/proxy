#!/bin/sh
#
# Linux kernel tuning for TCP proxy benchmarks/production.
#
# Usage:
#   sudo ./benchmarks/setup.sh              # Aggressive (benchmark)
#   sudo ./benchmarks/setup.sh --production # Conservative (production-safe)
#   sudo ./benchmarks/setup.sh --persist    # Write to /etc/sysctl.d for reboot survival
#
set -e

PRODUCTION=0
PERSIST=0
for arg in "$@"; do
    case "$arg" in
        --production) PRODUCTION=1 ;;
        --persist) PERSIST=1 ;;
    esac
done

if [ "$(id -u)" -ne 0 ]; then
    echo "Error: run as root (sudo)"
    exit 1
fi

SYSCTL_FILE="/etc/sysctl.d/99-tcp-proxy.conf"

if [ $PRODUCTION -eq 1 ]; then
    echo "=== Production Tuning ==="
    FILE_MAX=1000000
    SOMAXCONN=32768
    BUF_MAX=67108864
    TCP_BUF_MAX=33554432
    FIN_TIMEOUT=30
    MAX_ORPHANS=65536
    MAX_TW=500000
    TCP_MEM="524288 786432 1048576"
else
    echo "=== Benchmark Tuning ==="
    FILE_MAX=2000000
    SOMAXCONN=65535
    BUF_MAX=134217728
    TCP_BUF_MAX=67108864
    FIN_TIMEOUT=10
    MAX_ORPHANS=262144
    MAX_TW=2000000
    TCP_MEM="786432 1048576 1572864"
fi

echo ""

sysctl -w fs.file-max=$FILE_MAX >/dev/null
sysctl -w fs.nr_open=$FILE_MAX >/dev/null
sysctl -w net.core.somaxconn=$SOMAXCONN >/dev/null
sysctl -w net.ipv4.tcp_max_syn_backlog=$SOMAXCONN >/dev/null
sysctl -w net.core.netdev_max_backlog=$SOMAXCONN >/dev/null
sysctl -w net.core.rmem_max=$BUF_MAX >/dev/null
sysctl -w net.core.wmem_max=$BUF_MAX >/dev/null
sysctl -w net.ipv4.tcp_rmem="4096 87380 $TCP_BUF_MAX" >/dev/null
sysctl -w net.ipv4.tcp_wmem="4096 65536 $TCP_BUF_MAX" >/dev/null
sysctl -w net.ipv4.tcp_fastopen=3 >/dev/null
sysctl -w net.ipv4.tcp_fin_timeout=$FIN_TIMEOUT >/dev/null
sysctl -w net.ipv4.tcp_tw_reuse=1 >/dev/null
sysctl -w net.ipv4.tcp_window_scaling=1 >/dev/null
sysctl -w net.ipv4.tcp_sack=1 >/dev/null
sysctl -w net.ipv4.ip_local_port_range="1024 65535" >/dev/null
sysctl -w net.ipv4.tcp_max_orphans=$MAX_ORPHANS >/dev/null
sysctl -w net.ipv4.tcp_max_tw_buckets=$MAX_TW >/dev/null
sysctl -w net.ipv4.tcp_mem="$TCP_MEM" >/dev/null
sysctl -w vm.max_map_count=262144 >/dev/null

if [ $PRODUCTION -eq 0 ]; then
    sysctl -w net.ipv4.tcp_slow_start_after_idle=0 >/dev/null
    sysctl -w net.ipv4.tcp_no_metrics_save=1 >/dev/null
    sysctl -w net.core.rmem_default=262144 >/dev/null
    sysctl -w net.core.wmem_default=262144 >/dev/null
else
    sysctl -w net.ipv4.tcp_keepalive_time=300 >/dev/null
    sysctl -w net.ipv4.tcp_keepalive_intvl=30 >/dev/null
    sysctl -w net.ipv4.tcp_keepalive_probes=5 >/dev/null
fi

ulimit -n "$FILE_MAX" 2>/dev/null || ulimit -n 1000000 2>/dev/null || ulimit -n 500000

if [ $PERSIST -eq 1 ]; then
    cat > "$SYSCTL_FILE" << EOF
fs.file-max = $FILE_MAX
fs.nr_open = $FILE_MAX
net.core.somaxconn = $SOMAXCONN
net.ipv4.tcp_max_syn_backlog = $SOMAXCONN
net.core.netdev_max_backlog = $SOMAXCONN
net.core.rmem_max = $BUF_MAX
net.core.wmem_max = $BUF_MAX
net.ipv4.tcp_rmem = 4096 87380 $TCP_BUF_MAX
net.ipv4.tcp_wmem = 4096 65536 $TCP_BUF_MAX
net.ipv4.tcp_fastopen = 3
net.ipv4.tcp_fin_timeout = $FIN_TIMEOUT
net.ipv4.tcp_tw_reuse = 1
net.ipv4.ip_local_port_range = 1024 65535
net.ipv4.tcp_max_orphans = $MAX_ORPHANS
net.ipv4.tcp_max_tw_buckets = $MAX_TW
net.ipv4.tcp_mem = $TCP_MEM
vm.max_map_count = 262144
EOF
    echo "Persisted to $SYSCTL_FILE"
fi

if [ -f /sys/devices/system/cpu/cpu0/cpufreq/scaling_governor ]; then
    for cpu in /sys/devices/system/cpu/cpu*/cpufreq/scaling_governor; do
        echo "performance" > "$cpu" 2>/dev/null || true
    done
fi

echo "File descriptors: $(ulimit -n)"
echo "Somaxconn: $(sysctl -n net.core.somaxconn)"
echo "Port range: $(sysctl -n net.ipv4.ip_local_port_range)"
echo ""
echo "Ready."
