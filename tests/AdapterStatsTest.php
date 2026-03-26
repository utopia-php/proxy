<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Adapter;
use Utopia\Proxy\Protocol;
use Utopia\Proxy\Resolver\Exception as ResolverException;

class AdapterStatsTest extends TestCase
{
    protected MockResolver $resolver;

    protected function setUp(): void
    {
        if (! \extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to run adapter tests.');
        }

        $this->resolver = new MockResolver();
    }

    public function testCacheHitUpdatesStats(): void
    {
        $this->resolver->setEndpoint('127.0.0.1:8080');
        $adapter = new Adapter($this->resolver, protocol: Protocol::HTTP);
        $adapter->setSkipValidation(true);
        $adapter->setCacheTTL(60);

        $start = time();
        while (time() === $start) {
            usleep(1000);
        }

        $first = $adapter->route('api.example.com');
        $second = $adapter->route('api.example.com');

        $this->assertFalse($first->metadata['cached']);
        $this->assertTrue($second->metadata['cached']);

        $stats = $adapter->getStats();
        $this->assertSame(2, $stats['connections']);
        $this->assertSame(1, $stats['cacheHits']);
        $this->assertSame(1, $stats['cacheMisses']);
        $this->assertSame(50.0, $stats['cacheHitRate']);
        $this->assertSame(0, $stats['routingErrors']);
        $this->assertSame(1, $stats['routingTableSize']);
        $this->assertGreaterThan(0, $stats['routingTableMemory']);
    }

    public function testRoutingErrorIncrementsStats(): void
    {
        $this->resolver->setException(new ResolverException('No backend'));
        $adapter = new Adapter($this->resolver, protocol: Protocol::HTTP);

        try {
            $adapter->route('api.example.com');
            $this->fail('Expected routing error was not thrown.');
        } catch (ResolverException $e) {
            $this->assertSame('No backend', $e->getMessage());
        }

        $stats = $adapter->getStats();
        $this->assertSame(1, $stats['routingErrors']);
        $this->assertSame(1, $stats['cacheMisses']);
        $this->assertSame(0, $stats['cacheHits']);
        $this->assertSame(0.0, $stats['cacheHitRate']);
    }

    public function testResolverStatsAreIncludedInAdapterStats(): void
    {
        $this->resolver->setEndpoint('127.0.0.1:8080');
        $adapter = new Adapter($this->resolver, protocol: Protocol::HTTP);
        $adapter->setSkipValidation(true);

        $adapter->route('api.example.com');

        $stats = $adapter->getStats();
        $this->assertArrayHasKey('resolver', $stats);
        $this->assertIsArray($stats['resolver']);
        $this->assertSame('mock', $stats['resolver']['resolver']);
    }
}
