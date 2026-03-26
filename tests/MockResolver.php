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

    /** @var array<int, array{resourceId: string, metadata: array<string, mixed>}> */
    protected array $connects = [];

    /** @var array<int, array{resourceId: string, metadata: array<string, mixed>}> */
    protected array $disconnects = [];

    /** @var array<int, array{resourceId: string, metadata: array<string, mixed>}> */
    protected array $activities = [];

    /** @var array<int, string> */
    protected array $invalidations = [];

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

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function onConnect(string $resourceId, array $metadata = []): void
    {
        $this->connects[] = ['resourceId' => $resourceId, 'metadata' => $metadata];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function onDisconnect(string $resourceId, array $metadata = []): void
    {
        $this->disconnects[] = ['resourceId' => $resourceId, 'metadata' => $metadata];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function track(string $resourceId, array $metadata = []): void
    {
        $this->activities[] = ['resourceId' => $resourceId, 'metadata' => $metadata];
    }

    public function purge(string $resourceId): void
    {
        $this->invalidations[] = $resourceId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return [
            'resolver' => 'mock',
            'connects' => count($this->connects),
            'disconnects' => count($this->disconnects),
            'activities' => count($this->activities),
        ];
    }

    /**
     * @return array<int, array{resourceId: string, metadata: array<string, mixed>}>
     */
    public function getConnects(): array
    {
        return $this->connects;
    }

    /**
     * @return array<int, array{resourceId: string, metadata: array<string, mixed>}>
     */
    public function getDisconnects(): array
    {
        return $this->disconnects;
    }

    /**
     * @return array<int, array{resourceId: string, metadata: array<string, mixed>}>
     */
    public function getActivities(): array
    {
        return $this->activities;
    }

    /**
     * @return array<int, string>
     */
    public function getInvalidations(): array
    {
        return $this->invalidations;
    }

    public function reset(): void
    {
        $this->connects = [];
        $this->disconnects = [];
        $this->activities = [];
        $this->invalidations = [];
    }
}
