<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Adapter\TCP as TCPAdapter;
use Utopia\Proxy\Protocol;

class TCPAdapterTest extends TestCase
{
    protected MockResolver $resolver;

    protected function setUp(): void
    {
        if (! \extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to run adapter tests.');
        }

        $this->resolver = new MockResolver();
    }

    public function testProtocolDetection(): void
    {
        $postgresql = new TCPAdapter(port: 5432, resolver: $this->resolver);
        $this->assertSame(Protocol::PostgreSQL, $postgresql->getProtocol());

        $mysql = new TCPAdapter(port: 3306, resolver: $this->resolver);
        $this->assertSame(Protocol::MySQL, $mysql->getProtocol());

        $mongodb = new TCPAdapter(port: 27017, resolver: $this->resolver);
        $this->assertSame(Protocol::MongoDB, $mongodb->getProtocol());
    }

    public function testPort(): void
    {
        $adapter = new TCPAdapter(port: 3306, resolver: $this->resolver);
        $this->assertSame(3306, $adapter->port);
    }
}
