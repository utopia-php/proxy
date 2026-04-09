<?php

namespace Utopia\Proxy\Resolver;

use Utopia\Proxy\Resolver;

/**
 * Fixed resolver that always returns the same backend endpoint.
 *
 * Used as the default resolver in the Docker image when no custom
 * resolver is mounted.
 */
class Fixed implements Resolver
{
    public function __construct(private readonly string $endpoint)
    {
    }

    public function resolve(string $data): Result
    {
        return new Result(endpoint: $this->endpoint);
    }
}
