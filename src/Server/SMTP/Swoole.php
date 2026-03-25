<?php

namespace Utopia\Proxy\Server\SMTP;

use Swoole\Constant;
use Swoole\Coroutine;
use Swoole\Coroutine\Client;
use Swoole\Server;
use Utopia\Proxy\Adapter;
use Utopia\Proxy\Protocol;
use Utopia\Proxy\Resolver;

/**
 * High-performance SMTP proxy server
 *
 * Example:
 * ```php
 * $resolver = new MyEmailResolver();
 * $server = new Swoole($resolver, new Config(host: '0.0.0.0', port: 25));
 * $server->start();
 * ```
 */
class Swoole
{
    protected Server $server;

    protected Adapter $adapter;

    protected Config $config;

    /** @var array<int, Connection> */
    protected array $connections = [];

    public function __construct(
        protected Resolver $resolver,
        ?Config $config = null,
    ) {
        $this->config = $config ?? new Config();
        $this->server = new Server(
            $this->config->host,
            $this->config->port,
            SWOOLE_PROCESS,
            SWOOLE_SOCK_TCP,
        );
        $this->configure();
    }

    protected function configure(): void
    {
        $this->server->set([
            'worker_num' => $this->config->workers,
            'max_connection' => $this->config->maxConnections,
            'max_coroutine' => $this->config->maxCoroutine,
            'socket_buffer_size' => $this->config->socketBufferSize,
            'buffer_output_size' => $this->config->bufferOutputSize,
            'enable_coroutine' => $this->config->enableCoroutine,
            'max_wait_time' => $this->config->maxWaitTime,
            'open_tcp_nodelay' => true,
            'tcp_fastopen' => true,
            'open_cpu_affinity' => true,
            'open_length_check' => false,
            'package_eof' => "\r\n",
            'package_max_length' => 10 * 1024 * 1024,
            'task_enable_coroutine' => true,
        ]);

        $this->server->on(Constant::EVENT_START, $this->onStart(...));
        $this->server->on(Constant::EVENT_WORKER_START, $this->onWorkerStart(...));
        $this->server->on(Constant::EVENT_CONNECT, $this->onConnect(...));
        $this->server->on(Constant::EVENT_RECEIVE, $this->onReceive(...));
        $this->server->on(Constant::EVENT_CLOSE, $this->onClose(...));
    }

    public function onStart(Server $server): void
    {
        echo "SMTP Proxy Server started at {$this->config->host}:{$this->config->port}\n";
        echo "Workers: {$this->config->workers}\n";
        echo "Max connections: {$this->config->maxConnections}\n";
    }

    public function onWorkerStart(Server $server, int $workerId): void
    {
        $this->adapter = new Adapter(
            $this->resolver,
            name: 'SMTP',
            protocol: Protocol::SMTP
        );

        if ($this->config->skipValidation) {
            $this->adapter->setSkipValidation(true);
        }

        echo "Worker #{$workerId} started (Adapter: {$this->adapter->getName()})\n";
    }

    /**
     * Handle new SMTP connection - send greeting
     */
    public function onConnect(Server $server, int $fd, int $reactorId): void
    {
        echo "Client #{$fd} connected\n";

        $server->send($fd, "220 utopia-php.io ESMTP Proxy\r\n");

        $this->connections[$fd] = new Connection();
    }

    /**
     * Main SMTP command handler
     */
    public function onReceive(Server $server, int $fd, int $reactorId, string $data): void
    {
        try {
            if (!isset($this->connections[$fd])) {
                $this->connections[$fd] = new Connection();
            }

            $connection = $this->connections[$fd];

            $command = strtoupper(substr(trim($data), 0, 4));

            switch ($command) {
                case 'EHLO':
                case 'HELO':
                    $this->handleHelo($server, $fd, $data, $connection);
                    break;

                case 'MAIL':
                case 'RCPT':
                case 'DATA':
                case 'RSET':
                case 'NOOP':
                case 'QUIT':
                    $this->forwardToBackend($server, $fd, $data, $connection);
                    break;

                default:
                    $server->send($fd, "500 Unknown command\r\n");
            }

        } catch (\Exception $e) {
            echo "Error handling SMTP from #{$fd}: {$e->getMessage()}\n";
            $server->send($fd, "421 Service not available\r\n");
            $server->close($fd);
        }
    }

    /**
     * Handle EHLO/HELO - extract domain and route to backend
     */
    protected function handleHelo(Server $server, int $fd, string $data, Connection $connection): void
    {
        if (preg_match('/^(EHLO|HELO)\s+([^\s]+)/i', $data, $matches)) {
            $domain = $matches[2];
            $connection->domain = $domain;

            $result = $this->adapter->route($domain);

            $connection->backend = $this->connectToBackend($result->endpoint, 25);

            $this->forwardToBackend($server, $fd, $data, $connection);

        } else {
            $server->send($fd, "501 Syntax error\r\n");
        }
    }

    /**
     * Forward command to backend SMTP server
     */
    protected function forwardToBackend(Server $server, int $fd, string $data, Connection $connection): void
    {
        if ($connection->backend === null) {
            throw new \Exception('No backend connection');
        }

        $connection->backend->send($data);

        \go(function () use ($server, $fd, $connection) {
            $response = $connection->backend->recv(8192);

            if ($response !== false && $response !== '') {
                $server->send($fd, $response);
            }
        });
    }

    protected function connectToBackend(string $endpoint, int $port): Client
    {
        [$host, $port] = explode(':', $endpoint . ':' . $port);
        $port = (int) $port;

        $client = new Client(SWOOLE_SOCK_TCP);

        if (!$client->connect($host, $port, $this->config->connectTimeout)) {
            throw new \Exception("Failed to connect to backend SMTP: {$host}:{$port}");
        }

        $client->set([
            'timeout' => $this->config->timeout,
        ]);

        $client->recv(8192);

        return $client;
    }

    public function onClose(Server $server, int $fd, int $reactorId): void
    {
        echo "Client #{$fd} disconnected\n";

        if (isset($this->connections[$fd]) && $this->connections[$fd]->backend !== null) {
            $this->connections[$fd]->backend->close();
        }

        unset($this->connections[$fd]);
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
        /** @var array<string, mixed> $serverStats */
        $serverStats = $this->server->stats();
        /** @var array<string, mixed> $coroutineStats */
        $coroutineStats = Coroutine::stats();

        return [
            'connections' => $serverStats['connection_num'] ?? 0,
            'workers' => $serverStats['worker_num'] ?? 0,
            'coroutines' => $coroutineStats['coroutine_num'] ?? 0,
            'adapter' => $this->adapter->getStats(),
        ];
    }
}
