<?php

namespace Utopia\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Client;
use Swoole\Coroutine\Server as CoServer;
use Utopia\Proxy\Adapter;
use Utopia\Proxy\Adapter\TCP as TCPAdapter;
use Utopia\Proxy\Protocol;
use Utopia\Proxy\Resolver\Fixed;
use Utopia\Proxy\Server\TCP\Config;

/**
 * Integration tests that exercise the TCP adapter and server construction
 * paths inside a real Swoole coroutine context.
 *
 * @group integration
 */
class TCPServerTest extends TestCase
{
    protected function setUp(): void
    {
        if (! \extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required.');
        }
    }

    public function testTCPAdapterGetConnectionAndClose(): void
    {
        $result = null;
        $error = null;

        Coroutine\run(function () use (&$result, &$error): void {
            // Start a simple echo backend
            $backend = new CoServer('127.0.0.1', 0);
            $backend->set(['open_eof_check' => false]);
            $backendPort = $backend->port;

            Coroutine::create(function () use ($backend): void {
                $backend->handle(function (Coroutine\Server\Connection $connection): void {
                    while (true) {
                        $data = $connection->recv();
                        if (! \is_string($data) || $data === '') {
                            break;
                        }
                        $connection->send($data);
                    }
                    $connection->close();
                });
            });

            // Give the backend a moment to start
            Coroutine::sleep(0.05);

            try {
                $resolver = new Fixed("127.0.0.1:{$backendPort}");
                $adapter = new TCPAdapter(port: 5432, resolver: $resolver);
                $adapter->setSkipValidation(true);
                $adapter->setTimeout(5.0);
                $adapter->setConnectTimeout(2.0);

                // getConnection dials the backend and caches by fd
                $client = $adapter->getConnection('hello', 1);
                $this->assertInstanceOf(Client::class, $client);
                $this->assertTrue($client->isConnected());

                // Same fd returns cached connection
                $cached = $adapter->getConnection('ignored', 1);
                $this->assertSame($client, $cached);

                // Send/recv through the connection
                $client->send('ping');
                $response = $client->recv(1.0);
                $this->assertSame('ping', $response);

                // closeConnection cleans up
                $adapter->closeConnection(1);

                // Closing again is a no-op
                $adapter->closeConnection(1);

                // sockmap should not be active
                $this->assertFalse($adapter->isSockmapActive(1));

                $result = true;
            } catch (\Throwable $e) {
                $error = $e;
            } finally {
                $backend->shutdown();
            }
        });

        if ($error !== null) {
            throw $error;
        }
        $this->assertTrue($result);
    }

    public function testTCPAdapterRouteWithOnResolveCallback(): void
    {
        $result = null;
        $error = null;

        Coroutine\run(function () use (&$result, &$error): void {
            try {
                $adapter = new TCPAdapter(port: 5432);
                $adapter->setSkipValidation(true);

                $adapter->onResolve(function (string $data): string {
                    return '10.0.0.1:5432';
                });

                $routed = $adapter->route('raw-packet-data');
                $this->assertSame('10.0.0.1:5432', $routed->endpoint);
                $this->assertSame(Protocol::PostgreSQL, $routed->protocol);

                $result = true;
            } catch (\Throwable $e) {
                $error = $e;
            }
        });

        if ($error !== null) {
            throw $error;
        }
        $this->assertTrue($result);
    }

    public function testTCPAdapterProtocolDetectionAllPorts(): void
    {
        $result = null;
        $error = null;

        Coroutine\run(function () use (&$result, &$error): void {
            try {
                $portMap = [
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
                    9999 => Protocol::TCP,
                ];

                foreach ($portMap as $port => $expected) {
                    $adapter = new TCPAdapter(port: $port);
                    $this->assertSame($expected, $adapter->getProtocol(), "Port {$port}");
                }

                $result = true;
            } catch (\Throwable $e) {
                $error = $e;
            }
        });

        if ($error !== null) {
            throw $error;
        }
        $this->assertTrue($result);
    }

    public function testTCPConfigDefaults(): void
    {
        $config = new Config(ports: [5432]);

        $this->assertSame('0.0.0.0', $config->host);
        $this->assertSame([5432], $config->ports);
        $this->assertSame(200_000, $config->maxConnections);
        $this->assertGreaterThan(0, $config->workers);
        $this->assertGreaterThan(0, $config->reactorNum);
        $this->assertFalse($config->sockmapEnabled);
        $this->assertSame('', $config->sockmapBpfObject);
        $this->assertFalse($config->isTlsEnabled());
        $this->assertNull($config->getTLSContext());
    }

    public function testTCPConfigMultiplePorts(): void
    {
        $config = new Config(
            ports: [5432, 3306, 27017],
            workers: 4,
            reactorNum: 4,
        );

        $this->assertSame([5432, 3306, 27017], $config->ports);
        $this->assertSame(4, $config->workers);
        $this->assertSame(4, $config->reactorNum);
    }

    public function testTCPAdapterSettersChaining(): void
    {
        $adapter = new TCPAdapter(port: 5432);

        $chain = $adapter
            ->setTimeout(10.0)
            ->setConnectTimeout(2.0)
            ->setTcpUserTimeout(5000)
            ->setTcpQuickAck(true)
            ->setTcpNotsentLowat(16384)
            ->setSkipValidation(true)
            ->setCacheTTL(30);

        $this->assertSame($adapter, $chain);
    }

    public function testAdapterCacheTTLWithRouting(): void
    {
        $result = null;
        $error = null;

        Coroutine\run(function () use (&$result, &$error): void {
            try {
                $resolver = new Fixed('10.0.0.1:5432');
                $adapter = new TCPAdapter(port: 5432, resolver: $resolver);
                $adapter->setSkipValidation(true);
                $adapter->setCacheTTL(60);

                $first = $adapter->route('db-1');
                $this->assertFalse($first->metadata['cached']);

                $second = $adapter->route('db-1');
                $this->assertTrue($second->metadata['cached']);
                $this->assertSame($first->endpoint, $second->endpoint);

                $result = true;
            } catch (\Throwable $e) {
                $error = $e;
            }
        });

        if ($error !== null) {
            throw $error;
        }
        $this->assertTrue($result);
    }
}
