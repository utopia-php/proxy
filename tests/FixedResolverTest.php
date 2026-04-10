<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Resolver\Fixed;
use Utopia\Proxy\Resolver\Result;

class FixedResolverTest extends TestCase
{
    public function testResolveReturnsConfiguredEndpoint(): void
    {
        $resolver = new Fixed('backend.db:5432');
        $result = $resolver->resolve('any-input');

        $this->assertInstanceOf(Result::class, $result);
        $this->assertSame('backend.db:5432', $result->endpoint);
    }

    public function testResolveIgnoresInput(): void
    {
        $resolver = new Fixed('static-host:8080');

        $first = $resolver->resolve('input-one');
        $second = $resolver->resolve('input-two');
        $third = $resolver->resolve('');

        $this->assertSame('static-host:8080', $first->endpoint);
        $this->assertSame('static-host:8080', $second->endpoint);
        $this->assertSame('static-host:8080', $third->endpoint);
    }

    public function testResolveReturnsEmptyMetadata(): void
    {
        $resolver = new Fixed('host:80');
        $result = $resolver->resolve('data');

        $this->assertSame([], $result->metadata);
    }

    public function testResolveReturnsNullTimeout(): void
    {
        $resolver = new Fixed('host:80');
        $result = $resolver->resolve('data');

        $this->assertNull($result->timeout);
    }

    public function testResolveWithEmptyEndpoint(): void
    {
        $resolver = new Fixed('');
        $result = $resolver->resolve('data');

        $this->assertSame('', $result->endpoint);
    }

    public function testResolveWithHostOnly(): void
    {
        $resolver = new Fixed('my-backend');
        $result = $resolver->resolve('data');

        $this->assertSame('my-backend', $result->endpoint);
    }

    public function testResolveWithIpAddress(): void
    {
        $resolver = new Fixed('10.0.0.1:3306');
        $result = $resolver->resolve('data');

        $this->assertSame('10.0.0.1:3306', $result->endpoint);
    }

    public function testResolveReturnsFreshResultEachCall(): void
    {
        $resolver = new Fixed('host:80');

        $first = $resolver->resolve('a');
        $second = $resolver->resolve('b');

        // Each call returns a new Result instance (readonly, so identity doesn't matter,
        // but verifying they are independent objects)
        $this->assertNotSame($first, $second);
        $this->assertSame($first->endpoint, $second->endpoint);
    }

    public function testResolverImplementsInterface(): void
    {
        $resolver = new Fixed('host:80');
        $this->assertInstanceOf(\Utopia\Proxy\Resolver::class, $resolver);
    }
}
