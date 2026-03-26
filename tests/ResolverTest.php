<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Resolver\Exception as ResolverException;
use Utopia\Proxy\Resolver\Result as ResolverResult;

class ResolverTest extends TestCase
{
    public function testResolverResultStoresValues(): void
    {
        $result = new ResolverResult(
            endpoint: '127.0.0.1:8080',
            metadata: ['cached' => false, 'type' => 'http'],
            timeout: 30
        );

        $this->assertSame('127.0.0.1:8080', $result->endpoint);
        $this->assertSame(['cached' => false, 'type' => 'http'], $result->metadata);
        $this->assertSame(30, $result->timeout);
    }

    public function testResolverResultDefaultValues(): void
    {
        $result = new ResolverResult(endpoint: '127.0.0.1:8080');

        $this->assertSame('127.0.0.1:8080', $result->endpoint);
        $this->assertSame([], $result->metadata);
        $this->assertNull($result->timeout);
    }

    public function testResolverExceptionWithContext(): void
    {
        $exception = new ResolverException(
            'Resource not found',
            ResolverException::NOT_FOUND,
            ['resourceId' => 'abc123', 'type' => 'database']
        );

        $this->assertSame('Resource not found', $exception->getMessage());
        $this->assertSame(404, $exception->getCode());
        $this->assertSame(['resourceId' => 'abc123', 'type' => 'database'], $exception->context);
    }

    public function testResolverExceptionErrorCodes(): void
    {
        $this->assertSame(404, ResolverException::NOT_FOUND);
        $this->assertSame(503, ResolverException::UNAVAILABLE);
        $this->assertSame(504, ResolverException::TIMEOUT);
        $this->assertSame(403, ResolverException::FORBIDDEN);
        $this->assertSame(500, ResolverException::INTERNAL);
    }

    public function testResolverExceptionDefaultCode(): void
    {
        $exception = new ResolverException('Internal error');

        $this->assertSame(500, $exception->getCode());
        $this->assertSame([], $exception->context);
    }
}
