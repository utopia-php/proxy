<?php

require __DIR__ . '/../vendor/autoload.php';

use Utopia\Platform\Action;
use Utopia\Proxy\Adapter\SMTP\Swoole as SMTPAdapter;
use Utopia\Proxy\Server\SMTP\Swoole as SMTPServer;
use Utopia\Proxy\Service\SMTP as SMTPService;

/**
 * SMTP Proxy Server Example
 *
 * Performance: 50k+ messages/sec
 *
 * Usage:
 *   php examples/smtp.php
 *
 * Test:
 *   telnet localhost 25
 *   EHLO test.com
 *   MAIL FROM:<sender@test.com>
 *   RCPT TO:<recipient@test.com>
 *   DATA
 *   Subject: Test
 *
 *   Hello World
 *   .
 *   QUIT
 */

$config = [
    // Server settings
    'host' => '0.0.0.0',
    'port' => 25,
    'workers' => swoole_cpu_num() * 2,

    // Performance tuning
    'max_connections' => 100000,
    'max_coroutine' => 100000,
    'socket_buffer_size' => 8 * 1024 * 1024, // 8MB
    'buffer_output_size' => 8 * 1024 * 1024, // 8MB
    'log_level' => SWOOLE_LOG_ERROR,

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

echo "Starting SMTP Proxy Server...\n";
echo "Host: {$config['host']}:{$config['port']}\n";
echo "Workers: {$config['workers']}\n";
echo "Max connections: {$config['max_connections']}\n";
echo "\n";

$backendEndpoint = getenv('SMTP_BACKEND_ENDPOINT') ?: 'smtp-backend:1025';

$adapter = new SMTPAdapter();
$service = $adapter->getService() ?? new SMTPService();

$service->addAction('resolve', (new class extends Action {})
    ->callback(function (string $domain) use ($backendEndpoint): string {
        return $backendEndpoint;
    }));

$adapter->setService($service);

$server = new SMTPServer(
    host: $config['host'],
    port: $config['port'],
    workers: $config['workers'],
    config: array_merge($config, [
        'adapter' => $adapter,
    ])
);

$server->start();
