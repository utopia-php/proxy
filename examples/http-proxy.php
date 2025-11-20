<?php

require __DIR__ . '/../vendor/autoload.php';

use Appwrite\ProtocolProxy\Http\HttpServer;

/**
 * HTTP Proxy Server Example
 *
 * Performance: 250k+ req/s
 *
 * Usage:
 *   php examples/http-proxy.php
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
    'max_connections' => 100000,
    'max_coroutine' => 100000,

    // Cold-start settings
    'cold_start_timeout' => 30000, // 30 seconds
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

$server = new HttpServer(
    host: $config['host'],
    port: $config['port'],
    workers: $config['workers'],
    config: $config
);

$server->start();
