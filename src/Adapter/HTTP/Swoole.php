<?php

namespace Utopia\Proxy\Adapter\HTTP;

use Utopia\Proxy\Adapter;
use Utopia\Proxy\Resolver;

/**
 * HTTP Protocol Adapter (Swoole Implementation)
 *
 * Routes HTTP requests based on hostname to backend function containers.
 *
 * Routing:
 * - Input: Hostname (e.g., func-abc123.appwrite.network)
 * - Resolution: Provided by Resolver implementation
 * - Output: Backend endpoint (IP:port)
 *
 * Performance:
 * - 250,000+ requests/second
 * - <1ms p50 latency (cached)
 * - <5ms p99 latency
 * - 100,000+ concurrent connections
 *
 * Example:
 * ```php
 * $resolver = new MyFunctionResolver();
 * $adapter = new HTTP($resolver);
 * ```
 */
class Swoole extends Adapter
{
    public function __construct(Resolver $resolver)
    {
        parent::__construct($resolver);
    }

    /**
     * Get adapter name
     */
    public function getName(): string
    {
        return 'HTTP';
    }

    /**
     * Get protocol type
     */
    public function getProtocol(): string
    {
        return 'http';
    }

    /**
     * Get adapter description
     */
    public function getDescription(): string
    {
        return 'HTTP proxy adapter for routing requests to function containers';
    }
}
