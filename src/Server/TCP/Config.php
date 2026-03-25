<?php

namespace Utopia\Proxy\Server\TCP;

class Config
{
    public readonly int $reactorNum;

    /**
     * @param  array<int, int>  $ports
     */
    public function __construct(
        public readonly array $ports,
        public readonly string $host = '0.0.0.0',
        public readonly int $workers = 16,
        public readonly int $maxConnections = 200_000,
        public readonly int $maxCoroutine = 200_000,
        public readonly int $socketBufferSize = 16 * 1024 * 1024,
        public readonly int $bufferOutputSize = 16 * 1024 * 1024,
        ?int $reactorNum = null,
        public readonly int $dispatchMode = 2,
        public readonly bool $enableReusePort = true,
        public readonly int $backlog = 65535,
        public readonly int $packageMaxLength = 32 * 1024 * 1024,
        public readonly int $tcpKeepidle = 30,
        public readonly int $tcpKeepinterval = 10,
        public readonly int $tcpKeepcount = 3,
        public readonly bool $enableCoroutine = true,
        public readonly int $maxWaitTime = 60,
        public readonly int $logLevel = SWOOLE_LOG_ERROR,
        public readonly bool $logConnections = false,
        public readonly int $receiveBufferSize = 131072,
        public readonly float $timeout = 30.0,
        public readonly float $connectTimeout = 5.0,
        public readonly bool $skipValidation = false,
        public readonly int $cacheTTL = 0,
        public readonly ?TLS $tls = null,
        public readonly ?\Closure $adapterFactory = null,
    ) {
        $this->reactorNum = $reactorNum ?? swoole_cpu_num() * 2;
    }

    /**
     * Check if TLS termination is enabled
     */
    public function isTlsEnabled(): bool
    {
        return $this->tls !== null;
    }

    /**
     * Get the TLS context builder, or null if TLS is not configured
     */
    public function getTLSContext(): ?TLSContext
    {
        if ($this->tls === null) {
            return null;
        }

        return new TLSContext($this->tls);
    }
}
