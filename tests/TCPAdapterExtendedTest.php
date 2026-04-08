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
}
