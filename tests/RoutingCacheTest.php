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

}
