# Benchmarks

## Quick Start

Start a backend, then run the benchmark against it:

```bash
# HTTP
php benchmarks/http-backend.php &
php benchmarks/http.php

# TCP
php benchmarks/tcp-backend.php &
php benchmarks/tcp.php
```

## HTTP Benchmark

```bash
BENCH_CONCURRENCY=5000 BENCH_REQUESTS=2000000 php benchmarks/http.php
```

| Variable | Default | Description |
|----------|---------|-------------|
| `BENCH_HOST` | `localhost` | Target host |
| `BENCH_PORT` | `8080` | Target port |
| `BENCH_CONCURRENCY` | `cpu*500` | Concurrent workers |
| `BENCH_REQUESTS` | `concurrency*500` | Total requests |
| `BENCH_KEEP_ALIVE` | `true` | Reuse connections |
| `BENCH_TIMEOUT` | `10` | Request timeout (seconds) |

## TCP Benchmark (C client — preferred)

The PHP bench client has its own per-process ceiling (~10-11k ops/sec) that
caps measured throughput well below the proxy's actual capacity. For realistic
numbers use the C load generator, which pins the proxy workers at 80-90% CPU:

```bash
composer bench:build                      # gcc -O2 -pthread -o benchmarks/tcpbench benchmarks/tcpbench.c

# Request/response throughput (1 KB payloads)
./benchmarks/tcpbench rr   -h 127.0.0.1 -p 5432 -c 200 -d 10 -s 1024

# Connection rate
./benchmarks/tcpbench rate -h 127.0.0.1 -p 5432 -c 200 -d 10

# Bulk throughput (64 KB payloads)
./benchmarks/tcpbench rr   -h 127.0.0.1 -p 5432 -c 200 -d 10 -s 65536
```

Flags: `-h host`, `-p port`, `-c concurrency`, `-d duration`, `-s payload_size`.
Each thread owns one TCP connection, uses blocking IO, and counts ops via
atomic counters. Modes: `rr` (persistent req/resp loop), `rate` (connect →
handshake → close loop).

## TCP Benchmark (PHP client — legacy)

Functional testing only; numbers are latency-bound, not proxy-bound.

```bash
# Connection rate (no payload)
BENCH_PAYLOAD_BYTES=0 BENCH_CONNECTIONS=400000 php benchmarks/tcp.php

# Throughput (64KB payload)
BENCH_PAYLOAD_BYTES=65536 BENCH_TARGET_BYTES=17179869184 php benchmarks/tcp.php

# Sustained streaming
BENCH_PERSISTENT=true BENCH_STREAM_DURATION=60 php benchmarks/tcp.php
```

| Variable | Default | Description |
|----------|---------|-------------|
| `BENCH_HOST` | `localhost` | Target host |
| `BENCH_PORT` | `5432` | Target port |
| `BENCH_PROTOCOL` | auto | `postgres` or `mysql` (based on port) |
| `BENCH_CONCURRENCY` | `cpu*500` | Concurrent workers |
| `BENCH_CONNECTIONS` | derived | Total connections |
| `BENCH_PAYLOAD_BYTES` | `65536` | Bytes per connection |
| `BENCH_TARGET_BYTES` | `8GB` | Total bytes target |
| `BENCH_PERSISTENT` | `false` | Keep connections open |
| `BENCH_STREAM_DURATION` | `0` | Stream duration in seconds |
| `BENCH_TIMEOUT` | `10` | Connection timeout (seconds) |

## Kernel Tuning

```bash
sudo ./benchmarks/setup.sh              # Aggressive (benchmarks)
sudo ./benchmarks/setup.sh --production # Conservative (production)
sudo ./benchmarks/setup.sh --persist    # Survive reboots
```

## BPF Sockmap Zero-Copy Relay

For workloads where the proxy's userspace forwarding path is the bottleneck
(small-request req/resp at high concurrency), the proxy can hand each
`(client fd, backend fd)` pair to the Linux kernel via a BPF sockmap. Once
the pair is inserted, every incoming TCP segment on either socket is
redirected to its peer by an `sk_skb/stream_verdict` program, without the
bytes ever crossing into userspace. The PHP worker only runs for the initial
handshake and close events.

### Requirements

- Linux 4.17+ (`BPF_MAP_TYPE_SOCKHASH` + `sk_skb/stream_verdict` +
  `bpf_sk_redirect_hash`). Verified on 6.8.
- `libbpf` ≥ 1.0 on the runtime path as `libbpf.so.1`
  (`apt install libbpf1` on Debian/Ubuntu).
- `libc.so.6` for `getsockname`/`getpeername` via FFI (always present).
- `ext-ffi` enabled in PHP.
- `CAP_BPF` or run the proxy as root, OR
  `sudo sysctl kernel.unprivileged_bpf_disabled=0` to let regular users load
  BPF programs.
- `clang` with BPF target for building `relay.bpf.o`
  (`apt install clang libbpf-dev`).

### Build the BPF object

```bash
composer bench:bpf        # clang -target bpf + gcc for the PoC binary
```

This produces:
- `src/Sockmap/relay.bpf.o` — the precompiled BPF program loaded by
  `Utopia\Proxy\Sockmap\Loader` at proxy worker start.
- `benchmarks/sockmap_poc/relay_test` — a standalone correctness +
  throughput harness for the sockmap path with no PHP in the loop.

### Enable sockmap in the proxy

The proxy server reads two env vars from `examples/tcp.php`:

```bash
TCP_SOCKMAP_ENABLED=1 \
TCP_SOCKMAP_BPF_OBJECT=/path/to/relay.bpf.o \
php examples/tcp.php
```

or construct `Config` directly:

```php
$config = new Utopia\Proxy\Server\TCP\Config(
    ports: [5432],
    sockmapEnabled: true,
    sockmapBpfObject: __DIR__ . '/../src/Sockmap/relay.bpf.o',
);
```

At worker start each Swoole worker loads its own copy of the BPF object and
attaches an `sk_skb/stream_verdict` program to a per-worker sockhash. Logs
show `Sockmap: enabled (kernel zero-copy relay)` on success; on any failure
(missing ext-ffi, no libbpf, no CAP_BPF, missing `relay.bpf.o`, incompatible
kernel) the worker falls through to the existing userspace relay path and
logs `Sockmap: unavailable (<reason>)`.

### When sockmap helps

Sockmap delivers wins when **userspace coroutine dispatch is the bottleneck**
— small-request, high-concurrency workloads that saturate the PHP path. On
network-bound workloads (where the bottleneck is the NIC or the backend
hop), sockmap is a wash because the proxy's CPU isn't what's slow. On real
database workloads the backend query time dominates and sockmap makes no
measurable difference either way.

**Synthetic echo, backend on loopback** (proxy CPU is the limit), `tcpbench rr -s 1024`:

| Concurrency | Sockmap    | Userspace  | Delta  |
|-------------|-----------:|-----------:|-------:|
| 10          |     40,713 |     39,611 |  +2.8% |
| 500         |     78,915 |     73,061 |  +8.0% |
| 1000        |     76,708 |     69,651 | +10.1% |
| 2000        |     73,139 |     64,732 | +13.0% |

**Synthetic echo, backend on separate 8-core over VPC** (network is the limit):

| Concurrency | Sockmap    | Userspace  | Delta  |
|-------------|-----------:|-----------:|-------:|
| 10          |     33,362 |     33,558 |  ±0%   |
| 100         |     98,209 |     99,770 |  ±0%   |
| 500         |    106,379 |    107,519 |  ±0%   |
| 1000        |    104,453 |    105,010 |  ±0%   |

**Real PostgreSQL backend via VPC** (pgbench SELECT, 8s, through proxy vs
direct-to-postgres baseline). Backend CPU (query execution) dominates here:

| Clients | Direct | Userspace proxy | Sockmap proxy | Sockmap vs Userspace |
|---------|-------:|----------------:|--------------:|---------------------:|
| 5       | 14,682 |          10,143 |         9,629 |                  -5% |
| 10      | 20,246 |          17,830 |        17,524 |                  -2% |
| 30      | 20,938 |          21,369 |        21,420 |                  ±0% |
| 50      | 21,412 |          21,545 |        20,938 |                  -3% |
| 100     | 20,666 |          21,852 |        21,099 |                  -3% |

**pgbench bulk-read** (100-row SELECT per transaction):

| Clients | Userspace | Sockmap | Delta |
|---------|----------:|--------:|------:|
| 10      |    15,624 |  15,481 |   ±0% |
| 30      |    21,881 |  22,562 |   +3% |
| 50      |    23,385 |  24,334 |   +4% |

### Rule of thumb for deployment

Turn sockmap on when profiling (perf, pidstat) shows the proxy's PHP
workers pegged at high CPU with the backend and network both idle —
that's the small-packet high-concurrency CPU-bound case. For real DB
and application workloads where the backend is the slow thing, the
userspace path is already fast enough and the extra moving parts
(BPF toolchain, libbpf dependency, CAP_BPF) aren't worth it.

### Verifying it's actually in the data path

After starting a benchmark run, check the TCP stats on the proxy's backend
connections. Real sockmap redirect shows meaningful `bytes_sent` / `bytes_received`
per connection; a broken integration shows only the handshake (~42 bytes):

```bash
ss -t -i state established '( dport = BACKEND_PORT )' | grep bytes_sent
```

Should see `bytes_sent: 10000000`-ish per active connection during a load
test, not `bytes_sent: 42`.

### Limitations

- **Plaintext TCP only.** TLS-terminated connections need userspace for the
  crypto; the loader skips sockmap insertion on TLS sockets and the
  userspace relay handles them.
- **IPv4 only** in the current implementation. The tuple key in `relay.bpf.c`
  packs `remote_ip4` / `local_port` / `remote_port` — IPv6 would need a
  separate path using the `_ip6` fields.
- **No resolver reinspection after activation.** Once sockmap takes over
  the pair, the PHP code never sees subsequent bytes, so any routing logic
  that wants to parse later packets won't run. Content-based routing on the
  first packet still works because sockmap activation happens after the
  initial handshake send.
- **One sockhash per worker.** Each Swoole worker loads its own BPF object,
  so there's no cross-worker sharing. Fine for the typical proxy topology.

## Reference Numbers (8-core, 32GB RAM)

| Metric | Result |
|--------|--------|
| Peak concurrent connections | 672,348 |
| Memory per connection | ~33 KB |
| Connection rate (sustained) | 18,067/sec |
| CPU at peak | ~60% |
