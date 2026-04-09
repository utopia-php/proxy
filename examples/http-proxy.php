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
use Utopia\Proxy\Server\HTTP\Config as HTTPConfig;
use Utopia\Proxy\Server\HTTP\Swoole as HTTPServer;

$backends = [
    'api.example.com' => 'localhost:3000',
    'app.example.com' => 'localhost:3001',
    'admin.example.com' => 'localhost:3002',
];

$resolver = new class ($backends) implements Resolver {
    /** @var array<string, string> */
    private array $backends;

    public function __construct(array $backends)
    {
        $this->backends = $backends;
    }

    public function resolve(string $data): Result
    {
        if (! isset($this->backends[$data])) {
            throw new Exception(
                "No backend configured for hostname: {$data}",
                Exception::NOT_FOUND
            );
        }

        return new Result(endpoint: $this->backends[$data]);
    }
};

$config = new HTTPConfig(
    port: 8080,
    workers: swoole_cpu_num() * 2,
);

$server = new HTTPServer($resolver, $config);

echo "HTTP Proxy Server\n";
echo "Listening on: http://0.0.0.0:8080\n";
echo "\nConfigured backends:\n";
foreach ($backends as $hostname => $endpoint) {
    echo "  {$hostname} -> {$endpoint}\n";
}
echo "\n";

$server->start();
