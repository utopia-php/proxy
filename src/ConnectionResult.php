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
        public private(set) string $endpoint,
        public private(set) Protocol $protocol,
        public private(set) array $metadata = []
    ) {
    }
}
