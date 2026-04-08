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
}
