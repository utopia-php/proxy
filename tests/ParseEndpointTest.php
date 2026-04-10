<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Adapter;

class ParseEndpointTest extends TestCase
{
    public function testWithPort(): void
    {
        [$host, $port] = Adapter::parseEndpoint('example.com:8080', 80);
        $this->assertSame('example.com', $host);
        $this->assertSame(8080, $port);
    }

    public function testWithoutPort(): void
    {
        [$host, $port] = Adapter::parseEndpoint('example.com', 3306);
        $this->assertSame('example.com', $host);
        $this->assertSame(3306, $port);
    }

    public function testWithEmptyPort(): void
    {
        [$host, $port] = Adapter::parseEndpoint('example.com:', 5432);
        $this->assertSame('example.com', $host);
        $this->assertSame(5432, $port);
    }

    public function testWithIpAndPort(): void
    {
        [$host, $port] = Adapter::parseEndpoint('10.0.0.1:9200', 80);
        $this->assertSame('10.0.0.1', $host);
        $this->assertSame(9200, $port);
    }

    public function testWithIpWithoutPort(): void
    {
        [$host, $port] = Adapter::parseEndpoint('10.0.0.1', 27017);
        $this->assertSame('10.0.0.1', $host);
        $this->assertSame(27017, $port);
    }

    public function testDefaultPortZero(): void
    {
        [$host, $port] = Adapter::parseEndpoint('host.local', 0);
        $this->assertSame('host.local', $host);
        $this->assertSame(0, $port);
    }

    public function testPortOneOverridesDefault(): void
    {
        [$host, $port] = Adapter::parseEndpoint('host.local:1', 8080);
        $this->assertSame('host.local', $host);
        $this->assertSame(1, $port);
    }

    public function testPortZeroExplicit(): void
    {
        [$host, $port] = Adapter::parseEndpoint('host.local:0', 8080);
        $this->assertSame('host.local', $host);
        $this->assertSame(0, $port);
    }

    public function testPort65535(): void
    {
        [$host, $port] = Adapter::parseEndpoint('host.local:65535', 80);
        $this->assertSame('host.local', $host);
        $this->assertSame(65535, $port);
    }

    public function testLargeDefaultPort(): void
    {
        [$host, $port] = Adapter::parseEndpoint('backend', 50051);
        $this->assertSame('backend', $host);
        $this->assertSame(50051, $port);
    }

    public function testLocalhostWithPort(): void
    {
        [$host, $port] = Adapter::parseEndpoint('localhost:3000', 80);
        $this->assertSame('localhost', $host);
        $this->assertSame(3000, $port);
    }

    public function testColonInEndpointLimitedToTwo(): void
    {
        // explode with limit 2 means only first colon splits
        [$host, $port] = Adapter::parseEndpoint('host:abc', 80);
        $this->assertSame('host', $host);
        // 'abc' cast to int is 0
        $this->assertSame(0, $port);
    }
}
