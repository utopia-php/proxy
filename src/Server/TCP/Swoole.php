<?php

namespace Utopia\Proxy\Server\TCP;

use Swoole\Constant;
use Swoole\Coroutine;
use Swoole\Coroutine\Client;
use Swoole\Coroutine\Socket;
use Swoole\Server;
use Utopia\Proxy\Adapter\TCP as TCPAdapter;
use Utopia\Proxy\Resolver;

/**
 * High-performance TCP proxy server (Swoole Implementation)
 *
 * Supports optional TLS termination:
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
 * $tls = new TLS(certificate: '/certs/server.crt', key: '/certs/server.key');
 * $config = new Config(ports: [5432, 3306], tls: $tls);
 * $server = new Swoole($config, $resolver);
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
    protected array $clients = [];

    /** @var array<int, int> */
    protected array $clientPorts = [];

    /**
     * Tracks connections awaiting TLS upgrade (PostgreSQL STARTTLS).
     * After sending 'S' in response to SSLRequest, the connection
     * must complete the TLS handshake before we see the real startup message.
     *
     * @var array<int, bool>
     */
    protected array $pendingTls = [];

    protected ?Resolver $resolver;

    public function __construct(
        Config $config,
        ?Resolver $resolver = null,
    ) {
        $this->resolver = $resolver;
        $this->config = $config;

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

            'open_length_check' => false,
            'package_max_length' => $this->config->packageMaxLength,

            // Enable stats
            'task_enable_coroutine' => true,
        ];

        // Apply TLS settings when enabled
        if ($this->tlsContext !== null) {
            $settings = array_merge($settings, $this->tlsContext->toSwooleConfig());
        }

        $this->server->set($settings);

        $this->server->on(Constant::EVENT_START, $this->onStart(...));
        $this->server->on(Constant::EVENT_WORKER_START, $this->onWorkerStart(...));
        $this->server->on(Constant::EVENT_CONNECT, $this->onConnect(...));
        $this->server->on(Constant::EVENT_RECEIVE, $this->onReceive(...));
        $this->server->on(Constant::EVENT_CLOSE, $this->onClose(...));
    }

    public function onStart(Server $server): void
    {
        echo "TCP Proxy Server started at {$this->config->host}\n";
        echo 'Ports: '.implode(', ', $this->config->ports)."\n";
        echo "Workers: {$this->config->workers}\n";
        echo "Max connections: {$this->config->maxConnections}\n";

        if ($this->config->isTlsEnabled()) {
            echo "TLS: enabled\n";
            if ($this->config->tls?->isMutual()) {
                echo "mTLS: enabled (client certificates required)\n";
            }
        }
    }

    public function onWorkerStart(Server $server, int $workerId): void
    {
        // Initialize TCP adapter per worker per port
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

            $adapter->setTimeout($this->config->timeout);
            $adapter->setConnectTimeout($this->config->connectTimeout);

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
     * Main receive handler
     *
     * When TLS is enabled, handles protocol-specific SSL negotiation:
     * - PostgreSQL: Intercepts SSLRequest, responds 'S', Swoole upgrades to TLS
     * - MySQL: Swoole handles SSL natively via SWOOLE_SSL socket type
     */
    public function onReceive(Server $server, int $fd, int $reactorId, string $data): void
    {
        $fdKey = (string) $fd;

        // Fast path: existing connection - forward to appropriate backend
        if (isset($this->clients[$fd])) {
            $port = $this->clientPorts[$fd] ?? 0;
            $adapter = $this->adapters[$port] ?? null;

            if ($adapter !== null) {
                $adapter->recordBytes($fdKey, \strlen($data), 0);
                $adapter->track($fdKey);
            }

            $this->clients[$fd]->send($data);

            return;
        }

        // Handle PostgreSQL STARTTLS: SSLRequest comes before the real startup message.
        if ($this->tlsContext !== null && TLS::isPostgreSQLSSLRequest($data)) {
            $port = $this->clientPorts[$fd] ?? null;
            if ($port !== null && $port === 5432) {
                $server->send($fd, TLS::PG_SSL_RESPONSE_OK);
                $this->pendingTls[$fd] = true;

                return;
            }
        }

        if (isset($this->pendingTls[$fd])) {
            unset($this->pendingTls[$fd]);
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

            // Route via resolver — the resolver receives raw initial data
            // and is responsible for extracting any routing information
            $backendClient = $adapter->getConnection($data, $fd);
            $this->clients[$fd] = $backendClient;

            $adapter->notifyConnect($fdKey);

            // Forward initial data to primary
            $backendClient->send($data);

            // Start bidirectional forwarding from primary
            $this->forwarding[$fd] = true;
            $this->forward($server, $fd, $backendClient);

        } catch (\Exception $e) {
            echo "Error handling data from #{$fd}: {$e->getMessage()}\n";
            $server->close($fd);
        }
    }

    /**
     * Bidirectional forwarding loop
     */
    protected function forward(Server $server, int $clientFd, Client $backendClient): void
    {
        $bufferSize = $this->config->receiveBufferSize;
        /** @var Socket $backendSocket */
        $backendSocket = $backendClient->exportSocket();

        $fdKey = (string) $clientFd;
        $port = $this->clientPorts[$clientFd] ?? null;
        $adapter = ($port !== null) ? ($this->adapters[$port] ?? null) : null;

        \go(function () use ($server, $clientFd, $backendSocket, $bufferSize, $fdKey, $adapter) {
            while ($server->exist($clientFd)) {
                /** @var string|false $data */
                $data = $backendSocket->recv($bufferSize);
                if ($data === false || $data === '') {
                    break;
                }
                if ($adapter !== null) {
                    $adapter->recordBytes($fdKey, 0, \strlen($data));
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

        if (isset($this->clients[$fd])) {
            $this->clients[$fd]->close();
            unset($this->clients[$fd]);
        }

        if (isset($this->clientPorts[$fd])) {
            $port = $this->clientPorts[$fd];
            $adapter = $this->adapters[$port] ?? null;
            if ($adapter) {
                $adapter->notifyClose((string) $fd);
                $adapter->closeConnection($fd);
            }
        }

        unset($this->forwarding[$fd]);
        unset($this->clientPorts[$fd]);
        unset($this->pendingTls[$fd]);
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
