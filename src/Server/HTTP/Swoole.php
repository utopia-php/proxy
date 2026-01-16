<?php

namespace Utopia\Proxy\Server\HTTP;

use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Client as CoroutineClient;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Utopia\Proxy\Adapter\HTTP\Swoole as HTTPAdapter;
use Utopia\Proxy\Resolver;

/**
 * High-performance HTTP proxy server (Swoole Implementation)
 *
 * Example:
 * ```php
 * $resolver = new MyFunctionResolver();
 * $server = new Swoole($resolver, host: '0.0.0.0', port: 80);
 * $server->start();
 * ```
 */
class Swoole
{
    protected Server $server;

    protected HTTPAdapter $adapter;

    /** @var array<string, mixed> */
    protected array $config;

    /** @var array<string, Channel> */
    protected array $backendPools = [];

    /** @var array<string, Channel> */
    protected array $rawBackendPools = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected Resolver $resolver,
        string $host = '0.0.0.0',
        int $port = 80,
        int $workers = 16,
        array $config = []
    ) {
        $this->config = array_merge([
            'host' => $host,
            'port' => $port,
            'workers' => $workers,
            'max_connections' => 100_000,
            'max_coroutine' => 100_000,
            'socket_buffer_size' => 2 * 1024 * 1024, // 2MB
            'buffer_output_size' => 2 * 1024 * 1024, // 2MB
            'enable_coroutine' => true,
            'max_wait_time' => 60,
            'server_mode' => SWOOLE_PROCESS,
            'reactor_num' => swoole_cpu_num() * 2,
            'dispatch_mode' => 2,
            'enable_reuse_port' => true,
            'backlog' => 65535,
            'http_parse_post' => false,
            'http_parse_cookie' => false,
            'http_parse_files' => false,
            'http_compression' => false,
            'log_level' => SWOOLE_LOG_ERROR,
            'backend_timeout' => 30,
            'backend_keep_alive' => true,
            'backend_pool_size' => 1024,
            'backend_pool_timeout' => 0.001,
            'telemetry_headers' => true,
            'fast_path' => false,
            'fast_path_assume_ok' => false,
            'fixed_backend' => null,
            'direct_response' => null,
            'direct_response_status' => 200,
            'http_keepalive_timeout' => 60,
            'open_http_protocol' => true,
            'open_http2_protocol' => false,
            'max_request' => 0,
            'raw_backend' => false,
            'raw_backend_assume_ok' => false,
            'request_handler' => null, // Custom request handler callback
            'worker_start' => null, // Worker start callback
            'worker_stop' => null, // Worker stop callback
        ], $config);

        $this->server = new Server($host, $port, $this->config['server_mode']);
        $this->configure();
    }

    protected function configure(): void
    {
        $this->server->set([
            'worker_num' => $this->config['workers'],
            'reactor_num' => $this->config['reactor_num'],
            'max_connection' => $this->config['max_connections'],
            'max_coroutine' => $this->config['max_coroutine'],
            'socket_buffer_size' => $this->config['socket_buffer_size'],
            'buffer_output_size' => $this->config['buffer_output_size'],
            'enable_coroutine' => $this->config['enable_coroutine'],
            'max_wait_time' => $this->config['max_wait_time'],
            'open_http_protocol' => $this->config['open_http_protocol'],
            'open_http2_protocol' => $this->config['open_http2_protocol'],
            'http_keepalive_timeout' => $this->config['http_keepalive_timeout'],
            'max_request' => $this->config['max_request'],
            'dispatch_mode' => $this->config['dispatch_mode'],
            'enable_reuse_port' => $this->config['enable_reuse_port'],
            'backlog' => $this->config['backlog'],
            'http_parse_post' => $this->config['http_parse_post'],
            'http_parse_cookie' => $this->config['http_parse_cookie'],
            'http_parse_files' => $this->config['http_parse_files'],
            'http_compression' => $this->config['http_compression'],
            'log_level' => $this->config['log_level'],

            // Performance tuning
            'open_tcp_nodelay' => true,
            'tcp_fastopen' => true,
            'open_cpu_affinity' => true,
            'tcp_defer_accept' => 5,

            // Enable stats
            'task_enable_coroutine' => true,
        ]);

        $this->server->on('start', $this->onStart(...));
        $this->server->on('workerStart', $this->onWorkerStart(...));
        $this->server->on('request', $this->onRequest(...));
    }

    public function onStart(Server $server): void
    {
        /** @var string $host */
        $host = $this->config['host'];
        /** @var int $port */
        $port = $this->config['port'];
        /** @var int $workers */
        $workers = $this->config['workers'];
        /** @var int $maxConnections */
        $maxConnections = $this->config['max_connections'];
        echo "HTTP Proxy Server started at http://{$host}:{$port}\n";
        echo "Workers: {$workers}\n";
        echo "Max connections: {$maxConnections}\n";
    }

    public function onWorkerStart(Server $server, int $workerId): void
    {
        $this->adapter = new HTTPAdapter($this->resolver);

        // Apply skip_validation config if set
        if (! empty($this->config['skip_validation'])) {
            $this->adapter->setSkipValidation(true);
        }

        // Call worker start callback if provided
        $workerStartCallback = $this->config['worker_start'];
        if ($workerStartCallback !== null && is_callable($workerStartCallback)) {
            $workerStartCallback($server, $workerId, $this->adapter);
        }

        echo "Worker #{$workerId} started (Adapter: {$this->adapter->getName()})\n";
    }

    /**
     * Main request handler - FAST AS FUCK
     *
     * Performance: <1ms for cache hit
     */
    public function onRequest(Request $request, Response $response): void
    {
        // Custom request handler takes precedence
        $requestHandler = $this->config['request_handler'];
        if ($requestHandler !== null && is_callable($requestHandler)) {
            try {
                $requestHandler($request, $response, $this->adapter);
            } catch (\Throwable $e) {
                error_log("Request handler error: {$e->getMessage()}");
                $response->status(500);
                $response->end('Internal Server Error');
            }

            return;
        }

        $startTime = null;
        if ($this->config['telemetry_headers'] && ! $this->config['fast_path']) {
            $startTime = microtime(true);
        }

        try {
            $directResponse = $this->config['direct_response'];
            if ($directResponse !== null) {
                /** @var int $directResponseStatus */
                $directResponseStatus = $this->config['direct_response_status'];
                $response->status($directResponseStatus);
                /** @var string $directResponseStr */
                $directResponseStr = $directResponse;
                $response->end($directResponseStr);

                return;
            }

            $fixedBackend = $this->config['fixed_backend'];
            $endpoint = is_string($fixedBackend) ? $fixedBackend : null;
            $result = null;
            if ($endpoint === null) {
                // Extract hostname from request
                $hostname = $request->header['host'] ?? null;

                if (! $hostname) {
                    $response->status(400);
                    $response->end('Missing Host header');

                    return;
                }

                // Validate hostname format (basic sanitization)
                if (! $this->isValidHostname($hostname)) {
                    $response->status(400);
                    $response->end('Invalid Host header');

                    return;
                }

                // Route to backend using adapter
                $result = $this->adapter->route($hostname);
                $endpoint = $result->endpoint;
            }

            // Prepare telemetry data before forwarding
            $telemetryData = null;
            if ($this->config['telemetry_headers'] && ! $this->config['fast_path']) {
                $telemetryData = [
                    'start_time' => $startTime,
                    'result' => $result,
                ];
            }

            // Forward request to backend (zero-copy where possible)
            /** @var string $endpoint */
            if (! empty($this->config['raw_backend'])) {
                $this->forwardRawRequest($request, $response, $endpoint, $telemetryData);
            } else {
                $this->forwardRequest($request, $response, $endpoint, $telemetryData);
            }

        } catch (\Exception $e) {
            // Log the full error internally
            error_log("Proxy error: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}");

            // Return generic error to client (prevent information disclosure)
            $response->status(503);
            $response->header('Content-Type', 'application/json');
            $response->end(json_encode([
                'error' => 'Service Unavailable',
                'message' => 'The requested service is temporarily unavailable',
            ]));
        }
    }

    /**
     * Forward HTTP request to backend using Swoole HTTP client
     *
     * Performance: Zero-copy streaming for large responses
     *
     * @param  array<string, mixed>|null  $telemetryData
     */
    protected function forwardRequest(Request $request, Response $response, string $endpoint, ?array $telemetryData = null): void
    {
        [$host, $port] = explode(':', $endpoint.':80');
        $port = (int) $port;

        $poolKey = "{$host}:{$port}";
        if (! isset($this->backendPools[$poolKey])) {
            $this->backendPools[$poolKey] = new Channel($this->config['backend_pool_size']);
        }
        $pool = $this->backendPools[$poolKey];

        $isNewClient = false;
        $client = $pool->pop($this->config['backend_pool_timeout']);
        if (! $client instanceof \Swoole\Coroutine\Http\Client) {
            $client = new \Swoole\Coroutine\Http\Client($host, $port);
            $client->set([
                'timeout' => $this->config['backend_timeout'],
                'keep_alive' => $this->config['backend_keep_alive'],
            ]);
            $isNewClient = true;
        }

        // Forward headers
        if ($this->config['fast_path']) {
            if ($isNewClient) {
                $client->setHeaders([
                    'Host' => $port === 80 ? $host : "{$host}:{$port}",
                ]);
            }
        } else {
            $headers = [];
            foreach ($request->header as $key => $value) {
                $lower = strtolower($key);
                if ($lower !== 'host' && $lower !== 'connection') {
                    $headers[$key] = $value;
                }
            }
            $headers['Host'] = $port === 80 ? $host : "{$host}:{$port}";
            $client->setHeaders($headers);
            if (! empty($request->cookie)) {
                $client->setCookies($request->cookie);
            }
        }

        // Make request
        $method = strtoupper($request->server['request_method'] ?? 'GET');
        $path = $request->server['request_uri'] ?? '/';
        $body = '';
        if ($method !== 'GET' && $method !== 'HEAD') {
            $body = $request->getContent() ?: '';
        }

        switch ($method) {
            case 'GET':
                $client->get($path);
                break;
            case 'POST':
                $client->post($path, $body);
                break;
            case 'HEAD':
                $client->setMethod($method);
                $client->execute($path);
                break;
            default:
                $client->setMethod($method);
                if ($body !== '') {
                    $client->setData($body);
                }
                $client->execute($path);
                break;
        }

        if (empty($this->config['fast_path_assume_ok'])) {
            // Forward response
            $response->status($client->statusCode);
        }

        if (! $this->config['fast_path']) {
            // Forward response headers
            if (! empty($client->headers)) {
                foreach ($client->headers as $key => $value) {
                    $response->header($key, $value);
                }
            }

            // Forward response cookies
            if (! empty($client->set_cookie_headers)) {
                foreach ($client->set_cookie_headers as $cookie) {
                    $response->header('Set-Cookie', $cookie);
                }
            }
        }

        // Add telemetry headers before ending response
        if ($telemetryData !== null) {
            /** @var float $startTime */
            $startTime = $telemetryData['start_time'];
            $latency = round((microtime(true) - $startTime) * 1000, 2);
            $response->header('X-Proxy-Latency-Ms', (string) $latency);

            $telemetryResult = $telemetryData['result'] ?? null;
            if ($telemetryResult instanceof \Utopia\Proxy\ConnectionResult) {
                $response->header('X-Proxy-Protocol', $telemetryResult->protocol);

                if (isset($telemetryResult->metadata['cached'])) {
                    $response->header('X-Proxy-Cache', $telemetryResult->metadata['cached'] ? 'HIT' : 'MISS');
                }
            }
        }

        // Forward response body
        $response->end($client->body);

        if ($client->connected) {
            if (! $pool->push($client, 0.001)) {
                $client->close();
            }
        } else {
            $client->close();
        }
    }

    /**
     * Raw TCP HTTP forwarder for benchmark-only usage.
     *
     * Assumptions:
     * - Backend replies with Content-Length (no chunked encoding).
     * - Only GET/HEAD are supported; other methods fall back to HTTP client.
     *
     * @param  array<string, mixed>|null  $telemetryData
     */
    protected function forwardRawRequest(Request $request, Response $response, string $endpoint, ?array $telemetryData = null): void
    {
        $method = strtoupper($request->server['request_method'] ?? 'GET');
        if ($method !== 'GET' && $method !== 'HEAD') {
            $this->forwardRequest($request, $response, $endpoint, $telemetryData);

            return;
        }

        [$host, $port] = explode(':', $endpoint.':80');
        $port = (int) $port;

        $poolKey = "{$host}:{$port}";
        if (! isset($this->rawBackendPools[$poolKey])) {
            $this->rawBackendPools[$poolKey] = new Channel($this->config['backend_pool_size']);
        }
        $pool = $this->rawBackendPools[$poolKey];

        $client = $pool->pop($this->config['backend_pool_timeout']);
        if (! $client instanceof CoroutineClient || ! $client->isConnected()) {
            $client = new CoroutineClient(SWOOLE_SOCK_TCP);
            $client->set([
                'timeout' => $this->config['backend_timeout'],
            ]);
            if (! $client->connect($host, $port, $this->config['backend_timeout'])) {
                $response->status(502);
                $response->end('Bad Gateway');

                return;
            }
        }

        $path = $request->server['request_uri'] ?? '/';
        $hostHeader = $port === 80 ? $host : "{$host}:{$port}";
        $requestLine = $method.' '.$path." HTTP/1.1\r\n".
            'Host: '.$hostHeader."\r\n".
            "Connection: keep-alive\r\n\r\n";

        if ($client->send($requestLine) === false) {
            $client->close();
            $response->status(502);
            $response->end('Bad Gateway');

            return;
        }

        $buffer = '';
        while (strpos($buffer, "\r\n\r\n") === false) {
            $chunk = $client->recv(8192);
            if ($chunk === '' || $chunk === false) {
                $client->close();
                $response->status(502);
                $response->end('Bad Gateway');

                return;
            }
            $buffer .= $chunk;
        }

        [$headerPart, $bodyPart] = explode("\r\n\r\n", $buffer, 2);
        $contentLength = null;
        $statusCode = 200;
        $chunked = false;

        $lines = explode("\r\n", $headerPart);
        if (preg_match('/^HTTP\/\\d+\\.\\d+\\s+(\\d+)/', $lines[0], $matches)) {
            $statusCode = (int) $matches[1];
        }
        foreach ($lines as $line) {
            if (stripos($line, 'content-length:') === 0) {
                $contentLength = (int) trim(substr($line, 15));
                break;
            }
            if (stripos($line, 'transfer-encoding:') === 0 && stripos($line, 'chunked') !== false) {
                $chunked = true;
            }
        }

        if (! $this->config['raw_backend_assume_ok']) {
            $response->status($statusCode);
        }

        if ($chunked || $contentLength === null) {
            // Fallback: send what we have and close connection to avoid reusing a bad state.
            $response->end($bodyPart);
            $client->close();

            return;
        }

        /** @var string $bodyPartStr */
        $bodyPartStr = $bodyPart;
        $body = $bodyPartStr;
        $remaining = $contentLength - strlen($bodyPartStr);
        while ($remaining > 0) {
            $chunk = $client->recv(min(8192, $remaining));
            if ($chunk === '' || $chunk === false) {
                $client->close();
                $response->status(502);
                $response->end('Bad Gateway');

                return;
            }
            /** @var string $chunkStr */
            $chunkStr = $chunk;
            $body .= $chunkStr;
            $remaining -= strlen($chunkStr);
        }

        // Add telemetry headers before ending response
        if ($telemetryData !== null) {
            /** @var float $startTime */
            $startTime = $telemetryData['start_time'];
            $latency = round((microtime(true) - $startTime) * 1000, 2);
            $response->header('X-Proxy-Latency-Ms', (string) $latency);

            $telemetryResult = $telemetryData['result'] ?? null;
            if ($telemetryResult instanceof \Utopia\Proxy\ConnectionResult) {
                $response->header('X-Proxy-Protocol', $telemetryResult->protocol);

                if (isset($telemetryResult->metadata['cached'])) {
                    $response->header('X-Proxy-Cache', $telemetryResult->metadata['cached'] ? 'HIT' : 'MISS');
                }
            }
        }

        $response->end($body);

        if ($client->isConnected()) {
            if (! $pool->push($client, 0.001)) {
                $client->close();
            }
        } else {
            $client->close();
        }
    }

    /**
     * Validate hostname format
     */
    protected function isValidHostname(string $hostname): bool
    {
        // Remove port if present
        $host = preg_replace('/:\d+$/', '', $hostname);
        if ($host === null) {
            return false;
        }

        // Check for valid hostname/domain format
        // Allow alphanumeric, hyphens, dots, and underscores
        // Prevent injection attempts with null bytes, spaces, or other control characters
        if (strlen($host) > 255 || preg_match('/[\x00-\x1f\x7f\s]/', $host)) {
            return false;
        }

        // Basic format validation: domain or IP
        return preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $host) === 1;
    }

    public function start(): void
    {
        $this->server->start();
    }

    /**
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        /** @var array<string, mixed> $stats */
        $stats = $this->server->stats();

        return [
            'connections' => $stats['connection_num'] ?? 0,
            'requests' => $stats['request_count'] ?? 0,
            'workers' => $stats['worker_num'] ?? 0,
            'adapter' => $this->adapter->getStats(),
        ];
    }
}
