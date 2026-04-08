<?php

namespace Utopia\Proxy\Server\TCP;

class Config
{
    public readonly int $reactorNum;

    public readonly int $workers;

    public readonly int $maxCoroutine;

    /**
     * @param  array<int, int>  $ports
     */
    public function __construct(
        public readonly array $ports,
        public readonly string $host = '0.0.0.0',
        ?int $workers = null,
        public readonly int $maxConnections = 200_000,
        ?int $maxCoroutine = null,
        public readonly int $socketBufferSize = 1 * 1024 * 1024,
        public readonly int $bufferOutputSize = 1 * 1024 * 1024,
        ?int $reactorNum = null,
        public readonly int $serverMode = SWOOLE_BASE,
        public readonly bool $enableReusePort = true,
        public readonly int $backlog = 65535,
        public readonly int $packageMaxLength = 32 * 1024 * 1024,
        public readonly int $tcpKeepidle = 30,
        public readonly int $tcpKeepinterval = 10,
        public readonly int $tcpKeepcount = 3,
        public readonly int $tcpUserTimeoutMs = 10_000,
        public readonly bool $tcpQuickAck = true,
        public readonly bool $enableCoroutine = true,
        public readonly int $maxWaitTime = 60,
        public readonly int $logLevel = SWOOLE_LOG_ERROR,
        public readonly bool $logConnections = false,
        public readonly int $receiveBufferSize = 65_536,
        public readonly int $coroutineStackSize = 262_144,
        public readonly int $gcIntervalMs = 5_000,
        public readonly int $dnsCacheTtl = 60,
        public readonly int $tcpNotsentLowat = 16_384,
        public readonly float $timeout = 30.0,
        public readonly float $connectTimeout = 5.0,
        public readonly bool $skipValidation = false,
        public readonly int $cacheTTL = 0,
        public readonly ?TLS $tls = null,
        public readonly ?\Closure $adapterFactory = null,
    ) {
        $cpus = \swoole_cpu_num();
        $this->workers = $workers ?? $cpus;
        $this->reactorNum = $reactorNum ?? $cpus;
        $this->maxCoroutine = $maxCoroutine ?? ($maxConnections * 2);
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
