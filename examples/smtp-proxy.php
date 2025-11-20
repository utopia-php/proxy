<?php

require __DIR__ . '/../vendor/autoload.php';

use Appwrite\ProtocolProxy\Smtp\SmtpServer;

/**
 * SMTP Proxy Server Example
 *
 * Performance: 50k+ messages/sec
 *
 * Usage:
 *   php examples/smtp-proxy.php
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
    'max_connections' => 50000,
    'max_coroutine' => 50000,

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

$server = new SmtpServer(
    host: $config['host'],
    port: $config['port'],
    workers: $config['workers'],
    config: $config
);

$server->start();
