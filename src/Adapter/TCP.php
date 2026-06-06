<?php

namespace Utopia\Proxy\Adapter;

use Swoole\Coroutine\Client;
use Utopia\Proxy\Adapter;
use Utopia\Proxy\ConnectionResult;
use Utopia\Proxy\Protocol;
use Utopia\Proxy\Resolver;
use Utopia\Proxy\Sockmap\Loader as Sockmap;

/**
 * TCP Protocol Adapter
 *
 * Routes TCP connections to backend endpoints resolved by the provided Resolver.
 * The resolver receives the raw initial packet data and is responsible for
 * extracting any routing information it needs.
 *
 * Performance (validated on 8-core/32GB):
 * - 670k+ concurrent connections
 *
 * Example:
 * ```php
 * $adapter = new TCP($resolver, port: 5432);
 * ```
 */
class TCP extends Adapter
{
    /** Linux IPPROTO_TCP (sys/socket.h) */
    private const IPPROTO_TCP = 6;

    /** Linux TCP_USER_TIMEOUT (linux/tcp.h) */
    private const TCP_USER_TIMEOUT = 18;

    /** Linux TCP_QUICKACK (linux/tcp.h) */
    private const TCP_QUICKACK = 12;

    /** Linux TCP_NOTSENT_LOWAT (linux/tcp.h) */
    private const TCP_NOTSENT_LOWAT = 25;

    /** @var array<int, Client> */
    protected array $connections = [];

    /** @var array<int, string> */
    protected array $initialData = [];

    /** @var array<int, int> client fd => backend fd, for sockmap pairs handed to the kernel */
    protected array $sockmapPairs = [];

    protected ?\Closure $initialDataTransformer = null;

    protected float $timeout = 30.0;

    protected float $connectTimeout = 5.0;

    protected int $tcpUserTimeoutMs = 0;

    protected bool $tcpQuickAck = false;

    protected int $tcpNotsentLowat = 0;

    protected ?Sockmap $sockmap = null;

    public function __construct(
        public int $port {
            get {
                return $this->port;
            }
        },
        ?Resolver $resolver = null,
    ) {
        parent::__construct($resolver);
    }

    /**
     * Transform the first client packet before it is forwarded to the backend.
     *
     * The callback receives the raw initial packet and route result. This lets
     * protocol-aware callers route by one identifier while forwarding a backend
     * specific startup packet.
     */
    public function onInitialData(callable $callback): static
    {
        $this->initialDataTransformer = $callback(...);

        return $this;
    }

    public function getInitialData(int $fd, string $default): string
    {
        return $this->initialData[$fd] ?? $default;
    }

    public function setTimeout(float $timeout): static
    {
        $this->timeout = $timeout;

        return $this;
    }

    public function setConnectTimeout(float $timeout): static
    {
        $this->connectTimeout = $timeout;

        return $this;
    }

    public function setTcpUserTimeout(int $milliseconds): static
    {
        $this->tcpUserTimeoutMs = $milliseconds;

        return $this;
    }

    public function setTcpQuickAck(bool $enabled): static
    {
        $this->tcpQuickAck = $enabled;

        return $this;
    }

    /**
     * Cap the kernel's unsent-bytes threshold so the reactor reports
     * writability earlier, cutting queue depth and p99 latency under
     * multiplexed streams. Zero disables the option.
     */
    public function setTcpNotsentLowat(int $bytes): static
    {
        $this->tcpNotsentLowat = $bytes;

        return $this;
    }

    /**
     * Attach a BPF sockmap loader. When set, getConnection() will hand
     * the (client fd, backend fd) pair to the kernel after the backend
     * dials, and the kernel forwards data between them with zero userspace
     * involvement. The server code is expected to skip spawning its own
     * forward coroutine when isSockmapActive() returns true for an fd.
     */
    public function setSockmap(?Sockmap $sockmap): static
    {
        $this->sockmap = $sockmap;

        return $this;
    }

    /**
     * Report whether the given client fd has been handed to the kernel
     * via sockmap. Callers can use this to skip any userspace forwarding
     * they would otherwise do — the kernel is already moving the bytes.
     */
    public function isSockmapActive(int $clientFd): bool
    {
        return isset($this->sockmapPairs[$clientFd]);
    }

    /**
     * Get protocol type
     */
    public function getProtocol(): Protocol
    {
        return match ($this->port) {
            5432 => Protocol::PostgreSQL,
            3306 => Protocol::MySQL,
            27017 => Protocol::MongoDB,
            6379 => Protocol::Redis,
            11211 => Protocol::Memcached,
            9092 => Protocol::Kafka,
            5672 => Protocol::AMQP,
            9000 => Protocol::ClickHouse,
            9042 => Protocol::Cassandra,
            4222 => Protocol::NATS,
            1433 => Protocol::MSSQL,
            1521 => Protocol::Oracle,
            9200 => Protocol::Elasticsearch,
            1883 => Protocol::MQTT,
            50051 => Protocol::GRPC,
            2181 => Protocol::ZooKeeper,
            2379 => Protocol::Etcd,
            7687 => Protocol::Neo4j,
            11210 => Protocol::Couchbase,
            26257 => Protocol::CockroachDB,
            4000 => Protocol::TiDB,
            6650 => Protocol::Pulsar,
            21 => Protocol::FTP,
            389 => Protocol::LDAP,
            28015 => Protocol::RethinkDB,
            default => Protocol::TCP,
        };
    }

    /**
     * Get or create backend connection for a client.
     *
     * On first call for a given fd, routes via the resolver and establishes the
     * backend connection. Subsequent calls return the cached connection.
     *
     * @throws \Exception
     */
    public function getConnection(string $data, int $fd): Client
    {
        if (isset($this->connections[$fd])) {
            return $this->connections[$fd];
        }

        $result = $this->route($data);
        $initialData = $this->transformInitialData($data, $result);

        return $this->connect($result, $fd, $initialData);
    }

    /**
     * Establish and cache a backend connection from an already resolved route.
     *
     * Protocol-aware callers can resolve after custom negotiation, then reuse
     * the same backend dialing and socket-option path as getConnection().
     *
     * @throws \Exception
     */
    public function connect(ConnectionResult $result, int $fd, string $initialData = ''): Client
    {
        if (isset($this->connections[$fd])) {
            return $this->connections[$fd];
        }

        [$host, $port] = self::parseEndpoint($result->endpoint, $this->port);

        $client = new Client(SWOOLE_SOCK_TCP);
        $client->set([
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'open_tcp_nodelay' => true,
            'socket_buffer_size' => 256 * 1024,
        ]);

        if (!$client->connect($host, $port, $this->connectTimeout)) {
            throw new \Exception("Failed to connect to backend: {$host}:{$port}");
        }

        $this->applySocketOptions($client);
        $this->connections[$fd] = $client;
        $this->initialData[$fd] = $initialData;

        return $client;
    }

    protected function transformInitialData(string $data, ConnectionResult $result): string
    {
        if ($this->initialDataTransformer === null) {
            return $data;
        }

        $transformed = ($this->initialDataTransformer)($data, $result);
        if (!\is_string($transformed)) {
            throw new \Exception('Initial data transformer must return string');
        }

        return $transformed;
    }

    /**
     * Hand the (client fd, backend fd) pair to the kernel via sockmap.
     *
     * Must be called AFTER the initial handshake packet has been written
     * to the backend via $client->send(). Once the pair is in the map,
     * every sendmsg() on either fd is redirected to the peer by the
     * sk_msg program; a send to the backend after this point would be
     * looped back to the client, so the initial handshake must happen
     * while the backend socket is still in "plain TCP" mode.
     *
     * Returns true if the kernel took ownership of forwarding for this
     * pair; callers should skip any userspace forward loop in that case.
     */
    public function activateSockmap(int $clientFd): bool
    {
        if ($this->sockmap === null || !$this->sockmap->isAvailable()) {
            return false;
        }
        $client = $this->connections[$clientFd] ?? null;
        if ($client === null) {
            return false;
        }
        $backendFd = $this->backendFd($client);
        if ($backendFd <= 0) {
            return false;
        }
        if (!$this->sockmap->insertPair($clientFd, $backendFd)) {
            return false;
        }
        $this->sockmapPairs[$clientFd] = $backendFd;

        return true;
    }

    /**
     * Resolve the raw kernel fd of the backend connection so we can hand
     * it to the sockmap. Returns -1 if unavailable (non-Linux, TLS, etc).
     */
    private function backendFd(Client $client): int
    {
        if (\PHP_OS_FAMILY !== 'Linux') {
            return -1;
        }
        $socket = $client->exportSocket();
        if (!$socket instanceof \Swoole\Coroutine\Socket) {
            return -1;
        }
        $fd = $socket->fd;

        return \is_int($fd) && $fd > 0 ? $fd : -1;
    }

    /**
     * Close backend connection for a client.
     */
    public function closeConnection(int $fd): void
    {
        $client = $this->connections[$fd] ?? null;
        if ($client === null) {
            return;
        }

        // Pull the pair out of the sockmap first so the kernel stops
        // redirecting messages through the (about-to-close) fds.
        if (isset($this->sockmapPairs[$fd])) {
            $this->sockmap?->removePair($fd, $this->sockmapPairs[$fd]);
            unset($this->sockmapPairs[$fd]);
        }

        unset($this->connections[$fd]);
        unset($this->initialData[$fd]);
        $client->close();
    }

    /**
     * Apply TCP_USER_TIMEOUT, TCP_QUICKACK and TCP_NOTSENT_LOWAT on the
     * backend socket. Uses raw Linux kernel constants so the code works
     * regardless of which PHP sockets build-time defines are exposed.
     */
    private function applySocketOptions(Client $client): void
    {
        if ($this->tcpUserTimeoutMs <= 0 && !$this->tcpQuickAck && $this->tcpNotsentLowat <= 0) {
            return;
        }

        if (\PHP_OS_FAMILY !== 'Linux') {
            return;
        }

        $socket = $client->exportSocket();
        if (!$socket instanceof \Swoole\Coroutine\Socket) {
            return;
        }

        if ($this->tcpUserTimeoutMs > 0) {
            @$socket->setOption(self::IPPROTO_TCP, self::TCP_USER_TIMEOUT, $this->tcpUserTimeoutMs);
        }

        if ($this->tcpQuickAck) {
            @$socket->setOption(self::IPPROTO_TCP, self::TCP_QUICKACK, 1);
        }

        if ($this->tcpNotsentLowat > 0) {
            @$socket->setOption(self::IPPROTO_TCP, self::TCP_NOTSENT_LOWAT, $this->tcpNotsentLowat);
        }
    }
}
