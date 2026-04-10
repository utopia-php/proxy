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
        return new Adapter($this->resolver, protocol: Protocol::HTTP);
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

    public function testRejectsIpv6Loopback(): void
    {
        $this->resolver->setEndpoint('::1');
        $adapter = $this->createAdapter();

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('private/reserved IPv6');

        $adapter->route('test');
    }

    public function testRejectsIpv6LinkLocal(): void
    {
        $this->resolver->setEndpoint('fe80::1');
        $adapter = $this->createAdapter();

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('private/reserved IPv6');

        $adapter->route('test');
    }

    public function testRejectsIpv6UniqueLocalFc00(): void
    {
        $this->resolver->setEndpoint('fc00::1');
        $adapter = $this->createAdapter();

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('private/reserved IPv6');

        $adapter->route('test');
    }

    public function testRejectsIpv6UniqueLocalFd00(): void
    {
        $this->resolver->setEndpoint('fd00::1');
        $adapter = $this->createAdapter();

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('private/reserved IPv6');

        $adapter->route('test');
    }

    public function testRejectsIpv6MappedIpv4(): void
    {
        $this->resolver->setEndpoint('::ffff:127.0.0.1');
        $adapter = $this->createAdapter();

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('private/reserved IPv6');

        $adapter->route('test');
    }

    public function testRejectsIpv6MappedIpv4UpperCase(): void
    {
        $this->resolver->setEndpoint('::FFFF:10.0.0.1');
        $adapter = $this->createAdapter();

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('private/reserved IPv6');

        $adapter->route('test');
    }

    public function testAcceptsPublicIpv6(): void
    {
        // 2001:4860:4860::8888 is Google's public IPv6 DNS
        $this->resolver->setEndpoint('2001:4860:4860::8888');
        $adapter = $this->createAdapter();

        $result = $adapter->route('test');
        $this->assertSame('2001:4860:4860::8888', $result->endpoint);
    }

    public function testSkipValidationAllowsIpv6Loopback(): void
    {
        $this->resolver->setEndpoint('::1');
        $adapter = $this->createAdapter();
        $adapter->setSkipValidation(true);

        $result = $adapter->route('test');
        $this->assertSame('::1', $result->endpoint);
    }

    public function testSkipValidationAllowsIpv6LinkLocal(): void
    {
        $this->resolver->setEndpoint('fe80::1');
        $adapter = $this->createAdapter();
        $adapter->setSkipValidation(true);

        $result = $adapter->route('test');
        $this->assertSame('fe80::1', $result->endpoint);
    }

    public function testRejectsPortZeroExplicit(): void
    {
        $this->resolver->setEndpoint('8.8.8.8:0');
        $adapter = $this->createAdapter();

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('Invalid port number');

        $adapter->route('test');
    }

    public function testAcceptsPortOne(): void
    {
        $this->resolver->setEndpoint('8.8.8.8:1');
        $adapter = $this->createAdapter();

        $result = $adapter->route('test');
        $this->assertSame('8.8.8.8:1', $result->endpoint);
    }

    public function testRejectsNegativePort(): void
    {
        $this->resolver->setEndpoint('8.8.8.8:-1');
        $adapter = $this->createAdapter();

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('Invalid port number');

        $adapter->route('test');
    }

    public function testRejectsNonNumericPort(): void
    {
        $this->resolver->setEndpoint('8.8.8.8:abc');
        $adapter = $this->createAdapter();

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('Invalid port number');

        $adapter->route('test');
    }

    public function testRejectsEmptyHost(): void
    {
        $this->resolver->setEndpoint(':8080');
        $adapter = $this->createAdapter();

        $this->expectException(ResolverException::class);

        $adapter->route('test');
    }

    public function testValidateReplacesHostnameWithResolvedIp(): void
    {
        // google.com should resolve to a public IP
        $this->resolver->setEndpoint('google.com:80');
        $adapter = $this->createAdapter();

        $result = $adapter->route('test');

        // The endpoint should be an IP:port, not the hostname
        $this->assertMatchesRegularExpression('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}:80$/', $result->endpoint);
        $this->assertStringNotContainsString('google.com', $result->endpoint);
    }
}
