<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Protocol;

class ProtocolTest extends TestCase
{
    public function testAllProtocolValues(): void
    {
        $this->assertSame('http', Protocol::HTTP->value);
        $this->assertSame('smtp', Protocol::SMTP->value);
        $this->assertSame('tcp', Protocol::TCP->value);
        $this->assertSame('postgresql', Protocol::PostgreSQL->value);
        $this->assertSame('mysql', Protocol::MySQL->value);
        $this->assertSame('mongodb', Protocol::MongoDB->value);
        $this->assertSame('redis', Protocol::Redis->value);
        $this->assertSame('memcached', Protocol::Memcached->value);
        $this->assertSame('kafka', Protocol::Kafka->value);
        $this->assertSame('amqp', Protocol::AMQP->value);
        $this->assertSame('clickhouse', Protocol::ClickHouse->value);
        $this->assertSame('cassandra', Protocol::Cassandra->value);
        $this->assertSame('nats', Protocol::NATS->value);
        $this->assertSame('mssql', Protocol::MSSQL->value);
        $this->assertSame('oracle', Protocol::Oracle->value);
        $this->assertSame('elasticsearch', Protocol::Elasticsearch->value);
        $this->assertSame('mqtt', Protocol::MQTT->value);
        $this->assertSame('grpc', Protocol::GRPC->value);
        $this->assertSame('zookeeper', Protocol::ZooKeeper->value);
        $this->assertSame('etcd', Protocol::Etcd->value);
        $this->assertSame('neo4j', Protocol::Neo4j->value);
        $this->assertSame('couchbase', Protocol::Couchbase->value);
        $this->assertSame('cockroachdb', Protocol::CockroachDB->value);
        $this->assertSame('tidb', Protocol::TiDB->value);
        $this->assertSame('pulsar', Protocol::Pulsar->value);
        $this->assertSame('ftp', Protocol::FTP->value);
        $this->assertSame('ldap', Protocol::LDAP->value);
        $this->assertSame('rethinkdb', Protocol::RethinkDB->value);
    }

    public function testProtocolCount(): void
    {
        $cases = Protocol::cases();
        $this->assertCount(28, $cases);
    }

    public function testProtocolFromValue(): void
    {
        $this->assertSame(Protocol::HTTP, Protocol::from('http'));
        $this->assertSame(Protocol::SMTP, Protocol::from('smtp'));
        $this->assertSame(Protocol::TCP, Protocol::from('tcp'));
        $this->assertSame(Protocol::PostgreSQL, Protocol::from('postgresql'));
        $this->assertSame(Protocol::MySQL, Protocol::from('mysql'));
        $this->assertSame(Protocol::MongoDB, Protocol::from('mongodb'));
        $this->assertSame(Protocol::Redis, Protocol::from('redis'));
        $this->assertSame(Protocol::Memcached, Protocol::from('memcached'));
        $this->assertSame(Protocol::Kafka, Protocol::from('kafka'));
        $this->assertSame(Protocol::AMQP, Protocol::from('amqp'));
        $this->assertSame(Protocol::ClickHouse, Protocol::from('clickhouse'));
        $this->assertSame(Protocol::Cassandra, Protocol::from('cassandra'));
        $this->assertSame(Protocol::NATS, Protocol::from('nats'));
        $this->assertSame(Protocol::MSSQL, Protocol::from('mssql'));
        $this->assertSame(Protocol::Oracle, Protocol::from('oracle'));
        $this->assertSame(Protocol::Elasticsearch, Protocol::from('elasticsearch'));
        $this->assertSame(Protocol::MQTT, Protocol::from('mqtt'));
        $this->assertSame(Protocol::GRPC, Protocol::from('grpc'));
        $this->assertSame(Protocol::ZooKeeper, Protocol::from('zookeeper'));
        $this->assertSame(Protocol::Etcd, Protocol::from('etcd'));
        $this->assertSame(Protocol::Neo4j, Protocol::from('neo4j'));
        $this->assertSame(Protocol::Couchbase, Protocol::from('couchbase'));
        $this->assertSame(Protocol::CockroachDB, Protocol::from('cockroachdb'));
        $this->assertSame(Protocol::TiDB, Protocol::from('tidb'));
        $this->assertSame(Protocol::Pulsar, Protocol::from('pulsar'));
        $this->assertSame(Protocol::FTP, Protocol::from('ftp'));
        $this->assertSame(Protocol::LDAP, Protocol::from('ldap'));
        $this->assertSame(Protocol::RethinkDB, Protocol::from('rethinkdb'));
    }

    public function testProtocolTryFromInvalidReturnsNull(): void
    {
        $invalid = Protocol::tryFrom('invalid');
        $empty = Protocol::tryFrom('');
        $uppercase = Protocol::tryFrom('HTTP'); // case-sensitive

        $this->assertSame(null, $invalid);
        $this->assertSame(null, $empty);
        $this->assertSame(null, $uppercase);
    }

    public function testProtocolFromInvalidThrows(): void
    {
        $this->expectException(\ValueError::class);
        Protocol::from('invalid');
    }

    public function testProtocolIsBackedEnum(): void
    {
        $reflection = new \ReflectionEnum(Protocol::class);
        $this->assertTrue($reflection->isBacked());
        $this->assertSame('string', $reflection->getBackingType()->getName());
    }
}
