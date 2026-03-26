# Utopia Proxy

High-performance, protocol-agnostic proxy built on Swoole for blazing fast connection management across HTTP, TCP, and SMTP protocols.

## Performance First

- **670k+ concurrent connections** per server (validated on 8-core/32GB)
- **~33KB per connection** memory footprint
- **18k+ connections/sec** connection establishment rate
- **Linear scaling** across multiple pods (5 pods = 3M+ connections)
- **Minimal-copy forwarding**: Large buffers, no payload parsing
- **Connection pooling**: Reuse connections to backend services
- **Async I/O**: Non-blocking operations throughout

### Benchmark Results (8-core, 32GB RAM)

| Metric | Result |
|--------|--------|
| Peak concurrent connections | 672,348 |
| Memory at peak | 23 GB |
| Memory per connection | ~33 KB |
| Connection rate (sustained) | 18,067/sec |
| CPU utilization at peak | ~60% |

Memory is the primary constraint. Scale estimate:
- 16GB pod -> ~400k connections
- 32GB pod -> ~670k connections
- 5 x 32GB pods -> 3.3M connections

## Features

- Protocol-agnostic connection management
- Cold-start detection and triggering
- Automatic connection queueing during cold-starts
- Health checking and circuit breakers
- Built-in telemetry and metrics
- SSRF validation for security
- Support for HTTP, TCP (PostgreSQL, MySQL, MongoDB), and SMTP
- TLS termination with mTLS support
- Coroutine-based server variants for each protocol

## Requirements

- PHP >= 8.4
- ext-swoole >= 6.0
- ext-redis
## Installation

### Using Composer

```bash
composer require utopia-php/proxy
```

### Using Docker

For a complete setup with all dependencies:

```bash
docker compose up -d
```

This starts five services: MariaDB, Redis, HTTP proxy (port 8080), TCP proxy (ports 5432/3306), and SMTP proxy (port 8025).

## Quick Start

The proxy uses the **Resolver Pattern** - a platform-agnostic interface for resolving resource identifiers to backend endpoints.

### Implementing a Resolver

All servers require a `Resolver` implementation that maps resource IDs (hostnames, database IDs, domains) to backend endpoints:

```php
<?php
use Utopia\Proxy\Resolver;
use Utopia\Proxy\Resolver\Result;
use Utopia\Proxy\Resolver\Exception;

class MyResolver implements Resolver
{
    public function resolve(string $resourceId): Result
    {
        $backends = [
            'api.example.com' => 'localhost:3000',
            'app.example.com' => 'localhost:3001',
        ];

        if (!isset($backends[$resourceId])) {
            throw new Exception(
                "No backend for: {$resourceId}",
                Exception::NOT_FOUND
            );
        }

        return new Result(endpoint: $backends[$resourceId]);
    }

    public function onConnect(string $resourceId, array $metadata = []): void {}
    public function onDisconnect(string $resourceId, array $metadata = []): void {}
    public function track(string $resourceId, array $metadata = []): void {}
    public function purge(string $resourceId): void {}
    public function getStats(): array { return []; }
}
```

### HTTP Proxy

```php
<?php
require 'vendor/autoload.php';

use Utopia\Proxy\Server\HTTP\Swoole as HTTPServer;

$resolver = new MyResolver();

$server = new HTTPServer(
    $resolver,
    host: '0.0.0.0',
    port: 80,
    workers: swoole_cpu_num() * 2
);

$server->start();
```

### TCP Proxy (Database)

The TCP proxy uses a `Config` object for configuration and listens on multiple ports simultaneously (PostgreSQL on 5432, MySQL on 3306, MongoDB on 27017):

```php
<?php
require 'vendor/autoload.php';

use Utopia\Proxy\Server\TCP\Config;
use Utopia\Proxy\Server\TCP\Swoole as TCPServer;

$resolver = new MyResolver();

$config = new Config(
    host: '0.0.0.0',
    ports: [5432, 3306, 27017],
    workers: swoole_cpu_num() * 2,
);

$server = new TCPServer($resolver, $config);
$server->start();
```

The database protocol is determined by port: 5432 = PostgreSQL, 3306 = MySQL, 27017 = MongoDB. The database ID is parsed from the protocol-specific startup message (PostgreSQL startup message, MySQL COM_INIT_DB, MongoDB OP_MSG `$db` field).

### SMTP Proxy

```php
<?php
require 'vendor/autoload.php';

use Utopia\Proxy\Server\SMTP\Swoole as SMTPServer;

$resolver = new MyResolver();

$server = new SMTPServer(
    $resolver,
    host: '0.0.0.0',
    port: 25,
    workers: swoole_cpu_num() * 2
);

$server->start();
```

## TLS Termination

The TCP proxy supports TLS termination for database connections, including mutual TLS (mTLS).

```php
<?php
use Utopia\Proxy\Server\TCP\Config;
use Utopia\Proxy\Server\TCP\TLS;
use Utopia\Proxy\Server\TCP\Swoole as TCPServer;

$tls = new TLS(
    certificate: '/certs/server.crt',
    key: '/certs/server.key',
    ca: '/certs/ca.crt',           // Optional: for mTLS
    requireClientCert: true,            // Optional: require client certs
);

$config = new Config(
    ports: [5432, 3306],
    tls: $tls,
);

$server = new TCPServer($resolver, $config);
$server->start();
```

Supported protocols:
- **PostgreSQL**: STARTTLS via SSLRequest/SSLResponse handshake
- **MySQL**: SSL capability flag in server greeting

TLS can also be configured via environment variables:

| Variable | Default | Description |
|----------|---------|-------------|
| `PROXY_TLS_ENABLED` | `false` | Enable TLS termination |
| `PROXY_TLS_CERT` | | Path to server certificate |
| `PROXY_TLS_KEY` | | Path to private key |
| `PROXY_TLS_CA` | | Path to CA certificate (for mTLS) |
| `PROXY_TLS_REQUIRE_CLIENT_CERT` | `false` | Require client certificates |

## Configuration

### HTTP Server

```php
<?php
$server = new HTTPServer($resolver, '0.0.0.0', 80, 16, [
    // Performance
    'max_connections' => 100_000,
    'max_coroutine' => 100_000,
    'socket_buffer_size' => 2 * 1024 * 1024,
    'buffer_output_size' => 2 * 1024 * 1024,
    'backend_pool_size' => 1024,
    'backend_timeout' => 30,
    'backend_keep_alive' => true,

    // Behavior
    'fast_path' => false,           // Minimal header processing
    'fast_path_assume_ok' => false, // Skip status code forwarding
    'fixed_backend' => null,        // Route all requests to static endpoint
    'direct_response' => null,      // Return static response without forwarding
    'raw_backend' => false,         // Use raw TCP for GET/HEAD (benchmark only)
    'telemetry_headers' => true,    // Add X-Proxy-* response headers
    'skip_validation' => false,     // Disable SSRF protection

    // Protocol
    'open_http2_protocol' => false,
    'http_keepalive_timeout' => 60,
]);
```

### TCP Server

```php
<?php
$config = new Config(
    host: '0.0.0.0',
    ports: [5432, 3306, 27017],
    workers: 16,
    maxConnections: 200_000,
    socketBufferSize: 16 * 1024 * 1024,
    bufferOutputSize: 16 * 1024 * 1024,
    receiveBufferSize: 131_072,
    connectTimeout: 5.0,
    skipValidation: false,
    tls: null,

    // TCP keep-alive
    tcpKeepidle: 30,
    tcpKeepinterval: 10,
    tcpKeepcount: 3,
);
```

### Environment Variables

The proxy entry points (`examples/*.php`) support configuration via environment variables:

**HTTP Proxy:**

| Variable | Default | Description |
|----------|---------|-------------|
| `HTTP_WORKERS` | `cpu_num * 2` | Worker process count |
| `HTTP_SERVER_MODE` | `process` | `process` or `base` |
| `HTTP_SERVER_IMPL` | `swoole` | `swoole` or `coroutine` |
| `HTTP_FAST_PATH` | `true` | Minimal header processing |
| `HTTP_FAST_ASSUME_OK` | `false` | Skip status code forwarding |
| `HTTP_FIXED_BACKEND` | | Route all to static endpoint |
| `HTTP_DIRECT_RESPONSE` | | Return static response |
| `HTTP_RAW_BACKEND` | `false` | Raw TCP for GET/HEAD |
| `HTTP_BACKEND_POOL_SIZE` | `2048` | Connection pool size |
| `HTTP_KEEPALIVE_TIMEOUT` | `60` | Keep-alive timeout (seconds) |
| `HTTP_OPEN_HTTP2` | `false` | Enable HTTP/2 |
| `HTTP_SKIP_VALIDATION` | `false` | Disable SSRF protection |
| `HTTP_BACKEND_ENDPOINT` | `http-backend:5678` | Default backend endpoint |

**TCP Proxy:**

| Variable | Default | Description |
|----------|---------|-------------|
| `TCP_WORKERS` | `cpu_num * 2` | Worker process count |
| `TCP_SERVER_IMPL` | `swoole` | `swoole` or `coroutine` |
| `TCP_POSTGRES_PORT` | `5432` | PostgreSQL listen port |
| `TCP_MYSQL_PORT` | `3306` | MySQL listen port |
| `TCP_SKIP_VALIDATION` | `false` | Disable SSRF protection |
| `TCP_BACKEND_ENDPOINT` | `tcp-backend:15432` | Default backend endpoint |

**SMTP Proxy:**

| Variable | Default | Description |
|----------|---------|-------------|
| `SMTP_BACKEND_ENDPOINT` | `smtp-backend:1025` | Default backend endpoint |
| `SMTP_SKIP_VALIDATION` | `false` | Disable SSRF protection |

## Testing

```bash
composer test
```

Integration tests (Docker Compose):

```bash
composer test:integration
```

All tests:

```bash
composer test:all
```

Static analysis:

```bash
composer check
```

## Architecture

```text
┌─────────────────────────────────────────────────────────────────┐
│                         Utopia Proxy                            │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌──────────┐      ┌──────────┐      ┌──────────┐              │
│  │   HTTP   │      │   TCP    │      │   SMTP   │              │
│  │  Server  │      │  Server  │      │  Server  │              │
│  └────┬─────┘      └────┬─────┘      └────┬─────┘              │
│       │                 │                  │                     │
│       │            ┌────┴─────┐            │                     │
│       │            │ TCP      │            │                     │
│       │            │ Adapter  │            │                     │
│       │            └────┬─────┘            │                     │
│       │                 │                  │                     │
│       └─────────────────┴──────────────────┘                     │
│                         │                                        │
│                ┌────────▼────────┐                               │
│                │    Adapter      │                               │
│                │   (Base Class)  │                               │
│                └────────┬────────┘                               │
│                         │                                        │
│         ┌───────────────┘                                       │
│         │                                                        │
│    ┌────▼────┐                                                   │
│    │Resolver │                                                   │
│    │(resolve)│                                                   │
│    └────┬────┘                                                   │
│         │                                                        │
│    ┌────▼────┐                                                   │
│    │ Routing │                                                   │
│    │  Cache  │                                                   │
│    └─────────┘                                                   │
│                                                                  │
└──────────────────────────────────────────────────────────────────┘
```

### Protocol Enum

The `Protocol` enum defines all supported protocol types:

```php
enum Protocol: string
{
    case HTTP = 'http';
    case SMTP = 'smtp';
    case TCP = 'tcp';
    case PostgreSQL = 'postgresql';
    case MySQL = 'mysql';
    case MongoDB = 'mongodb';
}
```

### Resolver Interface

The `Resolver` interface is the core abstraction point:

```php
interface Resolver
{
    public function resolve(string $resourceId): Result;
    public function onConnect(string $resourceId, array $metadata = []): void;
    public function onDisconnect(string $resourceId, array $metadata = []): void;
    public function track(string $resourceId, array $metadata = []): void;
    public function purge(string $resourceId): void;
    public function getStats(): array;
}
```

### Resolution Result

```php
new Result(
    endpoint: 'host:port',      // Required: backend endpoint
    metadata: ['key' => 'val'], // Optional: additional data
    timeout: 30                 // Optional: connection timeout override
);
```

### Resolution Exceptions

Use `Resolver\Exception` with appropriate error codes:

```php
throw new Exception('Not found', Exception::NOT_FOUND);    // 404
throw new Exception('Unavailable', Exception::UNAVAILABLE); // 503
throw new Exception('Timeout', Exception::TIMEOUT);         // 504
throw new Exception('Forbidden', Exception::FORBIDDEN);     // 403
throw new Exception('Error', Exception::INTERNAL);          // 500
```

### Protocol-Specific Routing

- **HTTP** - Routes requests based on `Host` header
- **TCP/PostgreSQL** - Parses database name from startup message
- **TCP/MySQL** - Extracts database name from COM_INIT_DB packet
- **TCP/MongoDB** - Extracts database name from OP_MSG `$db` field
- **SMTP** - Routes connections based on domain from EHLO/HELO command

## License

BSD-3-Clause
