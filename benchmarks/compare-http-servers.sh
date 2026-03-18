#!/usr/bin/env bash
set -euo pipefail

backend_host=${COMPARE_BACKEND_HOST:-127.0.0.1}
backend_port=${COMPARE_BACKEND_PORT:-5678}
backend_workers=${COMPARE_BACKEND_WORKERS:-}

host=${COMPARE_HOST:-127.0.0.1}
port=${COMPARE_PORT:-8080}

concurrency=${COMPARE_CONCURRENCY:-1000}
requests=${COMPARE_REQUESTS:-100000}
sample_every=${COMPARE_SAMPLE_EVERY:-5}
bench_keep_alive=${COMPARE_BENCH_KEEP_ALIVE:-true}
bench_timeout=${COMPARE_BENCH_TIMEOUT:-10}
runs=${COMPARE_RUNS:-1}

proxy_workers=${COMPARE_WORKERS:-8}
proxy_dispatch=${COMPARE_DISPATCH_MODE:-3}
proxy_reactor=${COMPARE_REACTOR_NUM:-16}
proxy_pool=${COMPARE_BACKEND_POOL_SIZE:-2048}
proxy_keepalive=${COMPARE_KEEPALIVE_TIMEOUT:-10}
proxy_http2=${COMPARE_OPEN_HTTP2:-false}
proxy_fast_assume_ok=${COMPARE_FAST_ASSUME_OK:-true}
proxy_server_mode=${COMPARE_SERVER_MODE:-base}

cleanup() {
  pkill -f "examples/http.php" >/dev/null 2>&1 || true
  pkill -f "php benchmarks/http-backend.php" >/dev/null 2>&1 || true
}
trap cleanup EXIT

start_backend() {
  pkill -f "php benchmarks/http-backend.php" >/dev/null 2>&1 || true
  if [ -n "${backend_workers}" ]; then
    nohup env BACKEND_HOST="${backend_host}" BACKEND_PORT="${backend_port}" BACKEND_WORKERS="${backend_workers}" \
      php benchmarks/http-backend.php > /tmp/http-backend.log 2>&1 &
  else
    nohup env BACKEND_HOST="${backend_host}" BACKEND_PORT="${backend_port}" \
      php benchmarks/http-backend.php > /tmp/http-backend.log 2>&1 &
  fi
  for _ in {1..20}; do
    if curl -s -o /dev/null -w "%{http_code}" "http://${backend_host}:${backend_port}/" | grep -q "200"; then
      return 0
    fi
    sleep 0.25
  done
  echo "Backend failed to start" >&2
  return 1
}

start_proxy() {
  local impl="$1"
  pkill -f "examples/http.php" >/dev/null 2>&1 || true
  nohup env \
    HTTP_SERVER_IMPL="${impl}" \
    HTTP_BACKEND_ENDPOINT="${backend_host}:${backend_port}" \
    HTTP_FIXED_BACKEND="${backend_host}:${backend_port}" \
    HTTP_FAST_ASSUME_OK="${proxy_fast_assume_ok}" \
    HTTP_SERVER_MODE="${proxy_server_mode}" \
    HTTP_WORKERS="${proxy_workers}" \
    HTTP_DISPATCH_MODE="${proxy_dispatch}" \
    HTTP_REACTOR_NUM="${proxy_reactor}" \
    HTTP_BACKEND_POOL_SIZE="${proxy_pool}" \
    HTTP_KEEPALIVE_TIMEOUT="${proxy_keepalive}" \
    HTTP_OPEN_HTTP2="${proxy_http2}" \
    php -d memory_limit=1G examples/http.php > /tmp/http-proxy.log 2>&1 &

  for _ in {1..20}; do
    if curl -s -o /dev/null -w "%{http_code}" "http://${host}:${port}/" | grep -q "200"; then
      return 0
    fi
    sleep 0.25
  done
  echo "Proxy failed to start for ${impl}" >&2
  return 1
}

run_bench() {
  local impl="$1"
  local run="$2"
  local output
  output=$(BENCH_HOST="${host}" BENCH_PORT="${port}" \
    BENCH_CONCURRENCY="${concurrency}" BENCH_REQUESTS="${requests}" \
    BENCH_SAMPLE_EVERY="${sample_every}" BENCH_KEEP_ALIVE="${bench_keep_alive}" \
    BENCH_TIMEOUT="${bench_timeout}" php -d memory_limit=1G benchmarks/http.php)
  local throughput
  throughput=$(echo "$output" | awk '/Throughput:/ {print $2; exit}')
  printf "%s,%s,%s\n" "$impl" "$run" "$throughput"
}

start_backend

printf "impl,run,throughput\n"
for impl in swoole coroutine; do
  start_proxy "$impl"
  for ((i=1; i<=runs; i++)); do
    run_bench "$impl" "$i"
  done
done
