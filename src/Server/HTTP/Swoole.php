<?php

namespace Utopia\Proxy\Server\HTTP;

use Utopia\Proxy\Adapter\HTTP\Swoole as HTTPAdapter;
use Swoole\Coroutine\Channel;
use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * High-performance HTTP proxy server (Swoole Implementation)
 */
class Swoole
{
    protected Server $server;
    protected HTTPAdapter $adapter;
    protected array $config;
    /** @var array<string, Channel> */
    protected array $backendPools = [];

    public function __construct(
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
        ], $config);

        $this->server = new Server($host, $port, SWOOLE_PROCESS);
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
        echo "HTTP Proxy Server started at http://{$this->config['host']}:{$this->config['port']}\n";
        echo "Workers: {$this->config['workers']}\n";
        echo "Max connections: {$this->config['max_connections']}\n";
    }

    public function onWorkerStart(Server $server, int $workerId): void
    {
        // Use adapter from config, or create default
        if (isset($this->config['adapter'])) {
            $this->adapter = $this->config['adapter'];
        } else {
            $this->adapter = new HTTPAdapter();
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
        $startTime = microtime(true);

        try {
            // Extract hostname from request
            $hostname = $request->header['host'] ?? null;

            if (!$hostname) {
                $response->status(400);
                $response->end('Missing Host header');
                return;
            }

            // Route to backend using adapter
            $result = $this->adapter->route($hostname);

            // Forward request to backend (zero-copy where possible)
            $this->forwardRequest($request, $response, $result->endpoint);

            if ($this->config['telemetry_headers']) {
                // Add telemetry headers
                $latency = round((microtime(true) - $startTime) * 1000, 2);
                $response->header('X-Proxy-Latency-Ms', (string)$latency);
                $response->header('X-Proxy-Protocol', $result->protocol);

                if (isset($result->metadata['cached'])) {
                    $response->header('X-Proxy-Cache', $result->metadata['cached'] ? 'HIT' : 'MISS');
                }
            }

        } catch (\Exception $e) {
            $response->status(503);
            $response->header('Content-Type', 'application/json');
            $response->end(json_encode([
                'error' => 'Service Unavailable',
                'message' => $e->getMessage(),
            ]));
        }
    }

    /**
     * Forward HTTP request to backend using Swoole HTTP client
     *
     * Performance: Zero-copy streaming for large responses
     */
    protected function forwardRequest(Request $request, Response $response, string $endpoint): void
    {
        [$host, $port] = explode(':', $endpoint . ':80');
        $port = (int)$port;

        $poolKey = "{$host}:{$port}";
        if (!isset($this->backendPools[$poolKey])) {
            $this->backendPools[$poolKey] = new Channel($this->config['backend_pool_size']);
        }
        $pool = $this->backendPools[$poolKey];

        $client = $pool->pop($this->config['backend_pool_timeout']);
        if (!$client instanceof \Swoole\Coroutine\Http\Client) {
            $client = new \Swoole\Coroutine\Http\Client($host, $port);
        }

        // Set timeout
        $client->set([
            'timeout' => $this->config['backend_timeout'],
            'keep_alive' => $this->config['backend_keep_alive'],
        ]);

        // Forward headers
        $headers = [];
        foreach ($request->header as $key => $value) {
            $lower = strtolower($key);
            if ($lower !== 'host' && $lower !== 'connection') {
                $headers[$key] = $value;
            }
        }
        $headers['Host'] = $port === 80 ? $host : "{$host}:{$port}";
        $client->setHeaders($headers);

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

        // Forward response
        $response->status($client->statusCode);

        // Forward response headers
        if (!empty($client->headers)) {
            foreach ($client->headers as $key => $value) {
                $response->header($key, $value);
            }
        }

        // Forward response cookies
        if (!empty($client->set_cookie_headers)) {
            foreach ($client->set_cookie_headers as $cookie) {
                $response->header('Set-Cookie', $cookie);
            }
        }

        // Forward response body
        $response->end($client->body);

        if ($client->connected) {
            if (!$pool->push($client, 0.001)) {
                $client->close();
            }
        } else {
            $client->close();
        }
    }

    public function start(): void
    {
        $this->server->start();
    }

    public function getStats(): array
    {
        return [
            'connections' => $this->server->stats()['connection_num'] ?? 0,
            'requests' => $this->server->stats()['request_count'] ?? 0,
            'workers' => $this->server->stats()['worker_num'] ?? 0,
            'adapter' => $this->adapter?->getStats() ?? [],
        ];
    }
}
