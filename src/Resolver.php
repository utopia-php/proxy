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
}
