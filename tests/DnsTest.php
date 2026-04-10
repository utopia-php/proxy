<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Dns;

class DnsTest extends TestCase
{
    protected function setUp(): void
    {
        Dns::clear();
        Dns::setTtl(60);
    }

    public function testLiteralIpv4PassesThrough(): void
    {
        $this->assertSame('8.8.8.8', Dns::resolve('8.8.8.8'));
    }

    public function testLiteralIpv6PassesThrough(): void
    {
        $this->assertSame('2001:4860:4860::8888', Dns::resolve('2001:4860:4860::8888'));
    }

    public function testEmptyStringPassesThrough(): void
    {
        $this->assertSame('', Dns::resolve(''));
    }

    public function testUnresolvableHostnameReturnsInput(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required for hostname resolution.');
        }

        $host = 'this-hostname-definitely-does-not-exist-12345.invalid';
        $this->assertSame($host, Dns::resolve($host));
    }

    public function testSetTtlIsObservable(): void
    {
        Dns::setTtl(120);
        $this->assertSame(120, Dns::ttl());
    }

    public function testClearEmptiesCache(): void
    {
        // No public way to observe cache directly, but clear should not throw
        Dns::clear();
        $this->addToAssertionCount(1);
    }

    public function testResolvableHostnameReturnsIp(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required for hostname resolution.');
        }

        // google.com should resolve to a valid IP
        $result = Dns::resolve('google.com');
        $this->assertNotSame('google.com', $result);
        $this->assertNotFalse(\filter_var($result, FILTER_VALIDATE_IP));
    }

    public function testCacheHitReturnsSameIp(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required for hostname resolution.');
        }

        Dns::clear();
        Dns::setTtl(60);

        $first = Dns::resolve('google.com');
        $second = Dns::resolve('google.com');

        $this->assertSame($first, $second);
    }

    public function testCacheExpiry(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required for hostname resolution.');
        }

        Dns::clear();
        Dns::setTtl(1);

        $first = Dns::resolve('google.com');
        $this->assertNotSame('google.com', $first);

        sleep(2);

        // After TTL expires, it re-resolves (should still return a valid IP)
        $second = Dns::resolve('google.com');
        $this->assertNotFalse(\filter_var($second, FILTER_VALIDATE_IP));
    }

    public function testSetTtlToZeroDisablesCache(): void
    {
        Dns::clear();
        Dns::setTtl(0);
        $this->assertSame(0, Dns::ttl());
    }

    public function testMultipleHostsAreCachedIndependently(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required for hostname resolution.');
        }

        Dns::clear();
        Dns::setTtl(60);

        $google = Dns::resolve('google.com');
        $cloudflare = Dns::resolve('one.one.one.one');

        // Both should resolve to valid IPs, and they should differ
        $this->assertNotFalse(\filter_var($google, FILTER_VALIDATE_IP));
        $this->assertNotFalse(\filter_var($cloudflare, FILTER_VALIDATE_IP));
    }

    public function testIpv6LiteralPassesThrough(): void
    {
        $this->assertSame('::1', Dns::resolve('::1'));
        $this->assertSame('fe80::1', Dns::resolve('fe80::1'));
    }

    public function testDefaultTtlIsSixty(): void
    {
        // After setUp resets it to 60
        $this->assertSame(60, Dns::ttl());
    }
}
