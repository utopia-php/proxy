<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Adapter;
use Utopia\Proxy\ConnectionResult;
use Utopia\Proxy\Protocol;
use Utopia\Proxy\Resolver\Exception as ResolverException;
use Utopia\Proxy\Resolver\Result as ResolverResult;

class OnResolveCallbackTest extends TestCase
{
    protected MockResolver $resolver;

    protected function setUp(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to run adapter tests.');
        }

        $this->resolver = new MockResolver();
    }

    /**
     * Test that onResolve() sets the callback and returns the adapter for chaining
     */
    public function testOnResolveSetsCallback(): void
    {
        $adapter = new Adapter($this->resolver, protocol: Protocol::HTTP);

        $result = $adapter->onResolve(function (string $resourceId) {
            return '1.2.3.4:8080';
        });

        $this->assertSame($adapter, $result);
    }

    /**
     * Test that route() uses the callback when set, bypassing the resolver
     */
    public function testRouteUsesCallbackWhenSet(): void
    {
        $this->resolver->setEndpoint('should-not-be-used.example.com:8080');

        $adapter = new Adapter($this->resolver, protocol: Protocol::HTTP);
        $adapter->setSkipValidation(true);
        $adapter->onResolve(function (string $resourceId): string {
            return 'callback-host.example.com:9090';
        });

        $result = $adapter->route('test-resource');

        $this->assertInstanceOf(ConnectionResult::class, $result);
        $this->assertSame('callback-host.example.com:9090', $result->endpoint);
    }

    /**
     * Test that route() falls back to resolver when callback is null
     */
    public function testRouteFallsBackToResolverWhenCallbackIsNull(): void
    {
        $this->resolver->setEndpoint('resolver-host.example.com:8080');

        $adapter = new Adapter($this->resolver, protocol: Protocol::HTTP);
        $adapter->setSkipValidation(true);

        $result = $adapter->route('test-resource');

        $this->assertSame('resolver-host.example.com:8080', $result->endpoint);
    }

    /**
     * Test that callback can return a string endpoint
     */
    public function testCallbackReturnsStringEndpoint(): void
    {
        $adapter = new Adapter($this->resolver, protocol: Protocol::HTTP);
        $adapter->setSkipValidation(true);
        $adapter->onResolve(function (string $resourceId): string {
            return 'string-endpoint.example.com:5432';
        });

        $result = $adapter->route('my-db');

        $this->assertSame('string-endpoint.example.com:5432', $result->endpoint);
        $this->assertFalse($result->metadata['cached']);
    }

    /**
     * Test that callback can return a Result object
     */
    public function testCallbackReturnsResultObject(): void
    {
        $adapter = new Adapter($this->resolver, protocol: Protocol::HTTP);
        $adapter->setSkipValidation(true);
        $adapter->onResolve(function (string $resourceId): ResolverResult {
            return new ResolverResult(
                endpoint: 'result-endpoint.example.com:3306',
                metadata: ['custom' => 'metadata', 'resourceId' => $resourceId],
            );
        });

        $result = $adapter->route('my-db');

        $this->assertSame('result-endpoint.example.com:3306', $result->endpoint);
        $this->assertSame('metadata', $result->metadata['custom']);
        $this->assertSame('my-db', $result->metadata['resourceId']);
        $this->assertFalse($result->metadata['cached']);
    }

    /**
     * Test that callback receives the correct resource ID
     */
    public function testCallbackReceivesResourceId(): void
    {
        $receivedIds = [];

        $adapter = new Adapter($this->resolver, protocol: Protocol::HTTP);
        $adapter->setSkipValidation(true);
        $adapter->onResolve(function (string $resourceId) use (&$receivedIds): string {
            $receivedIds[] = $resourceId;
            return 'host.example.com:8080';
        });

        $adapter->route('resource-alpha');
        // Wait for cache to expire
        $start = time();
        while (time() === $start) {
            usleep(1000);
        }
        $adapter->route('resource-beta');

        $this->assertContains('resource-alpha', $receivedIds);
        $this->assertContains('resource-beta', $receivedIds);
    }

    /**
     * Test that route() throws when neither callback nor resolver is set
     */
    public function testRouteThrowsWhenNoCallbackOrResolver(): void
    {
        $adapter = new Adapter(null, protocol: Protocol::HTTP);
        $adapter->setSkipValidation(true);

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('No resolver or resolve callback configured');

        $adapter->route('test-resource');
    }

    /**
     * Test that callback takes priority over resolver
     */
    public function testCallbackTakesPriorityOverResolver(): void
    {
        $resolverCalled = false;

        $mockResolver = new class () extends MockResolver {
            public bool $wasCalled = false;

            public function __construct()
            {
                parent::setEndpoint('resolver.example.com:8080');
            }

            public function resolve(string $resourceId): \Utopia\Proxy\Resolver\Result
            {
                $this->wasCalled = true;
                return parent::resolve($resourceId);
            }
        };

        $adapter = new Adapter($mockResolver, protocol: Protocol::HTTP);
        $adapter->setSkipValidation(true);
        $adapter->onResolve(function (string $resourceId): string {
            return 'callback.example.com:8080';
        });

        $result = $adapter->route('test-resource');

        $this->assertSame('callback.example.com:8080', $result->endpoint);
        $this->assertFalse($mockResolver->wasCalled);
    }

    /**
     * Test that result from callback with string gets wrapped in default metadata
     */
    public function testStringCallbackResultHasDefaultMetadata(): void
    {
        $adapter = new Adapter($this->resolver, protocol: Protocol::HTTP);
        $adapter->setSkipValidation(true);
        $adapter->onResolve(function (string $resourceId): string {
            return 'host.example.com:8080';
        });

        $result = $adapter->route('test-resource');

        $this->assertArrayHasKey('cached', $result->metadata);
        $this->assertFalse($result->metadata['cached']);
    }

    /**
     * Test that Result metadata from callback is merged into connection result
     */
    public function testResultObjectMetadataIsMerged(): void
    {
        $adapter = new Adapter($this->resolver, protocol: Protocol::HTTP);
        $adapter->setSkipValidation(true);
        $adapter->onResolve(function (string $resourceId): ResolverResult {
            return new ResolverResult(
                endpoint: 'host.example.com:8080',
                metadata: ['region' => 'us-east-1', 'tier' => 'premium'],
            );
        });

        $result = $adapter->route('test-resource');

        $this->assertSame('us-east-1', $result->metadata['region']);
        $this->assertSame('premium', $result->metadata['tier']);
        $this->assertFalse($result->metadata['cached']);
    }
}
