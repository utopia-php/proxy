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

}
