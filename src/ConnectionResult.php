<?php

namespace Utopia\Proxy;

/**
 * Connection routing result
 */
class ConnectionResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $endpoint,
        public string $protocol,
        public array $metadata = []
    ) {
    }
}
