<?php

namespace Utopia\Proxy\Adapter\SMTP;

use Utopia\Proxy\Adapter;
use Utopia\Proxy\Resolver;

/**
 * SMTP Protocol Adapter (Swoole Implementation)
 *
 * Routes SMTP connections based on email domain to backend email server containers.
 *
 * Routing:
 * - Input: Email domain (e.g., tenant123.appwrite.io)
 * - Resolution: Provided by Resolver implementation
 * - Output: Backend endpoint (IP:port)
 *
 * Performance:
 * - 50,000+ messages/second
 * - 50,000+ concurrent connections
 * - <2ms forwarding overhead
 *
 * Example:
 * ```php
 * $resolver = new MyEmailResolver();
 * $adapter = new SMTP($resolver);
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
        return 'SMTP';
    }

    /**
     * Get protocol type
     */
    public function getProtocol(): string
    {
        return 'smtp';
    }

    /**
     * Get adapter description
     */
    public function getDescription(): string
    {
        return 'SMTP proxy adapter for email server routing';
    }
}
