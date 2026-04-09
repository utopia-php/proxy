<?php

namespace Utopia\Proxy\Server\HTTP\Swoole;

use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Client as CoroutineClient;
use Swoole\Coroutine\Http\Client as HttpClient;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Utopia\Console;
use Utopia\Proxy\Adapter;
use Utopia\Proxy\Server\HTTP\Config;
use Utopia\Validator\Hostname;

/**
 * Shared HTTP request handling logic used by both
 * the event-driven Swoole server and the coroutine server.
 */
trait Handler
{
    protected Adapter $adapter;

    protected Config $config;

    /** @var array<string, Channel> */
    protected array $pools = [];

    public function onRequest(Request $request, Response $response): void
    {
        if ($this->config->requestHandler !== null) {
            try {
                ($this->config->requestHandler)($request, $response, $this->adapter);
            } catch (\Throwable $e) {
                Console::error("Request handler error: {$e->getMessage()}");
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

            /** @var string $endpoint */
            if ($this->config->rawBackend) {
                $this->forwardRawRequest($request, $response, $endpoint);
            } else {
                $this->forwardRequest($request, $response, $endpoint);
            }

        } catch (\Exception $e) {
            Console::error("Proxy error: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}");

            $response->status(503);
            $response->header('Content-Type', 'application/json');
            $response->end(json_encode([
                'error' => 'Service Unavailable',
                'message' => 'The requested service is temporarily unavailable',
            ]));
        }
    }

    protected function forwardRequest(Request $request, Response $response, string $endpoint): void
    {
        [$host, $port] = Adapter::parseEndpoint($endpoint, 80);

        $poolKey = "{$host}:{$port}";
        if (!isset($this->pools[$poolKey])) {
            $this->pools[$poolKey] = new Channel($this->config->poolSize);
        }
        $pool = $this->pools[$poolKey];

        $isNew = false;
        $client = $pool->pop($this->config->poolTimeout);
        if (!$client instanceof HttpClient) {
            $client = new HttpClient($host, $port);
            $client->set([
                'timeout' => $this->config->timeout,
                'keep_alive' => $this->config->keepAlive,
            ]);
            $isNew = true;
        }

        if ($this->config->fastPath) {
            if ($isNew) {
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
            $path .= '?'.$query;
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
    protected function forwardRawRequest(Request $request, Response $response, string $endpoint): void
    {
        /** @var array<string, string> $requestServer */
        $requestServer = $request->server ?? [];
        $method = strtoupper($requestServer['request_method'] ?? 'GET');
        if ($method !== 'GET' && $method !== 'HEAD') {
            $this->forwardRequest($request, $response, $endpoint);

            return;
        }

        [$host, $port] = Adapter::parseEndpoint($endpoint, 80);

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
            $path .= '?'.$query;
        }
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
        $skipHeaders = ['connection', 'keep-alive', 'transfer-encoding', 'content-length'];
        for ($i = 1; $i < count($lines); $i++) {
            $colonPos = strpos($lines[$i], ':');
            if ($colonPos === false) {
                continue;
            }
            $key = substr($lines[$i], 0, $colonPos);
            $value = trim(substr($lines[$i], $colonPos + 1));
            $lower = strtolower($key);
            if ($lower === 'content-length') {
                $contentLength = (int) $value;
            } elseif ($lower === 'transfer-encoding' && stripos($value, 'chunked') !== false) {
                $chunked = true;
            }
            if (!in_array($lower, $skipHeaders, true)) {
                $response->header($key, $value);
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

        $response->end($body);

        if ($client->isConnected()) {
            if (!$pool->push($client, 0.001)) {
                $client->close();
            }
        } else {
            $client->close();
        }
    }

    protected function isValidHostname(string $hostname): bool
    {
        $host = preg_replace('/:\d+$/', '', $hostname);
        if ($host === null) {
            return false;
        }

        return (new Hostname())->isValid($host);
    }
}
