<?php

require __DIR__ . '/../vendor/autoload.php';

use Utopia\Platform\Action;
use Utopia\Proxy\Adapter\TCP\Swoole as TCPAdapter;
use Utopia\Proxy\Server\TCP\Swoole as TCPServer;
use Utopia\Proxy\Service\TCP as TCPService;

/**
 * TCP Proxy Server Example (PostgreSQL + MySQL)
 *
 * Performance: 100k+ conn/s, 10GB/s throughput
 *
 * Usage:
 *   php examples/tcp.php
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
    'max_connections' => 200_000,
    'max_coroutine' => 200_000,
    'socket_buffer_size' => 16 * 1024 * 1024, // 16MB for database traffic
    'buffer_output_size' => 16 * 1024 * 1024, // 16MB
    'log_level' => SWOOLE_LOG_ERROR,
    'reactor_num' => swoole_cpu_num() * 2,
    'dispatch_mode' => 2,
    'enable_reuse_port' => true,
    'backlog' => 65535,
    'package_max_length' => 32 * 1024 * 1024, // 32MB max query/result
    'tcp_keepidle' => 30,
    'tcp_keepinterval' => 10,
    'tcp_keepcount' => 3,

    // Cold-start settings
    'cold_start_timeout' => 30_000,
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

$postgresPort = (int)(getenv('TCP_POSTGRES_PORT') ?: 5432);
$mysqlPort = (int)(getenv('TCP_MYSQL_PORT') ?: 3306);
$ports = array_values(array_filter([$postgresPort, $mysqlPort], static fn (int $port): bool => $port > 0)); // PostgreSQL, MySQL
if ($ports === []) {
    $ports = [5432, 3306];
}

echo "Starting TCP Proxy Server...\n";
echo "Host: {$config['host']}\n";
echo "Ports: " . implode(', ', $ports) . "\n";
echo "Workers: {$config['workers']}\n";
echo "Max connections: {$config['max_connections']}\n";
echo "\n";

$backendEndpoint = getenv('TCP_BACKEND_ENDPOINT') ?: 'tcp-backend:15432';

$adapterFactory = function (int $port) use ($backendEndpoint): TCPAdapter {
    $adapter = new TCPAdapter(port: $port);
    $service = $adapter->getService() ?? new TCPService();

    $service->addAction('resolve', (new class extends Action {})
        ->callback(function (string $databaseId) use ($backendEndpoint): string {
            return $backendEndpoint;
        }));

    $adapter->setService($service);

    return $adapter;
};

$server = new TCPServer(
    host: $config['host'],
    ports: $ports,
    workers: $config['workers'],
    config: array_merge($config, [
        'adapter_factory' => $adapterFactory,
    ])
);

$server->start();
