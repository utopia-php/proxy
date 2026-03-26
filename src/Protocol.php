<?php

namespace Utopia\Proxy;

enum Protocol: string
{
    case HTTP = 'http';
    case SMTP = 'smtp';
    case TCP = 'tcp';
    case PostgreSQL = 'postgresql';
    case MySQL = 'mysql';
    case MongoDB = 'mongodb';
    case Redis = 'redis';
    case Memcached = 'memcached';
    case Kafka = 'kafka';
    case AMQP = 'amqp';
    case ClickHouse = 'clickhouse';
    case Cassandra = 'cassandra';
    case NATS = 'nats';
    case MSSQL = 'mssql';
    case Oracle = 'oracle';
    case Elasticsearch = 'elasticsearch';
    case MQTT = 'mqtt';
    case GRPC = 'grpc';
    case ZooKeeper = 'zookeeper';
    case Etcd = 'etcd';
    case Neo4j = 'neo4j';
    case Couchbase = 'couchbase';
    case CockroachDB = 'cockroachdb';
    case TiDB = 'tidb';
    case Pulsar = 'pulsar';
    case FTP = 'ftp';
    case LDAP = 'ldap';
    case RethinkDB = 'rethinkdb';
}
