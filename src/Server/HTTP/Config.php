<?php

namespace Utopia\Proxy\Server\HTTP;

class Config
{
    public readonly int $reactorNum;

    public function __construct(
        public readonly string $host = '0.0.0.0',
        public readonly int $port = 80,
        public readonly int $workers = 16,
        public readonly int $maxConnections = 100_000,
        public readonly int $maxCoroutine = 100_000,
        public readonly int $socketBufferSize = 2 * 1024 * 1024,
        public readonly int $bufferOutputSize = 2 * 1024 * 1024,
        public readonly bool $enableCoroutine = true,
        public readonly int $maxWaitTime = 60,
        public readonly int $serverMode = SWOOLE_PROCESS,
        ?int $reactorNum = null,
        public readonly int $dispatchMode = 2,
        public readonly bool $enableReusePort = true,
        public readonly int $backlog = 65535,
        public readonly bool $parsePost = false,
        public readonly bool $parseCookie = false,
        public readonly bool $parseFiles = false,
        public readonly bool $compression = false,
        public readonly int $logLevel = SWOOLE_LOG_ERROR,
        public readonly int $timeout = 30,
        public readonly bool $keepAlive = true,
        public readonly int $poolSize = 1024,
        public readonly float $poolTimeout = 0.001,
        public readonly bool $telemetry = true,
        public readonly bool $fastPath = false,
        public readonly bool $fastPathAssumeOk = false,
        public readonly ?string $fixedBackend = null,
        public readonly ?string $directResponse = null,
        public readonly int $directResponseStatus = 200,
        public readonly int $keepaliveTimeout = 60,
        public readonly bool $httpProtocol = true,
        public readonly bool $http2Protocol = false,
        public readonly int $maxRequest = 0,
        public readonly bool $rawBackend = false,
        public readonly bool $rawBackendAssumeOk = false,
        public readonly bool $skipValidation = false,
        public readonly ?\Closure $requestHandler = null,
        public readonly ?\Closure $workerStart = null,
    ) {
        $this->reactorNum = $reactorNum ?? swoole_cpu_num() * 2;
    }
}
