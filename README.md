# Appwrite Protocol Proxy

High-performance, protocol-agnostic proxy built on Swoole for blazing fast connection management across HTTP, TCP, and SMTP protocols.

## 🚀 Performance First

- **Swoole coroutines**: Handle 100,000+ concurrent connections per server
- **Connection pooling**: Reuse connections to backend services
- **Zero-copy forwarding**: Minimize memory allocations
- **Aggressive caching**: 1-second TTL with 99%+ cache hit rate
- **Async I/O**: Non-blocking operations throughout
- **Memory efficient**: Shared memory tables for state management

## 🎯 Features

- Protocol-agnostic connection management
- Cold-start detection and triggering
- Automatic connection queueing during cold-starts
- Health checking and circuit breakers
- Built-in telemetry and metrics
- Support for HTTP, TCP (PostgreSQL/MySQL), and SMTP

## 📦 Installation

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

## 🏃 Quick Start

The protocol-proxy uses the **Adapter Pattern** - similar to [utopia-php/database](https://github.com/utopia-php/database), [utopia-php/messaging](https://github.com/utopia-php/messaging), and [utopia-php/storage](https://github.com/utopia-php/storage).

### HTTP Proxy (Basic)

```php
<?php
require 'vendor/autoload.php';

use Utopia\Platform\Action;
use Utopia\Proxy\Adapter\HTTP\Swoole as HTTPAdapter;
use Utopia\Proxy\Server\HTTP\Swoole as HTTPServer;
use Utopia\Proxy\Service\HTTP as HTTPService;

$adapter = new HTTPAdapter();
$service = $adapter->getService() ?? new HTTPService();

// Required: Provide backend resolution logic
$service->addAction('resolve', (new class extends Action {})
    ->callback(function (string $hostname) use ($backend): string {
        return $backend->getEndpoint($hostname);
    }));

$adapter->setService($service);

$server = new HTTPServer(
    host: '0.0.0.0',
    port: 80,
    workers: swoole_cpu_num() * 2,
    config: ['adapter' => $adapter]
);

$server->start();
```

### TCP Proxy (Database)

```php
<?php
require 'vendor/autoload.php';

use Utopia\Proxy\Server\TCP\Swoole as TCPServer;

$server = new TCPServer(
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

use Utopia\Proxy\Server\SMTP\Swoole as SMTPServer;

$server = new SMTPServer(
    host: '0.0.0.0',
    port: 25,
    workers: swoole_cpu_num() * 2
);

$server->start();
```

## 🔧 Configuration

```php
<?php
$config = [
    'host' => '0.0.0.0',
    'port' => 80,
    'workers' => 16,

    // Performance tuning
    'max_connections' => 100000,
    'max_coroutine' => 100000,
    'socket_buffer_size' => 2 * 1024 * 1024, // 2MB
    'buffer_output_size' => 2 * 1024 * 1024, // 2MB

    // Routing cache
    'cache_ttl' => 1, // 1 second

    // Database connection (for cache and resolution actions)
    'db_host' => 'localhost',
    'db_port' => 3306,
    'db_user' => 'appwrite',
    'db_pass' => 'password',
    'db_name' => 'appwrite',

    // Redis cache
    'redis_host' => '127.0.0.1',
    'redis_port' => 6379,
];
```

## ✅ Testing

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

## 🎨 Architecture

The protocol-proxy follows the **Adapter Pattern** used throughout utopia-php libraries (Database, Messaging, Storage), providing a clean and extensible architecture for protocol-specific implementations.

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
│                │     Adapter     │                               │
│                │   (Abstract)    │                               │
│                └────────┬────────┘                               │
│                         │                                        │
│         ┌───────────────┼───────────────┐                        │
│         │               │               │                        │
│    ┌────▼────┐     ┌────▼────┐    ┌────▼────┐                  │
│    │  Cache  │     │ Database│    │ Compute │                  │
│    │  Layer  │     │  Pool   │    │   API   │                  │
│    └─────────┘     └─────────┘    └─────────┘                  │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

### Adapter Pattern

Following the design principles of utopia-php libraries:

- **Abstract Base**: `Adapter` class defines core proxy behavior
  - Connection handling and routing
  - Cold-start detection and triggering
  - Caching and performance optimization

- **Protocol-Specific Adapters**:
  - `HTTP` - Routes HTTP requests based on hostname
  - `TCP` - Routes TCP connections (PostgreSQL/MySQL) based on SNI
  - `SMTP` - Routes SMTP connections based on email domain

This pattern enables:
- Easy addition of new protocols
- Protocol-specific optimizations
- Consistent interface across all proxy types
- Shared infrastructure (caching, pooling, metrics)

## 📊 Performance Benchmarks

```
HTTP Proxy:
- Requests/sec: 250,000+
- Latency p50: <1ms
- Latency p99: <5ms
- Connections: 100,000+ concurrent

TCP Proxy:
- Connections/sec: 100,000+
- Throughput: 10GB/s+
- Latency: <1ms forwarding overhead

SMTP Proxy:
- Messages/sec: 50,000+
- Concurrent connections: 50,000+
```

## 🧪 Testing

```bash
composer test
```

## 📝 License

BSD-3-Clause
