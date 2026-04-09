# Utopia Proxy

High-performance, protocol-agnostic proxy library built on Swoole for HTTP, TCP (PostgreSQL, MySQL, MongoDB), and SMTP proxying. Handles 670k+ concurrent connections per server.

## Commands

| Command | Purpose |
|---------|---------|
| `composer test` | Run unit tests |
| `composer test:integration` | Run integration tests (requires Docker) |
| `composer test:all` | Run all tests (unit + integration) |
| `composer lint` | Check formatting (Pint, PSR-12) |
| `composer format` | Auto-format code |
| `composer check` | Static analysis (PHPStan, max level, 2GB) |
| `composer bench:http` | HTTP proxy benchmarks |
| `composer bench:tcp` | TCP proxy benchmarks |
| `composer bench:build` | Compile C load generator (tcpbench) |
| `composer bench:bpf` | Compile BPF sockmap object |

Run a single test:
```bash
./vendor/bin/phpunit tests/ResolverTest.php
./vendor/bin/phpunit tests/ResolverTest.php --filter=testResolverResultStoresValues
```

## Stack

- PHP 8.4+, ext-swoole >= 6.0, ext-redis
- PHPUnit 12, Pint (PSR-12), PHPStan (max level)
- Docker multi-stage build (BPF builder + PHP runtime)

## Project layout

- **src/** -- library code (PSR-4 namespace `Utopia\Proxy\`)
  - `Adapter.php` -- base adapter with routing, caching, SSRF validation
  - `Adapter/TCP.php` -- TCP adapter with protocol auto-detection by port, sockmap integration
  - `Resolver.php` -- single-method interface: `resolve(string $data): Result`
  - `Resolver/Result.php` -- resolver result: endpoint, metadata, timeout
  - `Resolver/Fixed.php` -- static resolver that always returns the same endpoint
  - `Resolver/Exception.php` -- exceptions with HTTP codes (404/503/504/403/500)
  - `ConnectionResult.php` -- immutable result with endpoint and metadata
  - `Protocol.php` -- enum with 28 protocol types
  - `Dns.php` -- coroutine-aware DNS resolver with caching
  - `Sockmap/Loader.php` -- BPF sockmap loader via FFI (libbpf)
  - `Server/HTTP/` -- HTTP proxy server
    - `Config.php` -- typed config
    - `Swoole.php` -- event-driven HTTP server
    - `Swoole/Coroutine.php` -- coroutine-based variant
    - `Swoole/Handler.php` -- shared HTTP request forwarding trait
  - `Server/TCP/` -- TCP proxy server
    - `Config.php` -- TCP config (ports, TLS, buffers, sockmap)
    - `TLS.php` -- TLS configuration for mTLS
    - `TLSContext.php` -- Swoole SSL context builder
    - `Swoole.php` -- event-driven TCP server
    - `Swoole/Coroutine.php` -- coroutine-based variant
  - `Server/SMTP/` -- SMTP proxy server
    - `Config.php`, `Connection.php`, `Swoole.php`

- **bin/** -- container entrypoint
  - `proxy` -- Docker entrypoint: `php bin/proxy tcp|http|smtp`
- **tests/** -- PHPUnit tests (unit + integration suites)
- **examples/** -- working examples (http-proxy.php, http.php, tcp.php, smtp.php)
- **benchmarks/** -- performance benchmarks, kernel tuning, sockmap PoC

## Key patterns

**Resolver interface:** Single method: `resolve(string $data): Result`. The `$data` parameter is protocol-specific: raw TCP packet bytes, HTTP hostname, SMTP domain. Use `Adapter::onResolve(\Closure)` for quick overrides without implementing the interface.

**Docker resolver mount:** Container entrypoint loads a resolver from `PROXY_RESOLVER` (default `/etc/utopia-proxy/resolver.php`). Mount a PHP file that returns a `Resolver` instance, like HAProxy config files. Falls back to `Fixed` resolver using `*_BACKEND_ENDPOINT` env vars.

**Server variants:** Each protocol has an event-driven server (`Swoole.php`) and a coroutine-based variant (`Swoole/Coroutine.php`). Event-driven uses Swoole PROCESS mode, coroutine uses BASE mode.

**Config classes:** Typed, readonly config per server type. Computed defaults (e.g., `reactorNum = cpu_num * 2`). Not arrays -- structured classes.

**SSRF protection:** Endpoint validation before caching. Configurable via `skipValidation` flag.

**TLS termination:** Protocol-specific TLS handling (PostgreSQL SSLRequest, MySQL SSL handshake). mTLS support with CA certificates via TLSContext builder.

**Connection pooling:** HTTP uses channel-based pools per host:port. TCP uses direct connection cache per file descriptor.

**BPF sockmap:** Optional kernel zero-copy relay for TCP. When enabled, the kernel forwards data between client and backend sockets without userspace involvement after the initial handshake.

## Testing patterns

- Tests extend `PHPUnit\Framework\TestCase`
- setUp checks `extension_loaded('swoole')` and skips if missing
- `MockResolver` for isolation (set endpoint, set exception)
- Integration tests simulate edge-like resolver patterns

## Conventions

- PSR-12 via Pint, PSR-4 autoloading
- Full type hints on all parameters and returns, readonly properties
- Imports: alphabetical, single per statement, grouped by const/class/function
- Fluent builder methods return `static`
- One class per file, filename matches class name

## Cross-repo context

Proxy is a direct dependency of the edge repo (`utopia-php/proxy` at `dev-main`). Changes to the Resolver interface or Adapter API can break edge. Edge's `app/tcp.php` uses `TCPAdapter`, `Config`, `TCPServer`, and `TLS` from this library. Edge implements its own resolver (`Edge\Proxy\Resolver`) that doesn't implement the proxy's `Resolver` interface — it uses `onResolve()` callback instead.
