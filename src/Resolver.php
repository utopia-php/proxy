<?php

namespace Utopia\Proxy;

use Utopia\Proxy\Resolver\Exception;
use Utopia\Proxy\Resolver\Result;

/**
 * Backend Resolver Interface
 *
 * Maps protocol-specific input to a backend endpoint. The input varies
 * by protocol: raw TCP packet data, HTTP hostname, SMTP domain, etc.
 * Implement this interface to integrate your platform with the proxy.
 */
interface Resolver
{
    /**
     * Resolve routing input to a backend endpoint
     *
     * @param  string  $data  Protocol-specific routing input (raw packet data, hostname, domain, etc.)
     * @return Result Backend endpoint and metadata
     *
     * @throws Exception If resource not found or unavailable
     */
    public function resolve(string $data): Result;
}
