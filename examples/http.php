<?php

require __DIR__.'/../vendor/autoload.php';

use Utopia\Proxy\Resolver;
use Utopia\Proxy\Resolver\Result;
use Utopia\Proxy\Server\HTTP\Swoole as HTTPServer;
use Utopia\Proxy\Server\HTTP\Swoole\Coroutine as HTTPCoroutineServer;

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
$workers = (int) (getenv('HTTP_WORKERS') ?: (swoole_cpu_num() * 2));
$serverMode = strtolower(getenv('HTTP_SERVER_MODE') ?: 'process');
$serverModeValue = $serverMode === 'base' ? SWOOLE_BASE : SWOOLE_PROCESS;
$fastPath = getenv('HTTP_FAST_PATH');
if ($fastPath === false) {
    $fastPath = true;
} else {
    $fastPath = filter_var($fastPath, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
}
$fastAssumeOk = getenv('HTTP_FAST_ASSUME_OK');
if ($fastAssumeOk === false) {
    $fastAssumeOk = false;
} else {
    $fastAssumeOk = filter_var($fastAssumeOk, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
}
$fixedBackend = getenv('HTTP_FIXED_BACKEND');
if ($fixedBackend === false || $fixedBackend === '') {
    $fixedBackend = null;
}
$directResponse = getenv('HTTP_DIRECT_RESPONSE');
if ($directResponse === false || $directResponse === '') {
    $directResponse = null;
}
$directResponseStatus = (int) (getenv('HTTP_DIRECT_RESPONSE_STATUS') ?: 200);
$rawBackend = getenv('HTTP_RAW_BACKEND');
if ($rawBackend === false) {
    $rawBackend = false;
} else {
    $rawBackend = filter_var($rawBackend, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
}
$rawBackendAssumeOk = getenv('HTTP_RAW_BACKEND_ASSUME_OK');
if ($rawBackendAssumeOk === false) {
    $rawBackendAssumeOk = false;
} else {
    $rawBackendAssumeOk = filter_var($rawBackendAssumeOk, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
}
$serverImpl = strtolower(getenv('HTTP_SERVER_IMPL') ?: 'swoole');
if (! in_array($serverImpl, ['swoole', 'coroutine', 'coro'], true)) {
    $serverImpl = 'swoole';
}
if ($serverImpl === 'coro') {
    $serverImpl = 'coroutine';
}
$backendPoolSize = getenv('HTTP_BACKEND_POOL_SIZE');
if ($backendPoolSize === false || $backendPoolSize === '') {
    $backendPoolSize = 2048;
} else {
    $backendPoolSize = (int) $backendPoolSize;
}
$httpKeepaliveTimeout = getenv('HTTP_KEEPALIVE_TIMEOUT');
if ($httpKeepaliveTimeout === false || $httpKeepaliveTimeout === '') {
    $httpKeepaliveTimeout = 60;
} else {
    $httpKeepaliveTimeout = (int) $httpKeepaliveTimeout;
}
$openHttp2 = getenv('HTTP_OPEN_HTTP2');
if ($openHttp2 === false) {
    $openHttp2 = false;
} else {
    $openHttp2 = filter_var($openHttp2, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
}

$backendEndpoint = getenv('HTTP_BACKEND_ENDPOINT') ?: 'http-backend:5678';
$skipValidation = filter_var(getenv('HTTP_SKIP_VALIDATION') ?: 'false', FILTER_VALIDATE_BOOLEAN);

// Create a simple resolver that returns the configured backend endpoint
$resolver = new class ($backendEndpoint) implements Resolver {
    public function __construct(private string $endpoint)
    {
    }

    public function resolve(string $resourceId): Result
    {
        return new Result(endpoint: $this->endpoint);
    }

    public function onConnect(string $resourceId, array $metadata = []): void
    {
    }

    public function onDisconnect(string $resourceId, array $metadata = []): void
    {
    }

    public function track(string $resourceId, array $metadata = []): void
    {
    }

    public function purge(string $resourceId): void
    {
    }

    public function getStats(): array
    {
        return ['resolver' => 'static', 'endpoint' => $this->endpoint];
    }
};

$config = [
    // Server settings
    'host' => '0.0.0.0',
    'port' => 8080,
    'workers' => $workers,
    'server_mode' => $serverModeValue,
    'reactor_num' => (int) (getenv('HTTP_REACTOR_NUM') ?: (swoole_cpu_num() * 2)),
    'dispatch_mode' => (int) (getenv('HTTP_DISPATCH_MODE') ?: 2),

    // Performance tuning
    'max_connections' => 100_000,
    'max_coroutine' => 100_000,
    'socket_buffer_size' => 8 * 1024 * 1024, // 8MB
    'buffer_output_size' => 8 * 1024 * 1024, // 8MB
    'log_level' => SWOOLE_LOG_ERROR,
    'backend_pool_size' => $backendPoolSize,
    'backend_pool_timeout' => 0.001,
    'telemetry_headers' => false,
    'fast_path' => $fastPath,
    'fast_path_assume_ok' => $fastAssumeOk,
    'fixed_backend' => $fixedBackend,
    'direct_response' => $directResponse,
    'direct_response_status' => $directResponseStatus,
    'raw_backend' => $rawBackend,
    'raw_backend_assume_ok' => $rawBackendAssumeOk,
    'http_keepalive_timeout' => $httpKeepaliveTimeout,
    'open_http2_protocol' => $openHttp2,

    // Cold-start settings
    'cold_start_timeout' => 30_000, // 30 seconds
    'health_check_interval' => 100, // 100ms

    // Backend services
    'compute_api_url' => getenv('COMPUTE_API_URL') ?: 'http://appwrite-api/v1/compute',
    'compute_api_key' => getenv('COMPUTE_API_KEY') ?: '',

    // Database connection
    'db_host' => getenv('DB_HOST') ?: 'localhost',
    'db_port' => (int) (getenv('DB_PORT') ?: 3306),
    'db_user' => getenv('DB_USER') ?: 'appwrite',
    'db_pass' => getenv('DB_PASS') ?: 'password',
    'db_name' => getenv('DB_NAME') ?: 'appwrite',

    // Redis cache
    'redis_host' => getenv('REDIS_HOST') ?: '127.0.0.1',
    'redis_port' => (int) (getenv('REDIS_PORT') ?: 6379),

    // Skip SSRF validation for trusted backends (e.g., Docker internal networks)
    'skip_validation' => $skipValidation,
];

echo "Starting HTTP Proxy Server...\n";
echo "Host: {$config['host']}:{$config['port']}\n";
echo "Workers: {$config['workers']}\n";
echo "Max connections: {$config['max_connections']}\n";
echo "Server impl: {$serverImpl}\n";
echo "\n";

$serverClass = $serverImpl === 'swoole' ? HTTPServer::class : HTTPCoroutineServer::class;
$server = new $serverClass(
    $resolver,
    $config['host'],
    $config['port'],
    $config['workers'],
    $config
);

$server->start();
