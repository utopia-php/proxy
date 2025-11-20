<?php

namespace Appwrite\ProtocolProxy;

/**
 * Connection routing result
 */
class ConnectionResult
{
    public function __construct(
        public string $endpoint,
        public string $protocol,
        public array $metadata = []
    ) {}
}
