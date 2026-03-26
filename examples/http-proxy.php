<?php

/**
 * HTTP Proxy Example
 *
 * Simple HTTP proxy with static backend mapping.
 *
 * Usage:
 *   php examples/http-proxy.php
 *
 * Test:
 *   curl -H "Host: api.example.com" http://localhost:8080/
 */

require __DIR__.'/../vendor/autoload.php';

use Utopia\Proxy\Resolver;
use Utopia\Proxy\Resolver\Exception;
use Utopia\Proxy\Resolver\Result;
use Utopia\Proxy\Server\HTTP\Swoole as HTTPServer;

// Simple static mapping of hostnames to backends
$backends = [
    'api.example.com' => 'localhost:3000',
    'app.example.com' => 'localhost:3001',
    'admin.example.com' => 'localhost:3002',
];

// Create resolver with static backend mapping
$resolver = new class ($backends) implements Resolver {
    /** @var array<string, string> */
    private array $backends;

    /** @var array<string, int> */
    private array $connectionCounts = [];

    public function __construct(array $backends)
    {
        $this->backends = $backends;
    }

    public function resolve(string $resourceId): Result
    {
        if (!isset($this->backends[$resourceId])) {
            throw new Exception(
                "No backend configured for hostname: {$resourceId}",
                Exception::NOT_FOUND
            );
        }

        return new Result(endpoint: $this->backends[$resourceId]);
    }

    public function onConnect(string $resourceId, array $metadata = []): void
    {
        $this->connectionCounts[$resourceId] = ($this->connectionCounts[$resourceId] ?? 0) + 1;
    }

    public function onDisconnect(string $resourceId, array $metadata = []): void
    {
        if (isset($this->connectionCounts[$resourceId])) {
            $this->connectionCounts[$resourceId]--;
        }
    }

    public function track(string $resourceId, array $metadata = []): void
    {
        // Track activity for cold-start detection
    }

    public function purge(string $resourceId): void
    {
        // No caching in this simple example
    }

    public function getStats(): array
    {
        return [
            'resolver' => 'static',
            'backends' => count($this->backends),
            'connections' => $this->connectionCounts,
        ];
    }
};

// Create server
$server = new HTTPServer(
    $resolver,
    host: '0.0.0.0',
    port: 8080,
    workers: swoole_cpu_num() * 2
);

echo "HTTP Proxy Server\n";
echo "=================\n";
echo "Listening on: http://0.0.0.0:8080\n";
echo "\nConfigured backends:\n";
foreach ($backends as $hostname => $endpoint) {
    echo "  {$hostname} -> {$endpoint}\n";
}
echo "\n";

$server->start();
