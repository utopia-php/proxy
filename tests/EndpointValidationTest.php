<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Adapter;
use Utopia\Proxy\Protocol;
use Utopia\Proxy\Resolver\Exception as ResolverException;

class EndpointValidationTest extends TestCase
{
    protected MockResolver $resolver;

    protected function setUp(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to run adapter tests.');
        }

        $this->resolver = new MockResolver();
    }

    private function createAdapter(): Adapter
    {
        return new Adapter($this->resolver, name: 'Test', protocol: Protocol::HTTP);
    }

    public function testRejectsEndpointWithMultipleColons(): void
    {
        $this->resolver->setEndpoint('host:port:extra');
        $adapter = $this->createAdapter();

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('Invalid endpoint format');

        $adapter->route('test');
    }

    public function testRejectsPortAbove65535(): void
    {
        $this->resolver->setEndpoint('example.com:70000');
        $adapter = $this->createAdapter();

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('Invalid port number');

        $adapter->route('test');
    }

    public function testRejectsPortWayAboveLimit(): void
    {
        $this->resolver->setEndpoint('example.com:999999');
        $adapter = $this->createAdapter();

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('Invalid port number');

        $adapter->route('test');
    }

    public function testRejects10Network(): void
    {
        $this->resolver->setEndpoint('10.0.0.1:80');
        $adapter = $this->createAdapter();

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('private/reserved IP');

        $adapter->route('test');
    }

    public function testRejects10NetworkHighEnd(): void
    {
        $this->resolver->setEndpoint('10.255.255.255:80');
        $adapter = $this->createAdapter();

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('private/reserved IP');

        $adapter->route('test');
    }

    public function testRejects172Network(): void
    {
        $this->resolver->setEndpoint('172.16.0.1:80');
        $adapter = $this->createAdapter();

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('private/reserved IP');

        $adapter->route('test');
    }

    public function testRejects172NetworkHighEnd(): void
    {
        $this->resolver->setEndpoint('172.31.255.255:80');
        $adapter = $this->createAdapter();

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('private/reserved IP');

        $adapter->route('test');
    }

    public function testRejects192168Network(): void
    {
        $this->resolver->setEndpoint('192.168.1.1:80');
        $adapter = $this->createAdapter();

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('private/reserved IP');

        $adapter->route('test');
    }

    public function testRejectsLoopbackIp(): void
    {
        $this->resolver->setEndpoint('127.0.0.1:80');
        $adapter = $this->createAdapter();

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('private/reserved IP');

        $adapter->route('test');
    }

    public function testRejectsLoopbackHighEnd(): void
    {
        $this->resolver->setEndpoint('127.255.255.255:80');
        $adapter = $this->createAdapter();

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('private/reserved IP');

        $adapter->route('test');
    }

    public function testRejectsLinkLocal(): void
    {
        $this->resolver->setEndpoint('169.254.1.1:80');
        $adapter = $this->createAdapter();

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('private/reserved IP');

        $adapter->route('test');
    }

    public function testRejectsMulticast(): void
    {
        $this->resolver->setEndpoint('224.0.0.1:80');
        $adapter = $this->createAdapter();

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('private/reserved IP');

        $adapter->route('test');
    }

    public function testRejectsMulticastHighEnd(): void
    {
        $this->resolver->setEndpoint('239.255.255.255:80');
        $adapter = $this->createAdapter();

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('private/reserved IP');

        $adapter->route('test');
    }

    public function testRejectsReservedRange240(): void
    {
        $this->resolver->setEndpoint('240.0.0.1:80');
        $adapter = $this->createAdapter();

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('private/reserved IP');

        $adapter->route('test');
    }

    public function testRejectsZeroNetwork(): void
    {
        $this->resolver->setEndpoint('0.0.0.0:80');
        $adapter = $this->createAdapter();

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('private/reserved IP');

        $adapter->route('test');
    }

    public function testAcceptsPublicIp(): void
    {
        // 8.8.8.8 is Google's public DNS
        $this->resolver->setEndpoint('8.8.8.8:80');
        $adapter = $this->createAdapter();

        $result = $adapter->route('test');
        $this->assertSame('8.8.8.8:80', $result->endpoint);
    }

    public function testAcceptsPublicIpWithoutPort(): void
    {
        $this->resolver->setEndpoint('8.8.8.8');
        $adapter = $this->createAdapter();

        $result = $adapter->route('test');
        $this->assertSame('8.8.8.8', $result->endpoint);
    }

    public function testSkipValidationAllowsPrivateIps(): void
    {
        $this->resolver->setEndpoint('10.0.0.1:80');
        $adapter = $this->createAdapter();
        $adapter->setSkipValidation(true);

        $result = $adapter->route('test');
        $this->assertSame('10.0.0.1:80', $result->endpoint);
    }

    public function testSkipValidationAllowsLoopback(): void
    {
        $this->resolver->setEndpoint('127.0.0.1:80');
        $adapter = $this->createAdapter();
        $adapter->setSkipValidation(true);

        $result = $adapter->route('test');
        $this->assertSame('127.0.0.1:80', $result->endpoint);
    }

    public function testRejectsUnresolvableHostname(): void
    {
        $this->resolver->setEndpoint('this-hostname-definitely-does-not-exist-12345.invalid:80');
        $adapter = $this->createAdapter();

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('Cannot resolve hostname');

        $adapter->route('test');
    }

    public function testAcceptsPort65535(): void
    {
        $this->resolver->setEndpoint('8.8.8.8:65535');
        $adapter = $this->createAdapter();

        $result = $adapter->route('test');
        $this->assertSame('8.8.8.8:65535', $result->endpoint);
    }

    public function testAcceptsPortZeroImplicit(): void
    {
        // No port specified resolves to 0 which is <= 65535
        $this->resolver->setEndpoint('8.8.8.8');
        $adapter = $this->createAdapter();

        $result = $adapter->route('test');
        $this->assertSame('8.8.8.8', $result->endpoint);
    }
}
