<?php

namespace Utopia\Proxy;

use Utopia\Proxy\Resolver\Exception;
use Utopia\Proxy\Resolver\Result;

/**
 * Backend Resolver Interface
 *
 * Platform-agnostic interface for resolving resource identifiers to backend endpoints.
 * Implement this interface to integrate your platform with the proxy.
 */
interface Resolver
{
    /**
     * Resolve a resource identifier to a backend endpoint
     *
     * @param  string  $resourceId  Protocol-specific identifier (hostname, SNI, etc.)
     * @return Result Backend endpoint and metadata
     *
     * @throws Exception If resource not found or unavailable
     */
    public function resolve(string $resourceId): Result;

    /**
     * Track activity for a resource
     *
     * @param  string  $resourceId  The resource identifier
     * @param  array<string, mixed>  $metadata  Activity metadata
     */
    public function track(string $resourceId, array $metadata = []): void;

    /**
     * Invalidate cached resolution data for a resource
     *
     * @param  string  $resourceId  The resource identifier
     */
    public function purge(string $resourceId): void;

    /**
     * Get resolver statistics
     *
     * @return array<string, mixed> Statistics data
     */
    public function getStats(): array;

    /**
     * Called when a new connection is established
     *
     * @param  string  $resourceId  The resource identifier
     * @param  array<string, mixed>  $metadata  Additional connection metadata
     */
    public function onConnect(string $resourceId, array $metadata = []): void;

    /**
     * Called when a connection is closed
     *
     * @param  string  $resourceId  The resource identifier
     * @param  array<string, mixed>  $metadata  Additional disconnection metadata
     */
    public function onDisconnect(string $resourceId, array $metadata = []): void;
}
