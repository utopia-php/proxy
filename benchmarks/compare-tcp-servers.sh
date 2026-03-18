#!/usr/bin/env bash
set -euo pipefail

backend_host=${COMPARE_BACKEND_HOST:-127.0.0.1}
backend_port=${COMPARE_BACKEND_PORT:-15432}
backend_workers=${COMPARE_BACKEND_WORKERS:-}
backend_start=${COMPARE_BACKEND_START:-true}
if [ "$backend_start" != "true" ] && [ "$backend_start" != "false" ]; then
  backend_start=true
fi

host=${COMPARE_HOST:-127.0.0.1}
port=${COMPARE_PORT:-15433}
protocol=${COMPARE_PROTOCOL:-mysql}

mode=${COMPARE_MODE:-single}
if [ "$mode" != "single" ] && [ "$mode" != "match" ]; then
  mode=single
fi

concurrency=${COMPARE_CONCURRENCY:-2000}
connections=${COMPARE_CONNECTIONS:-100000}
payload_bytes=${COMPARE_PAYLOAD_BYTES:-0}
target_bytes=${COMPARE_TARGET_BYTES:-0}
benchmark_timeout=${COMPARE_TIMEOUT:-10}
sample_every=${COMPARE_SAMPLE_EVERY:-5}
runs=${COMPARE_RUNS:-1}
persistent=${COMPARE_PERSISTENT:-false}
stream_bytes=${COMPARE_STREAM_BYTES:-0}
stream_duration=${COMPARE_STREAM_DURATION:-0}
echo_newline=${COMPARE_ECHO_NEWLINE:-false}

proxy_workers=${COMPARE_WORKERS:-8}
proxy_reactor=${COMPARE_REACTOR_NUM:-}
proxy_dispatch=${COMPARE_DISPATCH_MODE:-2}
coro_processes=${COMPARE_CORO_PROCESSES:-}
coro_reactor=${COMPARE_CORO_REACTOR_NUM:-}

if [ -z "$proxy_reactor" ]; then
  if [ "$mode" = "single" ]; then
    proxy_reactor=1
  else
    proxy_reactor=16
  fi
fi

event_workers=$proxy_workers
if [ "$mode" = "single" ]; then
  event_workers=1
fi

if [ -z "$coro_processes" ]; then
  if [ "$mode" = "match" ]; then
    coro_processes=$event_workers
  else
    coro_processes=1
  fi
fi

if [ -z "$coro_reactor" ]; then
  if [ "$mode" = "match" ] && [ "$coro_processes" -gt 1 ]; then
    coro_reactor=1
  else
    coro_reactor=$proxy_reactor
  fi
fi

cleanup() {
  pkill -f "examples/tcp.php" >/dev/null 2>&1 || true
  pkill -f "php benchmarks/tcp-backend.php" >/dev/null 2>&1 || true
}
trap cleanup EXIT

start_backend() {
  if [ "$backend_start" = "false" ]; then
    return 0
  fi

  pkill -f "php benchmarks/tcp-backend.php" >/dev/null 2>&1 || true
  if [ -n "${backend_workers}" ]; then
    nohup env BACKEND_HOST="${backend_host}" BACKEND_PORT="${backend_port}" BACKEND_WORKERS="${backend_workers}" \
      php benchmarks/tcp-backend.php > /tmp/tcp-backend.log 2>&1 &
  else
    nohup env BACKEND_HOST="${backend_host}" BACKEND_PORT="${backend_port}" \
      php benchmarks/tcp-backend.php > /tmp/tcp-backend.log 2>&1 &
  fi

  for _ in {1..20}; do
    if php -r '$s=@stream_socket_client("tcp://'"${backend_host}:${backend_port}"'", $errno, $errstr, 0.2); if ($s) { fclose($s); exit(0);} exit(1);' >/dev/null 2>&1; then
      return 0
    fi
    sleep 0.25
  done
  echo "Backend failed to start" >&2
  return 1
}

start_proxy() {
  local impl="$1"
  pkill -f "examples/tcp.php" >/dev/null 2>&1 || true
  for _ in {1..20}; do
    if php -r '$s=@stream_socket_client("tcp://'\"${host}:${port}\"'", $errno, $errstr, 0.2); if ($s) { fclose($s); exit(0);} exit(1);' >/dev/null 2>&1; then
      sleep 0.25
    else
      break
    fi
  done
  if [ "$impl" = "coroutine" ]; then
    for _ in $(seq 1 "$coro_processes"); do
      nohup env \
        TCP_SERVER_IMPL="${impl}" \
        TCP_BACKEND_ENDPOINT="${backend_host}:${backend_port}" \
        TCP_POSTGRES_PORT="${port}" \
        TCP_MYSQL_PORT=0 \
        TCP_WORKERS=1 \
        TCP_REACTOR_NUM="${coro_reactor}" \
        TCP_DISPATCH_MODE="${proxy_dispatch}" \
        TCP_SKIP_VALIDATION=true \
        php -d memory_limit=1G examples/tcp.php > /tmp/tcp-proxy.log 2>&1 &
    done
  else
    nohup env \
      TCP_SERVER_IMPL="${impl}" \
      TCP_BACKEND_ENDPOINT="${backend_host}:${backend_port}" \
      TCP_POSTGRES_PORT="${port}" \
      TCP_MYSQL_PORT=0 \
      TCP_WORKERS="${event_workers}" \
      TCP_REACTOR_NUM="${proxy_reactor}" \
      TCP_DISPATCH_MODE="${proxy_dispatch}" \
      TCP_SKIP_VALIDATION=true \
      php -d memory_limit=1G examples/tcp.php > /tmp/tcp-proxy.log 2>&1 &
  fi

  for _ in {1..20}; do
    if php -r '$s=@stream_socket_client("tcp://'"${host}:${port}"'", $errno, $errstr, 0.2); if ($s) { fclose($s); exit(0);} exit(1);' >/dev/null 2>&1; then
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
  output=$(BENCH_HOST="${host}" BENCH_PORT="${port}" BENCH_PROTOCOL="${protocol}" \
    BENCH_CONCURRENCY="${concurrency}" BENCH_CONNECTIONS="${connections}" \
    BENCH_PAYLOAD_BYTES="${payload_bytes}" BENCH_TARGET_BYTES="${target_bytes}" \
    BENCH_TIMEOUT="${benchmark_timeout}" BENCH_SAMPLE_EVERY="${sample_every}" \
    BENCH_PERSISTENT="${persistent}" BENCH_STREAM_BYTES="${stream_bytes}" \
    BENCH_STREAM_DURATION="${stream_duration}" BENCH_ECHO_NEWLINE="${echo_newline}" \
    php -d memory_limit=1G benchmarks/tcp.php)
  local conn_rate
  local throughput
  conn_rate=$(echo "$output" | awk '/Connections\/sec:/ {print $2; exit}')
  throughput=$(echo "$output" | awk '/Throughput:/ {print $2; exit}')
  printf "%s,%s,%s,%s\n" "$impl" "$run" "$conn_rate" "$throughput"
}

start_backend

for _ in {1..10}; do
  if php -r '$s=@stream_socket_client("tcp://'"${backend_host}:${backend_port}"'", $errno, $errstr, 0.5); if ($s) { fclose($s); exit(0);} exit(1);' >/dev/null 2>&1; then
    break
  fi
  sleep 0.5
done

printf "impl,run,connections_per_sec,throughput_gb\n"
for impl in swoole coroutine; do
  start_proxy "$impl"
  for ((i=1; i<=runs; i++)); do
    run_bench "$impl" "$i"
  done
done
