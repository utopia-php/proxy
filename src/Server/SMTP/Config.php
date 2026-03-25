<?php

namespace Utopia\Proxy\Server\SMTP;

class Config
{
    public function __construct(
        public readonly string $host = '0.0.0.0',
        public readonly int $port = 25,
        public readonly int $workers = 16,
        public readonly int $maxConnections = 50_000,
        public readonly int $maxCoroutine = 50_000,
        public readonly int $socketBufferSize = 2 * 1024 * 1024,
        public readonly int $bufferOutputSize = 2 * 1024 * 1024,
        public readonly bool $enableCoroutine = true,
        public readonly int $maxWaitTime = 60,
        public readonly float $timeout = 30.0,
        public readonly float $connectTimeout = 5.0,
        public readonly bool $skipValidation = false,
        public readonly int $cacheTTL = 60,
    ) {
    }
}
