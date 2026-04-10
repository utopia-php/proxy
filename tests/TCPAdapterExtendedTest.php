<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Adapter\TCP as TCPAdapter;
use Utopia\Proxy\Protocol;

class TCPAdapterExtendedTest extends TestCase
{
    protected MockResolver $resolver;

    protected function setUp(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to run adapter tests.');
        }

        $this->resolver = new MockResolver();
    }

    public function testProtocolForPostgresPort(): void
    {
        $adapter = new TCPAdapter(port: 5432, resolver: $this->resolver);
        $this->assertSame(Protocol::PostgreSQL, $adapter->getProtocol());
    }

    public function testProtocolForMysqlPort(): void
    {
        $adapter = new TCPAdapter(port: 3306, resolver: $this->resolver);
        $this->assertSame(Protocol::MySQL, $adapter->getProtocol());
    }

    public function testProtocolForMongoPort(): void
    {
        $adapter = new TCPAdapter(port: 27017, resolver: $this->resolver);
        $this->assertSame(Protocol::MongoDB, $adapter->getProtocol());
    }

    public function testUnknownPortReturnsTcp(): void
    {
        $adapter = new TCPAdapter(port: 8080, resolver: $this->resolver);
        $this->assertSame(Protocol::TCP, $adapter->getProtocol());
    }

    public function testPortProperty(): void
    {
        $adapter = new TCPAdapter(port: 5432, resolver: $this->resolver);
        $this->assertSame(5432, $adapter->port);
    }

    public function testSetTimeoutReturnsSelf(): void
    {
        $adapter = new TCPAdapter(port: 5432, resolver: $this->resolver);
        $result = $adapter->setTimeout(10.0);
        $this->assertSame($adapter, $result);
    }

    public function testSetConnectTimeoutReturnsSelf(): void
    {
        $adapter = new TCPAdapter(port: 5432, resolver: $this->resolver);
        $result = $adapter->setConnectTimeout(10.0);
        $this->assertSame($adapter, $result);
    }

    public function testSetTcpUserTimeoutReturnsSelf(): void
    {
        $adapter = new TCPAdapter(port: 5432, resolver: $this->resolver);
        $result = $adapter->setTcpUserTimeout(10_000);
        $this->assertSame($adapter, $result);
    }

    public function testSetTcpQuickAckReturnsSelf(): void
    {
        $adapter = new TCPAdapter(port: 5432, resolver: $this->resolver);
        $result = $adapter->setTcpQuickAck(true);
        $this->assertSame($adapter, $result);
    }

    public function testSetTcpNotsentLowatReturnsSelf(): void
    {
        $adapter = new TCPAdapter(port: 5432, resolver: $this->resolver);
        $result = $adapter->setTcpNotsentLowat(16_384);
        $this->assertSame($adapter, $result);
    }

    public function testSetSockmapReturnsSelf(): void
    {
        $adapter = new TCPAdapter(port: 5432, resolver: $this->resolver);
        $result = $adapter->setSockmap(null);
        $this->assertSame($adapter, $result);
    }

    public function testIsSockmapActiveReturnsFalseForUnknownFd(): void
    {
        $adapter = new TCPAdapter(port: 5432, resolver: $this->resolver);
        $this->assertFalse($adapter->isSockmapActive(999));
        $this->assertFalse($adapter->isSockmapActive(0));
        $this->assertFalse($adapter->isSockmapActive(-1));
    }

    public function testCloseConnectionWithNoExistingConnectionIsNoop(): void
    {
        $adapter = new TCPAdapter(port: 5432, resolver: $this->resolver);
        $adapter->closeConnection(999);
        $this->addToAssertionCount(1);
    }

    public function testCloseConnectionWithNonExistentFdIsNoop(): void
    {
        $adapter = new TCPAdapter(port: 3306, resolver: $this->resolver);
        $adapter->closeConnection(0);
        $adapter->closeConnection(-1);
        $adapter->closeConnection(PHP_INT_MAX);
        $this->addToAssertionCount(1);
    }

    public function testProtocolForRedisPort(): void
    {
        $adapter = new TCPAdapter(port: 6379, resolver: $this->resolver);
        $this->assertSame(Protocol::Redis, $adapter->getProtocol());
    }

    public function testProtocolForMemcachedPort(): void
    {
        $adapter = new TCPAdapter(port: 11211, resolver: $this->resolver);
        $this->assertSame(Protocol::Memcached, $adapter->getProtocol());
    }

    public function testProtocolForKafkaPort(): void
    {
        $adapter = new TCPAdapter(port: 9092, resolver: $this->resolver);
        $this->assertSame(Protocol::Kafka, $adapter->getProtocol());
    }

    public function testProtocolForAmqpPort(): void
    {
        $adapter = new TCPAdapter(port: 5672, resolver: $this->resolver);
        $this->assertSame(Protocol::AMQP, $adapter->getProtocol());
    }

    public function testProtocolForClickHousePort(): void
    {
        $adapter = new TCPAdapter(port: 9000, resolver: $this->resolver);
        $this->assertSame(Protocol::ClickHouse, $adapter->getProtocol());
    }

    public function testProtocolForCassandraPort(): void
    {
        $adapter = new TCPAdapter(port: 9042, resolver: $this->resolver);
        $this->assertSame(Protocol::Cassandra, $adapter->getProtocol());
    }

    public function testProtocolForNatsPort(): void
    {
        $adapter = new TCPAdapter(port: 4222, resolver: $this->resolver);
        $this->assertSame(Protocol::NATS, $adapter->getProtocol());
    }

    public function testProtocolForMssqlPort(): void
    {
        $adapter = new TCPAdapter(port: 1433, resolver: $this->resolver);
        $this->assertSame(Protocol::MSSQL, $adapter->getProtocol());
    }

    public function testProtocolForOraclePort(): void
    {
        $adapter = new TCPAdapter(port: 1521, resolver: $this->resolver);
        $this->assertSame(Protocol::Oracle, $adapter->getProtocol());
    }

    public function testProtocolForElasticsearchPort(): void
    {
        $adapter = new TCPAdapter(port: 9200, resolver: $this->resolver);
        $this->assertSame(Protocol::Elasticsearch, $adapter->getProtocol());
    }

    public function testProtocolForMqttPort(): void
    {
        $adapter = new TCPAdapter(port: 1883, resolver: $this->resolver);
        $this->assertSame(Protocol::MQTT, $adapter->getProtocol());
    }

    public function testProtocolForGrpcPort(): void
    {
        $adapter = new TCPAdapter(port: 50051, resolver: $this->resolver);
        $this->assertSame(Protocol::GRPC, $adapter->getProtocol());
    }

    public function testProtocolForZooKeeperPort(): void
    {
        $adapter = new TCPAdapter(port: 2181, resolver: $this->resolver);
        $this->assertSame(Protocol::ZooKeeper, $adapter->getProtocol());
    }

    public function testProtocolForEtcdPort(): void
    {
        $adapter = new TCPAdapter(port: 2379, resolver: $this->resolver);
        $this->assertSame(Protocol::Etcd, $adapter->getProtocol());
    }

    public function testProtocolForNeo4jPort(): void
    {
        $adapter = new TCPAdapter(port: 7687, resolver: $this->resolver);
        $this->assertSame(Protocol::Neo4j, $adapter->getProtocol());
    }

    public function testProtocolForCouchbasePort(): void
    {
        $adapter = new TCPAdapter(port: 11210, resolver: $this->resolver);
        $this->assertSame(Protocol::Couchbase, $adapter->getProtocol());
    }

    public function testProtocolForCockroachDbPort(): void
    {
        $adapter = new TCPAdapter(port: 26257, resolver: $this->resolver);
        $this->assertSame(Protocol::CockroachDB, $adapter->getProtocol());
    }

    public function testProtocolForTiDbPort(): void
    {
        $adapter = new TCPAdapter(port: 4000, resolver: $this->resolver);
        $this->assertSame(Protocol::TiDB, $adapter->getProtocol());
    }

    public function testProtocolForPulsarPort(): void
    {
        $adapter = new TCPAdapter(port: 6650, resolver: $this->resolver);
        $this->assertSame(Protocol::Pulsar, $adapter->getProtocol());
    }

    public function testProtocolForFtpPort(): void
    {
        $adapter = new TCPAdapter(port: 21, resolver: $this->resolver);
        $this->assertSame(Protocol::FTP, $adapter->getProtocol());
    }

    public function testProtocolForLdapPort(): void
    {
        $adapter = new TCPAdapter(port: 389, resolver: $this->resolver);
        $this->assertSame(Protocol::LDAP, $adapter->getProtocol());
    }

    public function testProtocolForRethinkDbPort(): void
    {
        $adapter = new TCPAdapter(port: 28015, resolver: $this->resolver);
        $this->assertSame(Protocol::RethinkDB, $adapter->getProtocol());
    }

    /**
     * @dataProvider allPortProtocolProvider
     */
    public function testAllPortProtocolMappings(int $port, Protocol $expected): void
    {
        $adapter = new TCPAdapter(port: $port, resolver: $this->resolver);
        $this->assertSame($expected, $adapter->getProtocol());
    }

    /**
     * @return array<string, array{int, Protocol}>
     */
    public static function allPortProtocolProvider(): array
    {
        return [
            'PostgreSQL' => [5432, Protocol::PostgreSQL],
            'MySQL' => [3306, Protocol::MySQL],
            'MongoDB' => [27017, Protocol::MongoDB],
            'Redis' => [6379, Protocol::Redis],
            'Memcached' => [11211, Protocol::Memcached],
            'Kafka' => [9092, Protocol::Kafka],
            'AMQP' => [5672, Protocol::AMQP],
            'ClickHouse' => [9000, Protocol::ClickHouse],
            'Cassandra' => [9042, Protocol::Cassandra],
            'NATS' => [4222, Protocol::NATS],
            'MSSQL' => [1433, Protocol::MSSQL],
            'Oracle' => [1521, Protocol::Oracle],
            'Elasticsearch' => [9200, Protocol::Elasticsearch],
            'MQTT' => [1883, Protocol::MQTT],
            'GRPC' => [50051, Protocol::GRPC],
            'ZooKeeper' => [2181, Protocol::ZooKeeper],
            'Etcd' => [2379, Protocol::Etcd],
            'Neo4j' => [7687, Protocol::Neo4j],
            'Couchbase' => [11210, Protocol::Couchbase],
            'CockroachDB' => [26257, Protocol::CockroachDB],
            'TiDB' => [4000, Protocol::TiDB],
            'Pulsar' => [6650, Protocol::Pulsar],
            'FTP' => [21, Protocol::FTP],
            'LDAP' => [389, Protocol::LDAP],
            'RethinkDB' => [28015, Protocol::RethinkDB],
            'unknown low' => [1, Protocol::TCP],
            'unknown high' => [65535, Protocol::TCP],
            'unknown 8080' => [8080, Protocol::TCP],
            'unknown 443' => [443, Protocol::TCP],
            'unknown 80' => [80, Protocol::TCP],
        ];
    }
}
