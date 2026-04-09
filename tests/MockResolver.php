<?php

namespace Utopia\Tests;

use Utopia\Proxy\Resolver;
use Utopia\Proxy\Resolver\Exception;
use Utopia\Proxy\Resolver\Result;

/**
 * Mock Resolver for testing
 */
class MockResolver implements Resolver
{
    protected ?string $endpoint = null;

    protected ?\Exception $exception = null;

    public function setEndpoint(string $endpoint): self
    {
        $this->endpoint = $endpoint;
        $this->exception = null;

        return $this;
    }

    public function setException(\Exception $exception): self
    {
        $this->exception = $exception;
        $this->endpoint = null;

        return $this;
    }

    public function resolve(string $resourceId): Result
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }

        if ($this->endpoint === null) {
            throw new Exception('No endpoint configured', Exception::NOT_FOUND);
        }

        return new Result(
            endpoint: $this->endpoint,
            metadata: ['resourceId' => $resourceId]
        );
    }
}
