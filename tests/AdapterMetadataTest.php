<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Adapter\HTTP\Swoole as HTTPAdapter;
use Utopia\Proxy\Adapter\SMTP\Swoole as SMTPAdapter;
use Utopia\Proxy\Adapter\TCP\Swoole as TCPAdapter;

class AdapterMetadataTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to run adapter tests.');
        }
    }

    public function testHttpAdapterMetadata(): void
    {
        $adapter = new HTTPAdapter();

        $this->assertSame('HTTP', $adapter->getName());
        $this->assertSame('http', $adapter->getProtocol());
        $this->assertSame('HTTP proxy adapter for routing requests to function containers', $adapter->getDescription());
    }

    public function testSmtpAdapterMetadata(): void
    {
        $adapter = new SMTPAdapter();

        $this->assertSame('SMTP', $adapter->getName());
        $this->assertSame('smtp', $adapter->getProtocol());
        $this->assertSame('SMTP proxy adapter for email server routing', $adapter->getDescription());
    }

    public function testTcpAdapterMetadata(): void
    {
        $adapter = new TCPAdapter(port: 5432);

        $this->assertSame('TCP', $adapter->getName());
        $this->assertSame('postgresql', $adapter->getProtocol());
        $this->assertSame('TCP proxy adapter for database connections (PostgreSQL, MySQL)', $adapter->getDescription());
        $this->assertSame(5432, $adapter->getPort());
    }
}
