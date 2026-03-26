<?php

namespace Utopia\Proxy\Resolver;

/**
 * Result of resource resolution
 */
readonly class Result
{
    /**
     * @param  string  $endpoint  Backend endpoint in format "host:port"
     * @param  array<string, mixed>  $metadata  Optional metadata about the resolved backend
     * @param  int|null  $timeout  Optional connection timeout override in seconds
     */
    public function __construct(
        public string $endpoint,
        public array  $metadata = [],
        public ?int   $timeout = null
    ) {
    }
}
