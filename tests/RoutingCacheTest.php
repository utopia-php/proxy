<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Adapter;
use Utopia\Proxy\Protocol;

class RoutingCacheTest extends TestCase
{
    protected MockResolver $resolver;

    protected function setUp(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to run adapter tests.');
        }

        $this->resolver = new MockResolver();
    }

    public function testFirstCallIsCacheMiss(): void
    {
        $this->resolver->setEndpoint('8.8.8.8:80');
        $adapter = new Adapter($this->resolver, protocol: Protocol::HTTP);
        $adapter->setSkipValidation(true);
        $adapter->setCacheTTL(60);

        // Ensure we're at the start of a clean second
        $start = time();
        while (time() === $start) {
            usleep(1000);
        }

        $result = $adapter->route('resource-1');

        $this->assertFalse($result->metadata['cached']);
        $stats = $adapter->getStats();
        $this->assertSame(1, $stats['cacheMisses']);
        $this->assertSame(0, $stats['cacheHits']);
    }

    public function testSecondCallWithinOneSecondIsCacheHit(): void
    {
        $this->resolver->setEndpoint('8.8.8.8:80');
        $adapter = new Adapter($this->resolver, protocol: Protocol::HTTP);
        $adapter->setSkipValidation(true);
        $adapter->setCacheTTL(60);

        $start = time();
        while (time() === $start) {
            usleep(1000);
        }

        $first = $adapter->route('resource-1');
        $second = $adapter->route('resource-1');

        $this->assertFalse($first->metadata['cached']);
        $this->assertTrue($second->metadata['cached']);
    }

    public function testCacheExpiresAfterTtl(): void
    {
        $this->resolver->setEndpoint('8.8.8.8:80');
        $adapter = new Adapter($this->resolver, protocol: Protocol::HTTP);
        $adapter->setSkipValidation(true);
        $adapter->setCacheTTL(1);

        $start = time();
        while (time() === $start) {
            usleep(1000);
        }

        $adapter->route('resource-1');

        sleep(1);

        $result = $adapter->route('resource-1');
        $this->assertFalse($result->metadata['cached']);

        $stats = $adapter->getStats();
        $this->assertSame(2, $stats['cacheMisses']);
    }

    public function testMultipleResourcesCachedIndependently(): void
    {
        $this->resolver->setEndpoint('8.8.8.8:80');
        $adapter = new Adapter($this->resolver, protocol: Protocol::HTTP);
        $adapter->setSkipValidation(true);
        $adapter->setCacheTTL(60);

        $start = time();
        while (time() === $start) {
            usleep(1000);
        }

        $adapter->route('resource-1');
        $adapter->route('resource-2');

        $stats = $adapter->getStats();
        $this->assertSame(2, $stats['cacheMisses']);
        $this->assertSame(0, $stats['cacheHits']);
        $this->assertSame(2, $stats['routingTableSize']);
    }

    public function testCacheHitPreservesProtocol(): void
    {
        $this->resolver->setEndpoint('8.8.8.8:80');
        $adapter = new Adapter($this->resolver, protocol: Protocol::SMTP);
        $adapter->setSkipValidation(true);
        $adapter->setCacheTTL(60);

        $start = time();
        while (time() === $start) {
            usleep(1000);
        }

        $adapter->route('resource-1');
        $cached = $adapter->route('resource-1');

        $this->assertSame(Protocol::SMTP, $cached->protocol);
    }

    public function testCacheHitPreservesEndpoint(): void
    {
        $this->resolver->setEndpoint('8.8.8.8:80');
        $adapter = new Adapter($this->resolver, protocol: Protocol::HTTP);
        $adapter->setSkipValidation(true);
        $adapter->setCacheTTL(60);

        $start = time();
        while (time() === $start) {
            usleep(1000);
        }

        $adapter->route('resource-1');
        $cached = $adapter->route('resource-1');

        $this->assertSame('8.8.8.8:80', $cached->endpoint);
    }

    public function testInitialStatsAreZero(): void
    {
        $adapter = new Adapter($this->resolver, protocol: Protocol::HTTP);

        $stats = $adapter->getStats();

        $this->assertSame(0, $stats['connections']);
        $this->assertSame(0, $stats['cacheHits']);
        $this->assertSame(0, $stats['cacheMisses']);
        $this->assertSame(0, $stats['routingErrors']);
        $this->assertSame(0, $stats['cacheHitRate']);
        $this->assertSame(0, $stats['routingTableSize']);
    }

    public function testStatsContainAdapterInfo(): void
    {
        $adapter = new Adapter($this->resolver, protocol: Protocol::HTTP);

        $stats = $adapter->getStats();

        $this->assertSame('Adapter', $stats['adapter']);
        $this->assertSame('http', $stats['protocol']);
    }

    public function testStatsRoutingTableMemoryIsPositive(): void
    {
        $adapter = new Adapter($this->resolver, protocol: Protocol::HTTP);

        $stats = $adapter->getStats();
        $this->assertGreaterThan(0, $stats['routingTableMemory']);
    }

    public function testCacheHitRateCalculation(): void
    {
        $this->resolver->setEndpoint('8.8.8.8:80');
        $adapter = new Adapter($this->resolver, protocol: Protocol::HTTP);
        $adapter->setSkipValidation(true);
        $adapter->setCacheTTL(60);

        $start = time();
        while (time() === $start) {
            usleep(1000);
        }

        // 1 miss, then 3 hits = 75% hit rate
        $adapter->route('resource-1');
        $adapter->route('resource-1');
        $adapter->route('resource-1');
        $adapter->route('resource-1');

        $stats = $adapter->getStats();
        $this->assertSame(75.0, $stats['cacheHitRate']);
    }

    public function testMultipleErrorsIncrementStats(): void
    {
        $this->resolver->setException(new \Utopia\Proxy\Resolver\Exception('fail'));
        $adapter = new Adapter($this->resolver, protocol: Protocol::HTTP);

        for ($i = 0; $i < 3; $i++) {
            try {
                $adapter->route('resource-1');
            } catch (\Exception $e) {
                // expected
            }
        }

        $stats = $adapter->getStats();
        $this->assertSame(3, $stats['routingErrors']);
        $this->assertSame(3, $stats['cacheMisses']);
    }
}
