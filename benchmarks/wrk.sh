#!/usr/bin/env bash
set -euo pipefail

if ! command -v wrk >/dev/null 2>&1; then
  echo "wrk not found. Install wrk or set WRK_BIN." >&2
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

threads="${WRK_THREADS:-$(cpu_count)}"
connections="${WRK_CONNECTIONS:-1000}"
duration="${WRK_DURATION:-30s}"
url="${WRK_URL:-http://127.0.0.1:8080/}"

extra_args=()
if [[ -n "${WRK_EXTRA:-}" ]]; then
  read -r -a extra_args <<< "${WRK_EXTRA}"
fi

echo "Running: wrk -t${threads} -c${connections} -d${duration} ${extra_args[*]} ${url}"
exec wrk -t"${threads}" -c"${connections}" -d"${duration}" "${extra_args[@]}" "${url}"
