<?php

require __DIR__ . '/../vendor/autoload.php';

use Appwrite\ProtocolProxy\Tcp\TcpServer;

/**
 * TCP Proxy Server Example (PostgreSQL + MySQL)
 *
 * Performance: 100k+ conn/s, 10GB/s throughput
 *
 * Usage:
 *   php examples/tcp-proxy.php
 *
 * Test PostgreSQL:
 *   psql -h localhost -p 5432 -U postgres -d db-abc123
 *
 * Test MySQL:
 *   mysql -h localhost -P 3306 -u root -D db-abc123
 */

$config = [
    // Server settings
    'host' => '0.0.0.0',
    'workers' => swoole_cpu_num() * 2,

    // Performance tuning
    'max_connections' => 100000,
    'max_coroutine' => 100000,
    'socket_buffer_size' => 8 * 1024 * 1024, // 8MB for database traffic

    // Cold-start settings
    'cold_start_timeout' => 30000,
    'health_check_interval' => 100,

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

$ports = [5432, 3306]; // PostgreSQL, MySQL

echo "Starting TCP Proxy Server...\n";
echo "Host: {$config['host']}\n";
echo "Ports: " . implode(', ', $ports) . "\n";
echo "Workers: {$config['workers']}\n";
echo "Max connections: {$config['max_connections']}\n";
echo "\n";

$server = new TcpServer(
    host: $config['host'],
    ports: $ports,
    workers: $config['workers'],
    config: $config
);

$server->start();
