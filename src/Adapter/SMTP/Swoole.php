<?php

namespace Utopia\Proxy\Adapter\SMTP;

use Utopia\Platform\Service;
use Utopia\Proxy\Adapter;
use Utopia\Proxy\Service\SMTP as SMTPService;

/**
 * SMTP Protocol Adapter (Swoole Implementation)
 *
 * Routes SMTP connections based on email domain to backend email server containers.
 *
 * Routing:
 * - Input: Email domain (e.g., tenant123.appwrite.io)
 * - Resolution: Provided by application via resolve action
 * - Output: Backend endpoint (IP:port)
 *
 * Performance:
 * - 50,000+ messages/second
 * - 50,000+ concurrent connections
 * - <2ms forwarding overhead
 *
 * Example:
 * ```php
 * $adapter = new SMTP();
 * $service = new \Utopia\Proxy\Service\SMTP();
 * $service->addAction('resolve', (new class extends \Utopia\Platform\Action {})
 *     ->callback(fn($domain) => $myBackend->resolve($domain)));
 * $adapter->setService($service);
 * ```
 */
class Swoole extends Adapter
{
    protected function defaultService(): ?Service
    {
        return new SMTPService();
    }

    /**
     * Get adapter name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'SMTP';
    }

    /**
     * Get protocol type
     *
     * @return string
     */
    public function getProtocol(): string
    {
        return 'smtp';
    }

    /**
     * Get adapter description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'SMTP proxy adapter for email server routing';
    }
}
