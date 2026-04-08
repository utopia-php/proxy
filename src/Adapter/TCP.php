<?php

namespace Utopia\Proxy\Adapter;

use Swoole\Coroutine\Client;
use Utopia\Proxy\Adapter;
use Utopia\Proxy\Protocol;
use Utopia\Proxy\Resolver;

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

    protected float $timeout = 30.0;

    protected float $connectTimeout = 5.0;

    protected int $tcpUserTimeoutMs = 0;

    protected bool $tcpQuickAck = false;

    protected int $tcpNotsentLowat = 0;

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

        return $client;
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

        unset($this->connections[$fd]);
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
