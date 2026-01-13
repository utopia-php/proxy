<?php

require __DIR__ . '/../vendor/autoload.php';

use Utopia\Platform\Action;
use Utopia\Proxy\Adapter\HTTP\Swoole as HTTPAdapter;
use Utopia\Proxy\Server\HTTP\Swoole as HTTPServer;
use Utopia\Proxy\Service\HTTP as HTTPService;

/**
 * HTTP Proxy Server Example
 *
 * Performance: 250k+ req/s
 *
 * Usage:
 *   php examples/http.php
 *
 * Test:
 *   ab -n 100000 -c 1000 http://localhost:8080/
 */

$config = [
    // Server settings
    'host' => '0.0.0.0',
    'port' => 8080,
    'workers' => swoole_cpu_num() * 2,

    // Performance tuning
    'max_connections' => 100_000,
    'max_coroutine' => 100_000,
    'socket_buffer_size' => 8 * 1024 * 1024, // 8MB
    'buffer_output_size' => 8 * 1024 * 1024, // 8MB
    'log_level' => SWOOLE_LOG_ERROR,
    'backend_pool_size' => 2048,
    'backend_pool_timeout' => 0.001,
    'telemetry_headers' => false,

    // Cold-start settings
    'cold_start_timeout' => 30_000, // 30 seconds
    'health_check_interval' => 100, // 100ms

    // Backend services
    'compute_api_url' => getenv('COMPUTE_API_URL') ?: 'http://appwrite-api/v1/compute',
    'compute_api_key' => getenv('COMPUTE_API_KEY') ?: '',

    // Database connection
    'db_host' => getenv('DB_HOST') ?: 'localhost',
    'db_port' => (int)(getenv('DB_PORT') ?: 3306),
    'db_user' => getenv('DB_USER') ?: 'appwrite',
    'db_pass' => getenv('DB_PASS') ?: 'password',
    'db_name' => getenv('DB_NAME') ?: 'appwrite',

    // Redis cache
    'redis_host' => getenv('REDIS_HOST') ?: '127.0.0.1',
    'redis_port' => (int)(getenv('REDIS_PORT') ?: 6379),
];

echo "Starting HTTP Proxy Server...\n";
echo "Host: {$config['host']}:{$config['port']}\n";
echo "Workers: {$config['workers']}\n";
echo "Max connections: {$config['max_connections']}\n";
echo "\n";

$backendEndpoint = getenv('HTTP_BACKEND_ENDPOINT') ?: 'http-backend:5678';

$adapter = new HTTPAdapter();
$service = $adapter->getService() ?? new HTTPService();

$service->addAction('resolve', (new class extends Action {})
    ->callback(function (string $hostname) use ($backendEndpoint): string {
        return $backendEndpoint;
    }));

$adapter->setService($service);

$server = new HTTPServer(
    host: $config['host'],
    port: $config['port'],
    workers: $config['workers'],
    config: array_merge($config, [
        'adapter' => $adapter,
    ])
);

$server->start();
