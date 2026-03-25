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
    /** @var array<int, Client> */
    protected array $connections = [];

    protected float $timeout = 30.0;

    protected float $connectTimeout = 5.0;

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

    /**
     * Get adapter name
     */
    public function getName(): string
    {
        return 'TCP';
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

        [$host, $port] = \explode(':', $result->endpoint . ':' . $this->port);
        $port = (int) $port;

        $client = new Client(SWOOLE_SOCK_TCP);

        $client->set([
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'open_tcp_nodelay' => true,
            'socket_buffer_size' => 2 * 1024 * 1024,
        ]);

        if (!$client->connect($host, $port, $this->connectTimeout)) {
            throw new \Exception("Failed to connect to backend: {$host}:{$port}");
        }

        $this->connections[$fd] = $client;

        return $client;
    }

    /**
     * Close backend connection for a client
     */
    public function closeConnection(int $fd): void
    {
        if (isset($this->connections[$fd])) {
            $this->connections[$fd]->close();
            unset($this->connections[$fd]);
        }
    }

}
