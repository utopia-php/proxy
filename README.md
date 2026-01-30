# Appwrite Protocol Proxy

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
- 16GB pod → ~400k connections
- 32GB pod → ~670k connections
- 5 × 32GB pods → 3.3M connections

## Features

- Protocol-agnostic connection management
- Cold-start detection and triggering
- Automatic connection queueing during cold-starts
- Health checking and circuit breakers
- Built-in telemetry and metrics
- SSRF validation for security
- Support for HTTP, TCP (PostgreSQL/MySQL), and SMTP

## Installation

### Using Composer

```bash
composer require appwrite/protocol-proxy
```

### Using Docker

For a complete setup with all dependencies:

```bash
docker-compose up -d
```

See [DOCKER.md](DOCKER.md) for detailed Docker setup and configuration.

## Quick Start

The protocol-proxy uses the **Resolver Pattern** - a platform-agnostic interface for resolving resource identifiers to backend endpoints.

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
        // Map resource ID to backend endpoint
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

    public function onConnect(string $resourceId, array $metadata = []): void
    {
        // Called when a connection is established
    }

    public function onDisconnect(string $resourceId, array $metadata = []): void
    {
        // Called when a connection is closed
    }

    public function trackActivity(string $resourceId, array $metadata = []): void
    {
        // Track activity for cold-start detection
    }

    public function invalidateCache(string $resourceId): void
    {
        // Invalidate cached resolution data
    }

    public function getStats(): array
    {
        return ['resolver' => 'custom'];
    }
}
```

### HTTP Proxy

```php
<?php
require 'vendor/autoload.php';

use Utopia\Proxy\Resolver;
use Utopia\Proxy\Resolver\Result;
use Utopia\Proxy\Server\HTTP\Swoole as HTTPServer;

// Create resolver (inline example)
$resolver = new class implements Resolver {
    public function resolve(string $resourceId): Result
    {
        return new Result(endpoint: 'backend:8080');
    }
    public function onConnect(string $resourceId, array $metadata = []): void {}
    public function onDisconnect(string $resourceId, array $metadata = []): void {}
    public function trackActivity(string $resourceId, array $metadata = []): void {}
    public function invalidateCache(string $resourceId): void {}
    public function getStats(): array { return []; }
};

$server = new HTTPServer(
    $resolver,
    host: '0.0.0.0',
    port: 80,
    workers: swoole_cpu_num() * 2
);

$server->start();
```

### TCP Proxy (Database)

```php
<?php
require 'vendor/autoload.php';

use Utopia\Proxy\Resolver;
use Utopia\Proxy\Resolver\Result;
use Utopia\Proxy\Server\TCP\Swoole as TCPServer;

$resolver = new class implements Resolver {
    public function resolve(string $resourceId): Result
    {
        // resourceId is the database name from connection
        return new Result(endpoint: 'postgres:5432');
    }
    public function onConnect(string $resourceId, array $metadata = []): void {}
    public function onDisconnect(string $resourceId, array $metadata = []): void {}
    public function trackActivity(string $resourceId, array $metadata = []): void {}
    public function invalidateCache(string $resourceId): void {}
    public function getStats(): array { return []; }
};

$server = new TCPServer(
    $resolver,
    host: '0.0.0.0',
    ports: [5432, 3306], // PostgreSQL, MySQL
    workers: swoole_cpu_num() * 2
);

$server->start();
```

### SMTP Proxy

```php
<?php
require 'vendor/autoload.php';

use Utopia\Proxy\Resolver;
use Utopia\Proxy\Resolver\Result;
use Utopia\Proxy\Server\SMTP\Swoole as SMTPServer;

$resolver = new class implements Resolver {
    public function resolve(string $resourceId): Result
    {
        // resourceId is the domain from EHLO/HELO
        return new Result(endpoint: 'mailserver:25');
    }
    public function onConnect(string $resourceId, array $metadata = []): void {}
    public function onDisconnect(string $resourceId, array $metadata = []): void {}
    public function trackActivity(string $resourceId, array $metadata = []): void {}
    public function invalidateCache(string $resourceId): void {}
    public function getStats(): array { return []; }
};

$server = new SMTPServer(
    $resolver,
    host: '0.0.0.0',
    port: 25,
    workers: swoole_cpu_num() * 2
);

$server->start();
```

## Configuration

```php
<?php
$config = [
    // Performance tuning
    'max_connections' => 100_000,
    'max_coroutine' => 100_000,
    'socket_buffer_size' => 8 * 1024 * 1024, // 8MB
    'buffer_output_size' => 8 * 1024 * 1024, // 8MB
    'log_level' => SWOOLE_LOG_ERROR,

    // HTTP-specific
    'backend_pool_size' => 2048,
    'telemetry_headers' => true,
    'fast_path' => true,
    'open_http2_protocol' => false,

    // Cold-start settings
    'cold_start_timeout' => 30_000, // 30 seconds
    'health_check_interval' => 100, // 100ms

    // Security
    'skip_validation' => false, // Enable SSRF protection
];

$server = new HTTPServer($resolver, '0.0.0.0', 80, 16, $config);
```

## Testing

```bash
composer test
```

Integration tests (Docker Compose):

```bash
composer test:integration
```

Coverage (requires Xdebug or PCOV):

```bash
vendor/bin/phpunit --coverage-text
```

## Architecture

The protocol-proxy uses the **Resolver Pattern** for platform-agnostic backend resolution, combined with protocol-specific adapters for optimized handling.

```
┌─────────────────────────────────────────────────────────────────┐
│                        Protocol Proxy                            │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ┌──────────┐      ┌──────────┐      ┌──────────┐              │
│  │   HTTP   │      │   TCP    │      │   SMTP   │              │
│  │  Server  │      │  Server  │      │  Server  │              │
│  └────┬─────┘      └────┬─────┘      └────┬─────┘              │
│       │                 │                  │                     │
│       └─────────────────┴──────────────────┘                     │
│                         │                                        │
│           ┌─────────────┼─────────────┐                          │
│           │             │             │                          │
│      ┌────▼────┐   ┌────▼────┐  ┌────▼────┐                    │
│      │   HTTP  │   │   TCP   │  │   SMTP  │                    │
│      │ Adapter │   │ Adapter │  │ Adapter │                    │
│      └────┬────┘   └────┬────┘  └────┬────┘                    │
│           │             │             │                          │
│           └─────────────┴─────────────┘                          │
│                         │                                        │
│                ┌────────▼────────┐                               │
│                │    Resolver     │                               │
│                │   (Interface)   │                               │
│                └────────┬────────┘                               │
│                         │                                        │
│         ┌───────────────┼───────────────┐                        │
│         │               │               │                        │
│    ┌────▼────┐     ┌────▼────┐    ┌────▼────┐                  │
│    │ Routing │     │Lifecycle│    │  Stats  │                  │
│    │  Cache  │     │ Hooks   │    │ & Logs  │                  │
│    └─────────┘     └─────────┘    └─────────┘                  │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

### Resolver Interface

The `Resolver` interface is the core abstraction point:

```php
interface Resolver
{
    // Map resource ID to backend endpoint
    public function resolve(string $resourceId): Result;

    // Lifecycle hooks
    public function onConnect(string $resourceId, array $metadata = []): void;
    public function onDisconnect(string $resourceId, array $metadata = []): void;

    // Activity tracking for cold-start detection
    public function trackActivity(string $resourceId, array $metadata = []): void;

    // Cache management
    public function invalidateCache(string $resourceId): void;

    // Statistics
    public function getStats(): array;
}
```

### Resolution Result

The `Result` class contains the resolved backend endpoint:

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

### Protocol-Specific Adapters

- **HTTP** - Routes requests based on `Host` header
- **TCP** - Routes connections based on database name from PostgreSQL/MySQL protocol
- **SMTP** - Routes connections based on domain from EHLO/HELO command

## License

BSD-3-Clause
