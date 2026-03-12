<?php

namespace Utopia\Proxy\Resolver;

use Utopia\Proxy\Resolver;

/**
 * Read/Write Backend Resolver Interface
 *
 * Extends the base Resolver to support read/write split routing.
 * Implementations should return replica endpoints for reads and
 * primary endpoints for writes.
 */
interface ReadWriteResolver extends Resolver
{
    /**
     * Resolve a resource identifier to a read replica endpoint
     *
     * @param  string  $resourceId  Protocol-specific identifier (database ID, hostname, etc.)
     * @return Result Backend endpoint for read operations (replica)
     *
     * @throws Exception If resource not found or unavailable
     */
    public function resolveRead(string $resourceId): Result;

    /**
     * Resolve a resource identifier to a primary/writer endpoint
     *
     * @param  string  $resourceId  Protocol-specific identifier (database ID, hostname, etc.)
     * @return Result Backend endpoint for write operations (primary)
     *
     * @throws Exception If resource not found or unavailable
     */
    public function resolveWrite(string $resourceId): Result;
}
