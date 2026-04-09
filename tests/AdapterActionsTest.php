<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Adapter;
use Utopia\Proxy\Adapter\TCP as TCPAdapter;
use Utopia\Proxy\Protocol;
use Utopia\Proxy\Resolver\Exception as ResolverException;

class AdapterActionsTest extends TestCase
{
    protected MockResolver $resolver;

    protected function setUp(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to run adapter tests.');
        }

        $this->resolver = new MockResolver();
    }

    public function testResolverIsAssignedToAdapters(): void
    {
        $http = new Adapter($this->resolver, protocol: Protocol::HTTP);
        $tcp = new TCPAdapter(port: 5432, resolver: $this->resolver);
        $smtp = new Adapter($this->resolver, protocol: Protocol::SMTP);

        $this->assertSame($this->resolver, $http->resolver);
        $this->assertSame($this->resolver, $tcp->resolver);
        $this->assertSame($this->resolver, $smtp->resolver);
    }

    public function testResolveRoutesAndReturnsEndpoint(): void
    {
        $this->resolver->setEndpoint('127.0.0.1:8080');
        $adapter = new Adapter($this->resolver, protocol: Protocol::HTTP);
        $adapter->setSkipValidation(true);

        $result = $adapter->route('api.example.com');

        $this->assertSame('127.0.0.1:8080', $result->endpoint);
        $this->assertSame(Protocol::HTTP, $result->protocol);
    }

    public function testRoutingErrorThrowsException(): void
    {
        $this->resolver->setException(new ResolverException('No backend found'));
        $adapter = new Adapter($this->resolver, protocol: Protocol::HTTP);

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('No backend found');

        $adapter->route('api.example.com');
    }

    public function testEmptyEndpointThrowsException(): void
    {
        $this->resolver->setEndpoint('');
        $adapter = new Adapter($this->resolver, protocol: Protocol::HTTP);

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('Resolver returned empty endpoint');

        $adapter->route('api.example.com');
    }

    public function testSkipValidationAllowsPrivateIPs(): void
    {
        // 10.0.0.1 is a private IP that would normally be blocked
        $this->resolver->setEndpoint('10.0.0.1:8080');
        $adapter = new Adapter($this->resolver, protocol: Protocol::HTTP);
        $adapter->setSkipValidation(true);

        // Should not throw exception with validation disabled
        $result = $adapter->route('api.example.com');
        $this->assertSame('10.0.0.1:8080', $result->endpoint);
    }

    public function testSetSkipValidationReturnsSelf(): void
    {
        $adapter = new Adapter($this->resolver, protocol: Protocol::TCP);

        $result = $adapter->setSkipValidation(true);
        $this->assertSame($adapter, $result);
    }
}
