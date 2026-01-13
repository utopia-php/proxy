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

require __DIR__ . '/../vendor/autoload.php';

use Utopia\Platform\Action;
use Utopia\Proxy\Adapter\HTTP\Swoole as HTTPAdapter;
use Utopia\Proxy\Service\HTTP as HTTPService;
use Utopia\Proxy\Server\HTTP\Swoole as HTTPServer;

// Create HTTP adapter
$adapter = new HTTPAdapter();
$service = $adapter->getService() ?? new HTTPService();

// Register resolve action - REQUIRED
// Map hostnames to backend endpoints
$service->addAction('resolve', (new class extends Action {})
    ->callback(function (string $hostname): string {
    // Simple static mapping
    $backends = [
        'api.example.com' => 'localhost:3000',
        'app.example.com' => 'localhost:3001',
        'admin.example.com' => 'localhost:3002',
    ];

    if (!isset($backends[$hostname])) {
        throw new \Exception("No backend configured for hostname: {$hostname}");
    }

    return $backends[$hostname];
}));

// Optional: Add logging
$service->addAction('logRoute', (new class extends Action {})
    ->setType(Action::TYPE_SHUTDOWN)
    ->callback(function (string $hostname, string $endpoint, $result) {
    echo sprintf(
        "[%s] %s -> %s (cached: %s, latency: %sms)\n",
        date('H:i:s'),
        $hostname,
        $endpoint,
        $result->metadata['cached'] ? 'yes' : 'no',
        $result->metadata['latency_ms']
    );
}));

$adapter->setService($service);

// Create server
$server = new HTTPServer(
    host: '0.0.0.0',
    port: 8080,
    workers: swoole_cpu_num() * 2,
    config: ['adapter' => $adapter]
);

echo "HTTP Proxy Server\n";
echo "=================\n";
echo "Listening on: http://0.0.0.0:8080\n";
echo "\nConfigured backends:\n";
echo "  api.example.com -> localhost:3000\n";
echo "  app.example.com -> localhost:3001\n";
echo "  admin.example.com -> localhost:3002\n\n";

$server->start();
