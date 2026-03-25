<?php

namespace Utopia\Proxy\Server\TCP;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Server as CoroutineServer;
use Swoole\Coroutine\Server\Connection;
use Swoole\Coroutine\Socket;
use Utopia\Console;
use Utopia\Proxy\Adapter\TCP as TCPAdapter;
use Utopia\Proxy\Resolver;

/**
 * High-performance TCP proxy server (Swoole Coroutine Implementation)
 *
 * Supports optional TLS termination:
 * - PostgreSQL: STARTTLS via SSLRequest/SSLResponse handshake
 * - MySQL: SSL capability flag in server greeting
 *
 * When TLS is enabled, the coroutine server creates SSL-enabled listeners
 * and handles TLS handshakes per-connection. For PostgreSQL STARTTLS,
 * the proxy intercepts the SSLRequest, responds with 'S', then enables
 * crypto on the socket before processing the real startup message.
 *
 * Example:
 * ```php
 * $tls = new TLS(certificate: '/certs/server.crt', key: '/certs/server.key');
 * $config = new Config(ports: [5432, 3306], tls: $tls);
 * $server = new SwooleCoroutine($config, $resolver);
 * $server->start();
 * ```
 */
class SwooleCoroutine
{
    /** @var array<int, CoroutineServer> */
    protected array $servers = [];

    /** @var array<int, TCPAdapter> */
    protected array $adapters = [];

    protected Config $config;

    protected ?TLSContext $tlsContext = null;

    public function __construct(
        Config $config,
        protected Resolver $resolver,
    ) {
        $this->config = $config;

        if ($this->config->isTlsEnabled()) {
            /** @var TLS $tls */
            $tls = $this->config->tls;
            $tls->validate();
            $this->tlsContext = $this->config->getTLSContext();
        }

        $this->initAdapters();
        $this->configureServers();
    }

    protected function initAdapters(): void
    {
        foreach ($this->config->ports as $port) {
            if ($this->config->adapterFactory !== null) {
                /** @var TCPAdapter $adapter */
                $adapter = ($this->config->adapterFactory)($port);
            } else {
                $adapter = new TCPAdapter(port: $port, resolver: $this->resolver);
            }

            if ($this->config->skipValidation) {
                $adapter->setSkipValidation(true);
            }

            if ($this->config->cacheTTL > 0) {
                $adapter->setCacheTTL($this->config->cacheTTL);
            }

            $adapter->setTimeout($this->config->timeout);
            $adapter->setConnectTimeout($this->config->connectTimeout);

            $this->adapters[$port] = $adapter;
        }
    }

    protected function configureServers(): void
    {
        // Global coroutine settings
        Coroutine::set([
            'max_coroutine' => $this->config->maxCoroutine,
            'socket_buffer_size' => $this->config->socketBufferSize,
            'log_level' => $this->config->logLevel,
        ]);

        $ssl = $this->tlsContext !== null;

        foreach ($this->config->ports as $port) {
            $server = new CoroutineServer($this->config->host, $port, $ssl, $this->config->enableReusePort);

            // Only socket-protocol settings are applicable to Coroutine\Server
            $settings = [
                'open_tcp_nodelay' => true,
                'open_tcp_keepalive' => true,
                'tcp_keepidle' => $this->config->tcpKeepidle,
                'tcp_keepinterval' => $this->config->tcpKeepinterval,
                'tcp_keepcount' => $this->config->tcpKeepcount,
                'open_length_check' => false,
                'package_max_length' => $this->config->packageMaxLength,
                'buffer_output_size' => $this->config->bufferOutputSize,
            ];

            // Apply TLS settings when enabled
            if ($this->tlsContext !== null) {
                $settings = array_merge($settings, $this->tlsContext->toSwooleConfig());
            }

            $server->set($settings);

            // Coroutine\Server::start() already spawns a coroutine per connection
            $server->handle(function (Connection $connection) use ($port): void {
                $this->handleConnection($connection, $port);
            });

            $this->servers[$port] = $server;
        }
    }

    public function onStart(): void
    {
        Console::success("TCP Proxy Server started at {$this->config->host}");
        Console::log('Ports: '.implode(', ', $this->config->ports));
        Console::log("Workers: {$this->config->workers}");
        Console::log("Max connections: {$this->config->maxConnections}");

        if ($this->config->isTlsEnabled()) {
            Console::info('TLS: enabled');
            if ($this->config->tls?->isMutual()) {
                Console::info('mTLS: enabled (client certificates required)');
            }
        }
    }

    public function onWorkerStart(int $workerId = 0): void
    {
        Console::log("Worker #{$workerId} started");
    }

    protected function handleConnection(Connection $connection, int $port): void
    {
        /** @var Socket $clientSocket */
        $clientSocket = $connection->exportSocket();
        $clientId = spl_object_id($connection);
        $adapter = $this->adapters[$port];
        $bufferSize = $this->config->receiveBufferSize;

        if ($this->config->logConnections) {
            Console::log("Client #{$clientId} connected to port {$port}");
        }

        /** @var string|false $data */
        $data = $clientSocket->recv($bufferSize);
        if ($data === false || $data === '') {
            $clientSocket->close();

            return;
        }

        // Handle PostgreSQL STARTTLS negotiation.
        // PG clients send an SSLRequest before the real startup message.
        // When TLS is enabled with Swoole's coroutine SSL server, the TLS
        // handshake is handled at the transport level. We respond with 'S'
        // to satisfy the PG protocol, then read the real startup message.
        if ($this->tlsContext !== null && $port === 5432 && TLS::isPostgreSQLSSLRequest($data)) {
            $clientSocket->sendAll(TLS::PG_SSL_RESPONSE_OK);

            /** @var string|false $data */
            $data = $clientSocket->recv($bufferSize);
            if ($data === false || $data === '') {
                $clientSocket->close();

                return;
            }
        }

        $resourceId = (string) $clientId;
        $done = new Channel(1);

        try {
            $backendClient = $adapter->getConnection($data, $clientId);
        } catch (\Exception $e) {
            Console::error("Error handling data from #{$clientId}: {$e->getMessage()}");
            $clientSocket->close();

            return;
        }

        /** @var Socket $backendSocket */
        $backendSocket = $backendClient->exportSocket();

        $adapter->notifyConnect($resourceId);

        \go(function () use ($clientSocket, $backendSocket, $bufferSize, $adapter, $resourceId, $done): void {
            while (true) {
                /** @var string|false $data */
                $data = $backendSocket->recv($bufferSize);
                if ($data === false || $data === '') {
                    break;
                }
                $adapter->recordBytes($resourceId, 0, \strlen($data));
                if ($clientSocket->sendAll($data) === false) {
                    break;
                }
            }
            $done->push(true);
        });

        $adapter->recordBytes($resourceId, \strlen($data), 0);
        if ($backendSocket->sendAll($data) === false) {
            $backendSocket->close();
            $done->pop(1.0);
            $clientSocket->close();
            $adapter->notifyClose($resourceId);
            $adapter->closeConnection($clientId);

            return;
        }

        while (true) {
            /** @var string|false $data */
            $data = $clientSocket->recv($bufferSize);
            if ($data === false || $data === '') {
                break;
            }
            $adapter->recordBytes($resourceId, \strlen($data), 0);
            $adapter->track($resourceId);
            if ($backendSocket->sendAll($data) === false) {
                break;
            }
        }

        $backendSocket->close();
        $done->pop();
        $clientSocket->close();

        $adapter->notifyClose($resourceId);
        $adapter->closeConnection($clientId);

        if ($this->config->logConnections) {
            Console::log("Client #{$clientId} disconnected");
        }
    }

    public function start(): void
    {
        $runner = function (): void {
            $this->onStart();
            $this->onWorkerStart(0);

            foreach ($this->servers as $server) {
                \go(function () use ($server): void {
                    $server->start();
                });
            }
        };

        if (Coroutine::getCid() > 0) {
            $runner();

            return;
        }

        Coroutine\run($runner);
    }

    /**
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        $adapterStats = [];
        foreach ($this->adapters as $port => $adapter) {
            $adapterStats[$port] = $adapter->getStats();
        }

        /** @var array<string, mixed> $coroutineStats */
        $coroutineStats = Coroutine::stats();

        return [
            'connections' => 0,
            'workers' => 1,
            'coroutines' => $coroutineStats['coroutine_num'] ?? 0,
            'adapters' => $adapterStats,
        ];
    }
}
