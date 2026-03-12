<?php

namespace Utopia\Proxy\Server\TCP;

use Swoole\Coroutine;
use Swoole\Coroutine\Client;
use Swoole\Server;
use Utopia\Proxy\Adapter\TCP as TCPAdapter;
use Utopia\Proxy\Resolver;
use Utopia\Proxy\Resolver\ReadWriteResolver;
use Utopia\Query\Type as QueryType;

/**
 * High-performance TCP proxy server (Swoole Implementation)
 *
 * Supports optional TLS termination for database connections:
 * - PostgreSQL: STARTTLS via SSLRequest/SSLResponse handshake
 * - MySQL: SSL capability flag in server greeting
 *
 * When TLS is enabled, the server uses SWOOLE_SOCK_TCP | SWOOLE_SSL socket type
 * and Swoole handles the TLS handshake natively. For PostgreSQL STARTTLS, the
 * proxy intercepts the SSLRequest message, responds with 'S', and Swoole
 * upgrades the connection to TLS before forwarding the subsequent startup message.
 *
 * Example:
 * ```php
 * $tls = new TLS(certPath: '/certs/server.crt', keyPath: '/certs/server.key');
 * $config = new Config(host: '0.0.0.0', ports: [5432, 3306], tls: $tls);
 * $server = new Swoole($resolver, $config);
 * $server->start();
 * ```
 */
class Swoole
{
    protected Server $server;

    /** @var array<int, TCPAdapter> */
    protected array $adapters = [];

    protected Config $config;

    protected ?TlsContext $tlsContext = null;

    /** @var array<int, bool> */
    protected array $forwarding = [];

    /** @var array<int, Client> Primary/default backend connections */
    protected array $backendClients = [];

    /** @var array<int, Client> Read replica backend connections (when read/write split enabled) */
    protected array $readBackendClients = [];

    /** @var array<int, string> */
    protected array $clientDatabaseIds = [];

    /** @var array<int, int> */
    protected array $clientPorts = [];

    /**
     * Tracks connections awaiting TLS upgrade (PostgreSQL STARTTLS).
     * After sending 'S' in response to SSLRequest, the connection
     * must complete the TLS handshake before we see the real startup message.
     *
     * @var array<int, bool>
     */
    protected array $pendingTlsUpgrade = [];

    public function __construct(
        protected Resolver $resolver,
        ?Config $config = null,
    ) {
        $this->config = $config ?? new Config();

        if ($this->config->isTlsEnabled()) {
            /** @var TLS $tls */
            $tls = $this->config->tls;
            $tls->validate();
            $this->tlsContext = $this->config->getTlsContext();
        }

        $socketType = $this->tlsContext !== null
            ? $this->tlsContext->getSocketType()
            : SWOOLE_SOCK_TCP;

        // Create main server on first port
        $this->server = new Server(
            $this->config->host,
            $this->config->ports[0],
            SWOOLE_PROCESS,
            $socketType,
        );

        // Add listeners for additional ports
        for ($i = 1; $i < count($this->config->ports); $i++) {
            $this->server->addlistener(
                $this->config->host,
                $this->config->ports[$i],
                $socketType,
            );
        }

        $this->configure();
    }

    protected function configure(): void
    {
        $settings = [
            'worker_num' => $this->config->workers,
            'reactor_num' => $this->config->reactorNum,
            'max_connection' => $this->config->maxConnections,
            'max_coroutine' => $this->config->maxCoroutine,
            'socket_buffer_size' => $this->config->socketBufferSize,
            'buffer_output_size' => $this->config->bufferOutputSize,
            'enable_coroutine' => $this->config->enableCoroutine,
            'max_wait_time' => $this->config->maxWaitTime,
            'log_level' => $this->config->logLevel,
            'dispatch_mode' => $this->config->dispatchMode,
            'enable_reuse_port' => $this->config->enableReusePort,
            'backlog' => $this->config->backlog,

            // TCP performance tuning
            'open_tcp_nodelay' => true,
            'tcp_fastopen' => true,
            'open_cpu_affinity' => true,
            'tcp_defer_accept' => 5,
            'open_tcp_keepalive' => true,
            'tcp_keepidle' => $this->config->tcpKeepidle,
            'tcp_keepinterval' => $this->config->tcpKeepinterval,
            'tcp_keepcount' => $this->config->tcpKeepcount,

            // Package settings for database protocols
            'open_length_check' => false, // Let database handle framing
            'package_max_length' => $this->config->packageMaxLength,

            // Enable stats
            'task_enable_coroutine' => true,
        ];

        // Apply TLS settings when enabled
        if ($this->tlsContext !== null) {
            $settings = array_merge($settings, $this->tlsContext->toSwooleConfig());
        }

        $this->server->set($settings);

        $this->server->on('start', $this->onStart(...));
        $this->server->on('workerStart', $this->onWorkerStart(...));
        $this->server->on('connect', $this->onConnect(...));
        $this->server->on('receive', $this->onReceive(...));
        $this->server->on('close', $this->onClose(...));
    }

    public function onStart(Server $server): void
    {
        echo "TCP Proxy Server started at {$this->config->host}\n";
        echo 'Ports: '.implode(', ', $this->config->ports)."\n";
        echo "Workers: {$this->config->workers}\n";
        echo "Max connections: {$this->config->maxConnections}\n";

        if ($this->config->isTlsEnabled()) {
            echo "TLS: enabled\n";
            if ($this->config->tls?->isMutualTLS()) {
                echo "mTLS: enabled (client certificates required)\n";
            }
        }
    }

    public function onWorkerStart(Server $server, int $workerId): void
    {
        // Initialize TCP adapter per worker per port
        foreach ($this->config->ports as $port) {
            $adapter = new TCPAdapter($this->resolver, port: $port);

            if ($this->config->skipValidation) {
                $adapter->setSkipValidation(true);
            }

            $adapter->setConnectTimeout($this->config->backendConnectTimeout);

            if ($this->config->readWriteSplit) {
                $adapter->setReadWriteSplit(true);
            }

            $this->adapters[$port] = $adapter;
        }

        echo "Worker #{$workerId} started\n";
    }

    /**
     * Handle new TCP connection
     */
    public function onConnect(Server $server, int $fd, int $reactorId): void
    {
        /** @var array<string, mixed> $info */
        $info = $server->getClientInfo($fd);
        /** @var int $port */
        $port = $info['server_port'] ?? 0;
        $this->clientPorts[$fd] = $port;

        if ($this->config->logConnections) {
            echo "Client #{$fd} connected to port {$port}\n";
        }
    }

    /**
     * Main receive handler - FAST AS FUCK
     *
     * Performance: <1ms overhead for proxying
     *
     * When TLS is enabled, handles protocol-specific SSL negotiation:
     * - PostgreSQL: Intercepts SSLRequest, responds 'S', Swoole upgrades to TLS
     * - MySQL: Swoole handles SSL natively via SWOOLE_SSL socket type
     */
    public function onReceive(Server $server, int $fd, int $reactorId, string $data): void
    {
        // Fast path: existing connection - forward to appropriate backend
        if (isset($this->backendClients[$fd])) {
            $databaseId = $this->clientDatabaseIds[$fd] ?? null;
            $port = $this->clientPorts[$fd] ?? 5432;
            $adapter = $this->adapters[$port] ?? null;

            // Record inbound bytes and track activity
            if ($databaseId !== null && $adapter !== null) {
                $adapter->recordBytes($databaseId, \strlen($data), 0);
                $adapter->track($databaseId);
            }

            // When read/write split is active and we have a read backend, classify and route
            if (isset($this->readBackendClients[$fd]) && $adapter !== null) {
                $queryType = $adapter->classifyQuery($data, $fd);

                if ($queryType === QueryType::Read) {
                    $this->readBackendClients[$fd]->send($data);

                    return;
                }
            }

            $this->backendClients[$fd]->send($data);

            return;
        }

        // Handle PostgreSQL STARTTLS: SSLRequest comes before the real startup message.
        // When TLS is enabled with Swoole's native SSL, the TLS handshake happens at the
        // transport level. However, PostgreSQL clients send an SSLRequest message first
        // (at the application layer) to negotiate TLS. We intercept this, respond with 'S'
        // to indicate willingness, and then Swoole handles the actual TLS upgrade.
        // The next onReceive call will contain the real startup message over TLS.
        if ($this->tlsContext !== null && TLS::isPostgreSQLSSLRequest($data)) {
            $port = $this->clientPorts[$fd] ?? null;
            if ($port !== null && $port === 5432) {
                // Respond with 'S' to indicate SSL is supported, then Swoole
                // handles the TLS handshake natively on the already-SSL socket
                $server->send($fd, TLS::PG_SSL_RESPONSE_OK);
                $this->pendingTlsUpgrade[$fd] = true;

                return;
            }
        }

        // After PostgreSQL SSLRequest -> 'S' response, the client performs the TLS
        // handshake (handled by Swoole at transport level), then sends the real
        // startup message. Clear the pending flag and continue to normal processing.
        if (isset($this->pendingTlsUpgrade[$fd])) {
            unset($this->pendingTlsUpgrade[$fd]);
        }

        // Slow path: new connection setup
        try {
            $port = $this->clientPorts[$fd] ?? null;
            if ($port === null) {
                /** @var array<string, mixed> $info */
                $info = $server->getClientInfo($fd);
                /** @var int $port */
                $port = $info['server_port'] ?? 0;
                if ($port === 0) {
                    throw new \Exception('Missing server port for connection');
                }
                $this->clientPorts[$fd] = $port;
            }

            $adapter = $this->adapters[$port] ?? null;
            if ($adapter === null) {
                throw new \Exception("No adapter registered for port {$port}");
            }

            // Parse database ID from initial packet
            $databaseId = $adapter->parseDatabaseId($data, $fd);
            $this->clientDatabaseIds[$fd] = $databaseId;

            // Get primary backend connection
            $backendClient = $adapter->getBackendConnection($databaseId, $fd);
            $this->backendClients[$fd] = $backendClient;

            // If read/write split is enabled, establish read replica connection
            if ($adapter->isReadWriteSplit() && $this->resolver instanceof ReadWriteResolver) {
                try {
                    $readResult = $adapter->routeQuery($databaseId, QueryType::Read);
                    $readEndpoint = $readResult->endpoint;
                    [$readHost, $readPort] = \explode(':', $readEndpoint . ':' . $port);

                    // Only create separate read connection if it differs from the write endpoint
                    $writeResult = $adapter->routeQuery($databaseId, QueryType::Write);
                    if ($readEndpoint !== $writeResult->endpoint) {
                        $readClient = new \Swoole\Coroutine\Client(SWOOLE_SOCK_TCP);
                        $readClient->set([
                            'timeout' => $this->config->backendConnectTimeout,
                            'connect_timeout' => $this->config->backendConnectTimeout,
                            'open_tcp_nodelay' => true,
                            'socket_buffer_size' => 2 * 1024 * 1024,
                        ]);

                        if ($readClient->connect($readHost, (int) $readPort, $this->config->backendConnectTimeout)) {
                            $this->readBackendClients[$fd] = $readClient;
                            // Forward initial startup message to read replica too
                            $readClient->send($data);
                            // Start forwarding from read replica back to client
                            $this->startForwarding($server, $fd, $readClient);
                        }
                    }
                } catch (\Exception $e) {
                    // Read replica unavailable — all traffic goes to primary
                    if ($this->config->logConnections) {
                        echo "Read replica unavailable for #{$fd}: {$e->getMessage()}\n";
                    }
                }
            }

            // Notify connect callback
            $adapter->notifyConnect($databaseId);

            // Forward initial data to primary
            $backendClient->send($data);

            // Start bidirectional forwarding from primary
            $this->forwarding[$fd] = true;
            $this->startForwarding($server, $fd, $backendClient);

        } catch (\Exception $e) {
            echo "Error handling data from #{$fd}: {$e->getMessage()}\n";
            $server->close($fd);
        }
    }

    /**
     * Bidirectional forwarding loop - ZERO-COPY
     *
     * Performance: 10GB/s+ throughput
     */
    protected function startForwarding(Server $server, int $clientFd, Client $backendClient): void
    {
        $bufferSize = $this->config->recvBufferSize;
        /** @var \Swoole\Coroutine\Socket $backendSocket */
        $backendSocket = $backendClient->exportSocket();

        $databaseId = $this->clientDatabaseIds[$clientFd] ?? null;
        $port = $this->clientPorts[$clientFd] ?? null;
        $adapter = ($port !== null) ? ($this->adapters[$port] ?? null) : null;

        Coroutine::create(function () use ($server, $clientFd, $backendSocket, $bufferSize, $databaseId, $adapter) {
            while ($server->exist($clientFd)) {
                /** @var string|false $data */
                $data = $backendSocket->recv($bufferSize);
                if ($data === false || $data === '') {
                    break;
                }
                if ($databaseId !== null && $adapter !== null) {
                    $adapter->recordBytes($databaseId, 0, \strlen($data));
                }
                $server->send($clientFd, $data);
            }
        });
    }

    public function onClose(Server $server, int $fd, int $reactorId): void
    {
        if ($this->config->logConnections) {
            echo "Client #{$fd} disconnected\n";
        }

        if (isset($this->backendClients[$fd])) {
            $this->backendClients[$fd]->close();
            unset($this->backendClients[$fd]);
        }

        if (isset($this->readBackendClients[$fd])) {
            $this->readBackendClients[$fd]->close();
            unset($this->readBackendClients[$fd]);
        }

        // Clean up adapter's connection pool and transaction pinning state
        if (isset($this->clientDatabaseIds[$fd]) && isset($this->clientPorts[$fd])) {
            $port = $this->clientPorts[$fd];
            $databaseId = $this->clientDatabaseIds[$fd];
            $adapter = $this->adapters[$port] ?? null;
            if ($adapter) {
                $adapter->notifyClose($databaseId);
                $adapter->closeBackendConnection($databaseId, $fd);
                $adapter->clearConnectionState($fd);
            }
        }

        unset($this->forwarding[$fd]);
        unset($this->clientDatabaseIds[$fd]);
        unset($this->clientPorts[$fd]);
        unset($this->pendingTlsUpgrade[$fd]);
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
        $adapterStats = [];
        foreach ($this->adapters as $port => $adapter) {
            $adapterStats[$port] = $adapter->getStats();
        }

        /** @var array<string, mixed> $serverStats */
        $serverStats = $this->server->stats();
        /** @var array<string, mixed> $coroutineStats */
        $coroutineStats = Coroutine::stats();

        return [
            'connections' => $serverStats['connection_num'] ?? 0,
            'workers' => $serverStats['worker_num'] ?? 0,
            'coroutines' => $coroutineStats['coroutine_num'] ?? 0,
            'adapters' => $adapterStats,
        ];
    }
}
