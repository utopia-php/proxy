<?php

namespace Utopia\Proxy\Server\HTTP;

use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Client as CoroutineClient;
use Swoole\Coroutine\Http\Client as HttpClient;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Utopia\Proxy\Adapter;
use Utopia\Proxy\Protocol;
use Utopia\Proxy\Resolver;

/**
 * High-performance HTTP proxy server (Swoole Implementation)
 *
 * Example:
 * ```php
 * $resolver = new MyFunctionResolver();
 * $server = new Swoole($resolver, new Config(host: '0.0.0.0', port: 80));
 * $server->start();
 * ```
 */
class Swoole
{
    protected Server $server;

    protected Adapter $adapter;

    protected Config $config;

    /** @var array<string, Channel> */
    protected array $pools = [];

    public function __construct(
        protected ?Resolver $resolver = null,
        ?Config $config = null,
    ) {
        $this->config = $config ?? new Config();
        $this->server = new Server(
            $this->config->host,
            $this->config->port,
            $this->config->serverMode,
        );
        $this->configure();
    }

    protected function configure(): void
    {
        $this->server->set([
            'worker_num' => $this->config->workers,
            'reactor_num' => $this->config->reactorNum,
            'max_connection' => $this->config->maxConnections,
            'max_coroutine' => $this->config->maxCoroutine,
            'socket_buffer_size' => $this->config->socketBufferSize,
            'buffer_output_size' => $this->config->bufferOutputSize,
            'enable_coroutine' => $this->config->enableCoroutine,
            'max_wait_time' => $this->config->maxWaitTime,
            'open_http_protocol' => $this->config->httpProtocol,
            'open_http2_protocol' => $this->config->http2Protocol,
            'http_keepalive_timeout' => $this->config->keepaliveTimeout,
            'max_request' => $this->config->maxRequest,
            'dispatch_mode' => $this->config->dispatchMode,
            'enable_reuse_port' => $this->config->enableReusePort,
            'backlog' => $this->config->backlog,
            'http_parse_post' => $this->config->parsePost,
            'http_parse_cookie' => $this->config->parseCookie,
            'http_parse_files' => $this->config->parseFiles,
            'http_compression' => $this->config->compression,
            'log_level' => $this->config->logLevel,
            'open_tcp_nodelay' => true,
            'tcp_fastopen' => true,
            'open_cpu_affinity' => true,
            'tcp_defer_accept' => 5,
            'task_enable_coroutine' => true,
        ]);

        $this->server->on('start', $this->onStart(...));
        $this->server->on('workerStart', $this->onWorkerStart(...));
        $this->server->on('request', $this->onRequest(...));
    }

    public function onStart(Server $server): void
    {
        echo "HTTP Proxy Server started at http://{$this->config->host}:{$this->config->port}\n";
        echo "Workers: {$this->config->workers}\n";
        echo "Max connections: {$this->config->maxConnections}\n";
    }

    public function onWorkerStart(Server $server, int $workerId): void
    {
        $this->adapter = new Adapter($this->resolver, name: 'HTTP', protocol: Protocol::HTTP);

        if ($this->config->skipValidation) {
            $this->adapter->setSkipValidation(true);
        }

        if ($this->config->workerStart !== null) {
            ($this->config->workerStart)($server, $workerId, $this->adapter);
        }

        echo "Worker #{$workerId} started (Adapter: {$this->adapter->getName()})\n";
    }

    /**
     * Main request handler
     *
     * Performance: <1ms for cache hit
     */
    public function onRequest(Request $request, Response $response): void
    {
        if ($this->config->requestHandler !== null) {
            try {
                ($this->config->requestHandler)($request, $response, $this->adapter);
            } catch (\Throwable $e) {
                error_log("Request handler error: {$e->getMessage()}");
                $response->status(500);
                $response->end('Internal Server Error');
            }

            return;
        }

        try {
            if ($this->config->directResponse !== null) {
                $response->status($this->config->directResponseStatus);
                $response->end($this->config->directResponse);

                return;
            }

            $endpoint = is_string($this->config->fixedBackend) ? $this->config->fixedBackend : null;
            $result = null;
            if ($endpoint === null) {
                /** @var array<string, string> $requestHeaders */
                $requestHeaders = $request->header ?? [];
                $hostname = $requestHeaders['host'] ?? null;

                if (!$hostname) {
                    $response->status(400);
                    $response->end('Missing Host header');

                    return;
                }

                if (!$this->isValidHostname($hostname)) {
                    $response->status(400);
                    $response->end('Invalid Host header');

                    return;
                }

                $result = $this->adapter->route($hostname);
                $endpoint = $result->endpoint;
            }

            $telemetry = null;
            if ($this->config->telemetry && !$this->config->fastPath) {
                $telemetry = new Telemetry(
                    startTime: microtime(true),
                    result: $result,
                );
            }

            /** @var string $endpoint */
            if ($this->config->rawBackend) {
                $this->forwardRawRequest($request, $response, $endpoint, $telemetry);
            } else {
                $this->forwardRequest($request, $response, $endpoint, $telemetry);
            }

        } catch (\Exception $e) {
            error_log("Proxy error: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}");

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
     */
    protected function forwardRequest(Request $request, Response $response, string $endpoint, ?Telemetry $telemetry = null): void
    {
        [$host, $port] = explode(':', $endpoint . ':80');
        $port = (int) $port;

        $poolKey = "{$host}:{$port}";
        if (!isset($this->pools[$poolKey])) {
            $this->pools[$poolKey] = new Channel($this->config->poolSize);
        }
        $pool = $this->pools[$poolKey];

        $isNewClient = false;
        $client = $pool->pop($this->config->poolTimeout);
        if (!$client instanceof HttpClient) {
            $client = new HttpClient($host, $port);
            $client->set([
                'timeout' => $this->config->timeout,
                'keep_alive' => $this->config->keepAlive,
            ]);
            $isNewClient = true;
        }

        if ($this->config->fastPath) {
            if ($isNewClient) {
                $client->setHeaders([
                    'Host' => $port === 80 ? $host : "{$host}:{$port}",
                ]);
            }
        } else {
            $headers = [];
            /** @var array<string, string> $requestHeaders */
            $requestHeaders = $request->header ?? [];
            foreach ($requestHeaders as $key => $value) {
                $lower = strtolower($key);
                if ($lower !== 'host' && $lower !== 'connection') {
                    $headers[$key] = $value;
                }
            }
            $headers['Host'] = $port === 80 ? $host : "{$host}:{$port}";
            $client->setHeaders($headers);
            if (!empty($request->cookie)) {
                /** @var array<string, string> $cookies */
                $cookies = $request->cookie;
                $client->setCookies($cookies);
            }
        }

        /** @var array<string, string> $requestServer */
        $requestServer = $request->server ?? [];
        $method = strtoupper($requestServer['request_method'] ?? 'GET');
        $path = $requestServer['request_uri'] ?? '/';
        $query = $requestServer['query_string'] ?? '';
        if ($query !== '') {
            $path .= '?' . $query;
        }
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

        if (!$this->config->fastPathAssumeOk) {
            $response->status($client->statusCode);
        }

        if (!$this->config->fastPath) {
            if (!empty($client->headers)) {
                /** @var array<string, string> $responseHeaders */
                $responseHeaders = $client->headers;
                foreach ($responseHeaders as $key => $value) {
                    $response->header($key, $value);
                }
            }

            if (!empty($client->set_cookie_headers)) {
                /** @var list<string> $cookieHeaders */
                $cookieHeaders = $client->set_cookie_headers;
                foreach ($cookieHeaders as $cookie) {
                    $response->header('Set-Cookie', $cookie);
                }
            }
        }

        $this->addTelemetryHeaders($response, $telemetry);

        $response->end($client->body);

        if ($client->connected) {
            if (!$pool->push($client, 0.001)) {
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
     */
    protected function forwardRawRequest(Request $request, Response $response, string $endpoint, ?Telemetry $telemetry = null): void
    {
        /** @var array<string, string> $requestServer */
        $requestServer = $request->server ?? [];
        $method = strtoupper($requestServer['request_method'] ?? 'GET');
        if ($method !== 'GET' && $method !== 'HEAD') {
            $this->forwardRequest($request, $response, $endpoint, $telemetry);

            return;
        }

        [$host, $port] = explode(':', $endpoint . ':80');
        $port = (int) $port;

        $poolKey = "raw:{$host}:{$port}";
        if (!isset($this->pools[$poolKey])) {
            $this->pools[$poolKey] = new Channel($this->config->poolSize);
        }
        $pool = $this->pools[$poolKey];

        $client = $pool->pop($this->config->poolTimeout);
        if (!$client instanceof CoroutineClient || !$client->isConnected()) {
            $client = new CoroutineClient(SWOOLE_SOCK_TCP);
            $client->set([
                'timeout' => $this->config->timeout,
            ]);
            if (!$client->connect($host, $port, $this->config->connectTimeout)) {
                $response->status(502);
                $response->end('Bad Gateway');

                return;
            }
        }

        $path = $requestServer['request_uri'] ?? '/';
        $query = $requestServer['query_string'] ?? '';
        if ($query !== '') {
            $path .= '?' . $query;
        }
        $hostHeader = $port === 80 ? $host : "{$host}:{$port}";
        $requestLine = $method . ' ' . $path . " HTTP/1.1\r\n" .
            'Host: ' . $hostHeader . "\r\n" .
            "Connection: keep-alive\r\n\r\n";

        if ($client->send($requestLine) === false) {
            $client->close();
            $response->status(502);
            $response->end('Bad Gateway');

            return;
        }

        $buffer = '';
        while (strpos($buffer, "\r\n\r\n") === false) {
            /** @var string|false $chunk */
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

        if (!$this->config->rawBackendAssumeOk) {
            $response->status($statusCode);
        }

        if ($chunked || $contentLength === null) {
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

        $this->addTelemetryHeaders($response, $telemetry);

        $response->end($body);

        if ($client->isConnected()) {
            if (!$pool->push($client, 0.001)) {
                $client->close();
            }
        } else {
            $client->close();
        }
    }

    protected function addTelemetryHeaders(Response $response, ?Telemetry $telemetry): void
    {
        if ($telemetry === null) {
            return;
        }

        $latency = round((microtime(true) - $telemetry->startTime) * 1000, 2);
        $response->header('X-Proxy-Latency-Ms', (string) $latency);

        if ($telemetry->result !== null) {
            $response->header('X-Proxy-Protocol', $telemetry->result->protocol->value);

            if (isset($telemetry->result->metadata['cached'])) {
                $response->header('X-Proxy-Cache', $telemetry->result->metadata['cached'] ? 'HIT' : 'MISS');
            }
        }
    }

    protected function isValidHostname(string $hostname): bool
    {
        $host = preg_replace('/:\d+$/', '', $hostname);
        if ($host === null) {
            return false;
        }

        if (strlen($host) > 255 || preg_match('/[\x00-\x1f\x7f\s]/', $host)) {
            return false;
        }

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
