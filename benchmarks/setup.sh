#!/bin/sh
#
# Linux kernel + host tuning for TCP proxy benchmarks/production.
#
# Usage:
#   sudo ./benchmarks/setup.sh              # Aggressive (benchmark)
#   sudo ./benchmarks/setup.sh --production # Conservative (production-safe)
#   sudo ./benchmarks/setup.sh --persist    # Write to /etc/sysctl.d for reboot survival
#
# Covers: file descriptor limits, TCP buffer sizing, SYN/accept queues,
# time-wait recycling, fast open, keepalive, orphans, NUMA-friendly VM
# pressure, transparent huge pages, CPU governor, NIC interrupt coalescing
# hints, and receive packet steering. Matches the tunables HAProxy relies
# on to reach its published numbers.
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
LIMITS_FILE="/etc/security/limits.d/99-tcp-proxy.conf"

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
    NETDEV_BACKLOG=30000
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
    NETDEV_BACKLOG=65535
fi

echo ""

# File descriptors
sysctl -w fs.file-max=$FILE_MAX >/dev/null
sysctl -w fs.nr_open=$FILE_MAX >/dev/null

# Accept queue / SYN backlog
sysctl -w net.core.somaxconn=$SOMAXCONN >/dev/null
sysctl -w net.ipv4.tcp_max_syn_backlog=$SOMAXCONN >/dev/null
sysctl -w net.core.netdev_max_backlog=$NETDEV_BACKLOG >/dev/null

# Socket buffer ceilings (autotuning targets)
sysctl -w net.core.rmem_max=$BUF_MAX >/dev/null
sysctl -w net.core.wmem_max=$BUF_MAX >/dev/null
sysctl -w net.ipv4.tcp_rmem="4096 87380 $TCP_BUF_MAX" >/dev/null
sysctl -w net.ipv4.tcp_wmem="4096 65536 $TCP_BUF_MAX" >/dev/null

# Connection lifecycle
sysctl -w net.ipv4.tcp_fastopen=3 >/dev/null
sysctl -w net.ipv4.tcp_fin_timeout=$FIN_TIMEOUT >/dev/null
sysctl -w net.ipv4.tcp_tw_reuse=1 >/dev/null
sysctl -w net.ipv4.tcp_window_scaling=1 >/dev/null
sysctl -w net.ipv4.tcp_sack=1 >/dev/null
sysctl -w net.ipv4.tcp_timestamps=1 >/dev/null
sysctl -w net.ipv4.ip_local_port_range="1024 65535" >/dev/null
sysctl -w net.ipv4.tcp_max_orphans=$MAX_ORPHANS >/dev/null
sysctl -w net.ipv4.tcp_max_tw_buckets=$MAX_TW >/dev/null
sysctl -w net.ipv4.tcp_mem="$TCP_MEM" >/dev/null

# Defeat SYN flood without hurting legit clients
sysctl -w net.ipv4.tcp_syncookies=1 >/dev/null

# VM tuning for long-lived worker processes
sysctl -w vm.max_map_count=262144 >/dev/null
sysctl -w vm.swappiness=10 >/dev/null

if [ $PRODUCTION -eq 0 ]; then
    sysctl -w net.ipv4.tcp_slow_start_after_idle=0 >/dev/null
    sysctl -w net.ipv4.tcp_no_metrics_save=1 >/dev/null
    sysctl -w net.core.rmem_default=262144 >/dev/null
    sysctl -w net.core.wmem_default=262144 >/dev/null
    sysctl -w net.ipv4.tcp_low_latency=1 >/dev/null 2>&1 || true
    sysctl -w net.ipv4.tcp_notsent_lowat=16384 >/dev/null 2>&1 || true
else
    sysctl -w net.ipv4.tcp_keepalive_time=300 >/dev/null
    sysctl -w net.ipv4.tcp_keepalive_intvl=30 >/dev/null
    sysctl -w net.ipv4.tcp_keepalive_probes=5 >/dev/null
fi

# Current shell limits
ulimit -n "$FILE_MAX" 2>/dev/null || ulimit -n 1000000 2>/dev/null || ulimit -n 500000

# Persist file descriptor limits across sessions so systemd units pick them up
cat > "$LIMITS_FILE" << EOF
*       soft    nofile  $FILE_MAX
*       hard    nofile  $FILE_MAX
root    soft    nofile  $FILE_MAX
root    hard    nofile  $FILE_MAX
EOF

# Transparent huge pages: set to madvise for mixed workloads. THP always-on
# causes allocation stalls under PHP's zend_mm churn.
if [ -f /sys/kernel/mm/transparent_hugepage/enabled ]; then
    echo madvise > /sys/kernel/mm/transparent_hugepage/enabled 2>/dev/null || true
fi
if [ -f /sys/kernel/mm/transparent_hugepage/defrag ]; then
    echo madvise > /sys/kernel/mm/transparent_hugepage/defrag 2>/dev/null || true
fi

# CPU governor: performance (max frequency, no P-state ramp-up latency)
if [ -f /sys/devices/system/cpu/cpu0/cpufreq/scaling_governor ]; then
    for cpu in /sys/devices/system/cpu/cpu*/cpufreq/scaling_governor; do
        echo "performance" > "$cpu" 2>/dev/null || true
    done
fi

# Receive packet steering (RPS) — spread soft IRQs across cores when the
# NIC has fewer hardware queues than cores. Skipped silently on single-core
# or virtualised hosts where it isn't useful.
CPU_COUNT=$(nproc 2>/dev/null || echo 1)
if [ "$CPU_COUNT" -gt 1 ]; then
    RPS_MASK=$(printf '%x' $(( (1 << CPU_COUNT) - 1 )))
    for rx in /sys/class/net/*/queues/rx-*/rps_cpus; do
        [ -w "$rx" ] && echo "$RPS_MASK" > "$rx" 2>/dev/null || true
    done
    for flow in /sys/class/net/*/queues/rx-*/rps_flow_cnt; do
        [ -w "$flow" ] && echo 4096 > "$flow" 2>/dev/null || true
    done
    if [ -w /proc/sys/net/core/rps_sock_flow_entries ]; then
        sysctl -w net.core.rps_sock_flow_entries=32768 >/dev/null 2>&1 || true
    fi
fi

# Disable GRO/GSO offload adjustments only if explicitly requested — by
# default these are beneficial. Mention them for operators tuning NIC hw.
echo "NIC tuning tip: check 'ethtool -k <iface>' for tso/gso/gro/rx-checksumming"
echo "               and 'ethtool -c <iface>' for interrupt coalescing"
echo "               consider 'ethtool -C <iface> adaptive-rx on adaptive-tx on'"
echo "IRQ affinity tip: pin NIC RX/TX queues to dedicated cores and keep"
echo "                  proxy workers on the remaining cores to avoid"
echo "                  cache line bouncing with softirq processing."

if [ $PERSIST -eq 1 ]; then
    cat > "$SYSCTL_FILE" << EOF
fs.file-max = $FILE_MAX
fs.nr_open = $FILE_MAX
net.core.somaxconn = $SOMAXCONN
net.ipv4.tcp_max_syn_backlog = $SOMAXCONN
net.core.netdev_max_backlog = $NETDEV_BACKLOG
net.core.rmem_max = $BUF_MAX
net.core.wmem_max = $BUF_MAX
net.ipv4.tcp_rmem = 4096 87380 $TCP_BUF_MAX
net.ipv4.tcp_wmem = 4096 65536 $TCP_BUF_MAX
net.ipv4.tcp_fastopen = 3
net.ipv4.tcp_fin_timeout = $FIN_TIMEOUT
net.ipv4.tcp_tw_reuse = 1
net.ipv4.tcp_syncookies = 1
net.ipv4.tcp_timestamps = 1
net.ipv4.ip_local_port_range = 1024 65535
net.ipv4.tcp_max_orphans = $MAX_ORPHANS
net.ipv4.tcp_max_tw_buckets = $MAX_TW
net.ipv4.tcp_mem = $TCP_MEM
vm.max_map_count = 262144
vm.swappiness = 10
EOF
    echo "Persisted sysctl to $SYSCTL_FILE"
    echo "Persisted nofile limits to $LIMITS_FILE"
fi

# jemalloc: replaces glibc malloc for the whole worker, cuts fragmentation
# under long-lived connection churn. Swoole is officially tested against it.
# Export LD_PRELOAD before running the proxy:
JEMALLOC_PATH=""
for candidate in \
    /usr/lib/x86_64-linux-gnu/libjemalloc.so.2 \
    /usr/lib/aarch64-linux-gnu/libjemalloc.so.2 \
    /usr/lib64/libjemalloc.so.2 \
    /usr/local/lib/libjemalloc.so.2; do
    if [ -f "$candidate" ]; then
        JEMALLOC_PATH="$candidate"
        break
    fi
done
if [ -n "$JEMALLOC_PATH" ]; then
    echo ""
    echo "jemalloc found at: $JEMALLOC_PATH"
    echo "Recommended: export LD_PRELOAD=$JEMALLOC_PATH"
else
    echo ""
    echo "jemalloc not found. Install with:"
    echo "  Debian/Ubuntu: apt install libjemalloc2"
    echo "  RHEL/Fedora:   dnf install jemalloc"
fi

echo ""
echo "PHP JIT: set opcache.jit=tracing opcache.jit_buffer_size=128M"
echo "         verify via opcache_get_status()['jit']['on'] === true"
echo "         (the proxy logs 'JIT: enabled' or 'JIT: disabled' at worker start)"

echo ""
echo "File descriptors: $(ulimit -n)"
echo "Somaxconn: $(sysctl -n net.core.somaxconn)"
echo "Port range: $(sysctl -n net.ipv4.ip_local_port_range)"
echo "Swappiness: $(sysctl -n vm.swappiness)"
if [ -f /sys/kernel/mm/transparent_hugepage/enabled ]; then
    echo "THP: $(cat /sys/kernel/mm/transparent_hugepage/enabled)"
fi
echo ""
echo "Ready."
