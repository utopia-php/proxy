<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Adapter;
use Utopia\Proxy\Adapter\TCP as TCPAdapter;
use Utopia\Proxy\Protocol;

class AdapterMetadataTest extends TestCase
{
    protected MockResolver $resolver;

    protected function setUp(): void
    {
        if (! \extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to run adapter tests.');
        }

        $this->resolver = new MockResolver();
    }

    public function testHttpAdapterMetadata(): void
    {
        $adapter = new Adapter($this->resolver, protocol: Protocol::HTTP);

        $this->assertSame(Protocol::HTTP, $adapter->getProtocol());
    }

    public function testSmtpAdapterMetadata(): void
    {
        $adapter = new Adapter($this->resolver, protocol: Protocol::SMTP);

        $this->assertSame(Protocol::SMTP, $adapter->getProtocol());
    }

    public function testTcpAdapterMetadata(): void
    {
        $adapter = new TCPAdapter(port: 5432, resolver: $this->resolver);

        $this->assertSame(Protocol::PostgreSQL, $adapter->getProtocol());
        $this->assertSame(5432, $adapter->port);
    }
}
