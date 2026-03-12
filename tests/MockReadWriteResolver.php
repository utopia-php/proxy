<?php

namespace Utopia\Tests;

use Utopia\Proxy\Resolver\Exception;
use Utopia\Proxy\Resolver\ReadWriteResolver;
use Utopia\Proxy\Resolver\Result;

/**
 * Mock ReadWriteResolver for testing read/write split routing
 */
class MockReadWriteResolver extends MockResolver implements ReadWriteResolver
{
    protected ?string $readEndpoint = null;

    protected ?string $writeEndpoint = null;

    /** @var array<int, array{resourceId: string, type: string}> */
    protected array $routeLog = [];

    public function setReadEndpoint(string $endpoint): self
    {
        $this->readEndpoint = $endpoint;

        return $this;
    }

    public function setWriteEndpoint(string $endpoint): self
    {
        $this->writeEndpoint = $endpoint;

        return $this;
    }

    public function resolveRead(string $resourceId): Result
    {
        $this->routeLog[] = ['resourceId' => $resourceId, 'type' => 'read'];

        if ($this->readEndpoint === null) {
            throw new Exception('No read endpoint configured', Exception::NOT_FOUND);
        }

        return new Result(
            endpoint: $this->readEndpoint,
            metadata: ['resourceId' => $resourceId, 'route' => 'read']
        );
    }

    public function resolveWrite(string $resourceId): Result
    {
        $this->routeLog[] = ['resourceId' => $resourceId, 'type' => 'write'];

        if ($this->writeEndpoint === null) {
            throw new Exception('No write endpoint configured', Exception::NOT_FOUND);
        }

        return new Result(
            endpoint: $this->writeEndpoint,
            metadata: ['resourceId' => $resourceId, 'route' => 'write']
        );
    }

    /**
     * @return array<int, array{resourceId: string, type: string}>
     */
    public function getRouteLog(): array
    {
        return $this->routeLog;
    }

    public function reset(): void
    {
        parent::reset();
        $this->routeLog = [];
    }
}
