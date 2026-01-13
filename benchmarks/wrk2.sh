#!/usr/bin/env bash
set -euo pipefail

if ! command -v wrk2 >/dev/null 2>&1; then
  echo "wrk2 not found. Install wrk2 or set WRK2_BIN." >&2
  exit 1
fi

cpu_count() {
  if command -v nproc >/dev/null 2>&1; then
    nproc
    return
  fi
  if command -v getconf >/dev/null 2>&1; then
    getconf _NPROCESSORS_ONLN
    return
  fi
  if command -v sysctl >/dev/null 2>&1; then
    sysctl -n hw.ncpu 2>/dev/null || echo 4
    return
  fi
  echo 4
}

threads="${WRK2_THREADS:-$(cpu_count)}"
connections="${WRK2_CONNECTIONS:-1000}"
duration="${WRK2_DURATION:-30s}"
rate="${WRK2_RATE:-50000}"
url="${WRK2_URL:-http://127.0.0.1:8080/}"

extra_args=()
if [[ -n "${WRK2_EXTRA:-}" ]]; then
  read -r -a extra_args <<< "${WRK2_EXTRA}"
fi

echo "Running: wrk2 -t${threads} -c${connections} -d${duration} -R${rate} ${extra_args[*]} ${url}"
exec wrk2 -t"${threads}" -c"${connections}" -d"${duration}" -R"${rate}" "${extra_args[@]}" "${url}"
