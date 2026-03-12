<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Resolver\Exception as ResolverException;
use Utopia\Proxy\Resolver\Result as ResolverResult;

class ResolverExtendedTest extends TestCase
{
    public function testResultIsReadonly(): void
    {
        $result = new ResolverResult(endpoint: '127.0.0.1:8080');

        $reflection = new \ReflectionClass($result);
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testResultWithEmptyEndpoint(): void
    {
        $result = new ResolverResult(endpoint: '');
        $this->assertSame('', $result->endpoint);
    }

    public function testResultWithLargeMetadata(): void
    {
        $metadata = [];
        for ($i = 0; $i < 100; $i++) {
            $metadata["key_{$i}"] = "value_{$i}";
        }

        $result = new ResolverResult(endpoint: 'host:80', metadata: $metadata);
        $this->assertCount(100, $result->metadata);
        $this->assertSame('value_50', $result->metadata['key_50']);
    }

    public function testResultWithZeroTimeout(): void
    {
        $result = new ResolverResult(endpoint: 'host:80', timeout: 0);
        $this->assertSame(0, $result->timeout);
    }

    public function testResultWithNegativeTimeout(): void
    {
        $result = new ResolverResult(endpoint: 'host:80', timeout: -1);
        $this->assertSame(-1, $result->timeout);
    }

    public function testExceptionNotFound(): void
    {
        $e = new ResolverException('Not found', ResolverException::NOT_FOUND);
        $this->assertSame(404, $e->getCode());
    }

    public function testExceptionUnavailable(): void
    {
        $e = new ResolverException('Down', ResolverException::UNAVAILABLE);
        $this->assertSame(503, $e->getCode());
    }

    public function testExceptionTimeout(): void
    {
        $e = new ResolverException('Slow', ResolverException::TIMEOUT);
        $this->assertSame(504, $e->getCode());
    }

    public function testExceptionForbidden(): void
    {
        $e = new ResolverException('Denied', ResolverException::FORBIDDEN);
        $this->assertSame(403, $e->getCode());
    }

    public function testExceptionInternal(): void
    {
        $e = new ResolverException('Crash', ResolverException::INTERNAL);
        $this->assertSame(500, $e->getCode());
    }

    public function testExceptionIsInstanceOfBaseException(): void
    {
        $e = new ResolverException('test');
        $this->assertInstanceOf(\Exception::class, $e);
    }

    public function testExceptionContextIsReadonly(): void
    {
        $e = new ResolverException('test', context: ['key' => 'value']);

        $reflection = new \ReflectionProperty($e, 'context');
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testExceptionWithEmptyContext(): void
    {
        $e = new ResolverException('test');
        $this->assertSame([], $e->context);
    }

    public function testExceptionWithRichContext(): void
    {
        $context = [
            'resourceId' => 'db-123',
            'attempt' => 3,
            'lastError' => 'connection refused',
            'timestamps' => [1000, 2000, 3000],
        ];

        $e = new ResolverException('Failed after retries', ResolverException::UNAVAILABLE, $context);

        $this->assertSame('db-123', $e->context['resourceId']);
        $this->assertSame(3, $e->context['attempt']);
        $this->assertSame([1000, 2000, 3000], $e->context['timestamps']);
    }

    public function testMockResolverResolvesEndpoint(): void
    {
        $resolver = new MockResolver();
        $resolver->setEndpoint('backend.db:5432');

        $result = $resolver->resolve('test-resource');

        $this->assertSame('backend.db:5432', $result->endpoint);
        $this->assertSame('test-resource', $result->metadata['resourceId']);
    }

    public function testMockResolverThrowsWhenNoEndpoint(): void
    {
        $resolver = new MockResolver();

        $this->expectException(ResolverException::class);
        $this->expectExceptionCode(404);

        $resolver->resolve('test-resource');
    }

    public function testMockResolverThrowsConfiguredException(): void
    {
        $resolver = new MockResolver();
        $resolver->setException(new ResolverException('custom error', ResolverException::TIMEOUT));

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('custom error');
        $this->expectExceptionCode(504);

        $resolver->resolve('test-resource');
    }

    public function testMockResolverTracksActivities(): void
    {
        $resolver = new MockResolver();

        $resolver->track('resource-1', ['type' => 'query']);
        $resolver->track('resource-2', ['type' => 'heartbeat']);

        $activities = $resolver->getActivities();
        $this->assertCount(2, $activities);
        $this->assertSame('resource-1', $activities[0]['resourceId']);
        $this->assertSame('query', $activities[0]['metadata']['type']);
    }

    public function testMockResolverTracksPurges(): void
    {
        $resolver = new MockResolver();

        $resolver->purge('resource-1');
        $resolver->purge('resource-2');

        $invalidations = $resolver->getInvalidations();
        $this->assertCount(2, $invalidations);
        $this->assertSame('resource-1', $invalidations[0]);
        $this->assertSame('resource-2', $invalidations[1]);
    }

    public function testMockResolverResetClearsEverything(): void
    {
        $resolver = new MockResolver();

        $resolver->setEndpoint('host:80');
        $resolver->resolve('test');
        $resolver->track('test');
        $resolver->purge('test');
        $resolver->onConnect('test');
        $resolver->onDisconnect('test');

        $resolver->reset();

        $this->assertEmpty($resolver->getConnects());
        $this->assertEmpty($resolver->getDisconnects());
        $this->assertEmpty($resolver->getActivities());
        $this->assertEmpty($resolver->getInvalidations());
    }

    public function testMockResolverStats(): void
    {
        $resolver = new MockResolver();

        $resolver->onConnect('r1');
        $resolver->onConnect('r2');
        $resolver->onDisconnect('r1');
        $resolver->track('r2');

        $stats = $resolver->getStats();
        $this->assertSame('mock', $stats['resolver']);
        $this->assertSame(2, $stats['connects']);
        $this->assertSame(1, $stats['disconnects']);
        $this->assertSame(1, $stats['activities']);
    }

    public function testMockReadWriteResolverReadEndpoint(): void
    {
        $resolver = new MockReadWriteResolver();
        $resolver->setReadEndpoint('replica.db:5432');

        $result = $resolver->resolveRead('test-db');

        $this->assertSame('replica.db:5432', $result->endpoint);
        $this->assertSame('read', $result->metadata['route']);
    }

    public function testMockReadWriteResolverWriteEndpoint(): void
    {
        $resolver = new MockReadWriteResolver();
        $resolver->setWriteEndpoint('primary.db:5432');

        $result = $resolver->resolveWrite('test-db');

        $this->assertSame('primary.db:5432', $result->endpoint);
        $this->assertSame('write', $result->metadata['route']);
    }

    public function testMockReadWriteResolverThrowsNoReadEndpoint(): void
    {
        $resolver = new MockReadWriteResolver();

        $this->expectException(ResolverException::class);
        $this->expectExceptionCode(404);

        $resolver->resolveRead('test-db');
    }

    public function testMockReadWriteResolverThrowsNoWriteEndpoint(): void
    {
        $resolver = new MockReadWriteResolver();

        $this->expectException(ResolverException::class);
        $this->expectExceptionCode(404);

        $resolver->resolveWrite('test-db');
    }

    public function testMockReadWriteResolverRouteLog(): void
    {
        $resolver = new MockReadWriteResolver();
        $resolver->setReadEndpoint('replica:5432');
        $resolver->setWriteEndpoint('primary:5432');

        $resolver->resolveRead('db-1');
        $resolver->resolveWrite('db-2');
        $resolver->resolveRead('db-3');

        $log = $resolver->getRouteLog();
        $this->assertCount(3, $log);
        $this->assertSame('read', $log[0]['type']);
        $this->assertSame('write', $log[1]['type']);
        $this->assertSame('read', $log[2]['type']);
    }

    public function testMockReadWriteResolverResetIncludesRouteLog(): void
    {
        $resolver = new MockReadWriteResolver();
        $resolver->setReadEndpoint('replica:5432');
        $resolver->resolveRead('db-1');

        $resolver->reset();

        $this->assertEmpty($resolver->getRouteLog());
    }
}
