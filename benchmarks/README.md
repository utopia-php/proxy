# Benchmarks

This folder contains high-load benchmark helpers for HTTP and TCP proxies.

## Quick start (HTTP)

Run the PHP benchmark:
```bash
php benchmarks/http.php
```

Run wrk:
```bash
benchmarks/wrk.sh
```

Run wrk2 (fixed rate):
```bash
benchmarks/wrk2.sh
```

## Quick start (TCP)

Run the TCP benchmark:
```bash
php benchmarks/tcp.php
```

## Presets (HTTP)

Max throughput, burst:
```bash
WRK_THREADS=16 WRK_CONNECTIONS=5000 WRK_DURATION=30s WRK_URL=http://127.0.0.1:8080/ benchmarks/wrk.sh
```

Fixed rate (wrk2):
```bash
WRK2_THREADS=16 WRK2_CONNECTIONS=5000 WRK2_DURATION=30s WRK2_RATE=200000 WRK2_URL=http://127.0.0.1:8080/ benchmarks/wrk2.sh
```

PHP benchmark, moderate:
```bash
BENCH_CONCURRENCY=500 BENCH_REQUESTS=50000 php benchmarks/http.php
```

## Presets (TCP)

Connection rate only:
```bash
BENCH_PROTOCOL=mysql BENCH_PORT=15433 BENCH_PAYLOAD_BYTES=0 BENCH_CONCURRENCY=500 BENCH_CONNECTIONS=50000 php benchmarks/tcp.php
```

Throughput heavy (payload enabled):
```bash
BENCH_PROTOCOL=mysql BENCH_PORT=15433 BENCH_PAYLOAD_BYTES=65536 BENCH_TARGET_BYTES=17179869184 BENCH_CONCURRENCY=2000 php benchmarks/tcp.php
```

## Environment variables

HTTP PHP benchmark (`benchmarks/http.php`):
- `BENCH_HOST` (default `localhost`)
- `BENCH_PORT` (default `8080`)
- `BENCH_CONCURRENCY` (default `max(2000, cpu*500)`)
- `BENCH_REQUESTS` (default `max(1000000, concurrency*500)`)
- `BENCH_TIMEOUT` (default `10`)
- `BENCH_KEEP_ALIVE` (default `true`)
- `BENCH_SAMPLE_TARGET` (default `200000`)
- `BENCH_SAMPLE_EVERY` (optional override)

TCP PHP benchmark (`benchmarks/tcp.php`):
- `BENCH_HOST` (default `localhost`)
- `BENCH_PORT` (default `5432`)
- `BENCH_PROTOCOL` (`postgres` or `mysql`, default based on port)
- `BENCH_CONCURRENCY` (default `max(2000, cpu*500)`)
- `BENCH_CONNECTIONS` (default derived from payload/target)
- `BENCH_PAYLOAD_BYTES` (default `65536`)
- `BENCH_TARGET_BYTES` (default `8GB`)
- `BENCH_TIMEOUT` (default `10`)
- `BENCH_SAMPLE_TARGET` (default `200000`)
- `BENCH_SAMPLE_EVERY` (optional override)

wrk (`benchmarks/wrk.sh`):
- `WRK_THREADS` (default `cpu`)
- `WRK_CONNECTIONS` (default `1000`)
- `WRK_DURATION` (default `30s`)
- `WRK_URL` (default `http://127.0.0.1:8080/`)
- `WRK_EXTRA` (extra flags)

wrk2 (`benchmarks/wrk2.sh`):
- `WRK2_THREADS` (default `cpu`)
- `WRK2_CONNECTIONS` (default `1000`)
- `WRK2_DURATION` (default `30s`)
- `WRK2_RATE` (default `50000`)
- `WRK2_URL` (default `http://127.0.0.1:8080/`)
- `WRK2_EXTRA` (extra flags)

## Notes

- For realistic max numbers, run on a tuned Linux host (see `PERFORMANCE.md`).
- Running in Docker on macOS will be bottlenecked by the VM and host networking.
