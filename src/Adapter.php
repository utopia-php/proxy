<?php

namespace Utopia\Proxy;

use Swoole\Table;
use Utopia\Proxy\Resolver\Exception as ResolverException;
use Utopia\Validator\IP;
use Utopia\Validator\Range;

/**
 * Protocol Proxy Adapter
 *
 * Base class for protocol-specific proxy implementations.
 * Routes traffic to backends resolved by the provided Resolver.
 */
class Adapter
{
    protected Table $router;

    /** @var array<string, int> Connection pool stats */
    protected array $stats = [
        'connections' => 0,
        'cacheHits' => 0,
        'cacheMisses' => 0,
        'routingErrors' => 0,
    ];

    /** @var bool Skip SSRF validation for trusted backends */
    protected bool $skipValidation = false;

    /** @var int Routing cache TTL in seconds (0 disables caching) */
    protected int $cacheTTL = 0;

    /** @var int Activity tracking interval in seconds */
    protected int $interval = 30;

    /** @var array<string, int> Last activity timestamp per resource */
    protected array $lastActivity = [];

    /** @var array<string, Bytes> */
    protected array $bytes = [];

    /** @var \Closure|null Custom resolve callback, checked before the resolver */
    protected ?\Closure $callback = null;

    public function __construct(
        public ?Resolver $resolver = null {
            get {
                return $this->resolver;
            }
        },
        protected Protocol $protocol = Protocol::TCP,
    ) {
        $this->initRouter();
    }

    /**
     * Set activity tracking interval
     */
    public function setInterval(int $seconds): static
    {
        $this->interval = $seconds;

        return $this;
    }

    /**
     * Set a custom resolve callback that is checked before the resolver
     *
     * The callback receives a resource ID and should return a Resolver\Result.
     */
    public function onResolve(callable $callback): static
    {
        $this->callback = $callback(...);

        return $this;
    }

    /**
     * Skip SSRF validation for trusted backends
     */
    public function setSkipValidation(bool $skip): static
    {
        $this->skipValidation = $skip;

        return $this;
    }

    public function setCacheTTL(int $seconds): static
    {
        $this->cacheTTL = $seconds;

        return $this;
    }

    /**
     * Notify connect event
     *
     * @param  array<string, mixed>  $metadata  Additional connection metadata
     */
    public function notifyConnect(string $resourceId, array $metadata = []): void
    {
        $this->resolver?->onConnect($resourceId, $metadata);
    }

    /**
     * Notify close event
     *
     * @param  array<string, mixed>  $metadata  Additional disconnection metadata
     */
    public function notifyClose(string $resourceId, array $metadata = []): void
    {
        if (isset($this->bytes[$resourceId])) {
            $metadata['inboundBytes'] = $this->bytes[$resourceId]->inbound;
            $metadata['outboundBytes'] = $this->bytes[$resourceId]->outbound;
            unset($this->bytes[$resourceId]);
        }

        $this->resolver?->onDisconnect($resourceId, $metadata);
        unset($this->lastActivity[$resourceId]);
    }

    /**
     * Record bytes transferred for a resource
     */
    public function recordBytes(
        string $resourceId,
        int $inbound = 0,
        int $outbound = 0,
    ): void {
        $entry = $this->bytes[$resourceId] ?? null;
        if ($entry === null) {
            $entry = new Bytes();
            $this->bytes[$resourceId] = $entry;
        }
        $entry->inbound += $inbound;
        $entry->outbound += $outbound;
    }

    /**
     * @param  array<string, mixed>  $metadata  Activity metadata
     */
    public function track(string $resourceId, array $metadata = []): void
    {
        $now = \time();
        $lastUpdate = $this->lastActivity[$resourceId] ?? 0;

        if (($now - $lastUpdate) < $this->interval) {
            return;
        }

        $this->lastActivity[$resourceId] = $now;

        $entry = $this->bytes[$resourceId] ?? null;
        if ($entry !== null) {
            $metadata['inboundBytes'] = $entry->inbound;
            $metadata['outboundBytes'] = $entry->outbound;
            $entry->inbound = 0;
            $entry->outbound = 0;
        }

        $this->resolver?->track($resourceId, $metadata);
    }

    /**
     * Get protocol type
     */
    public function getProtocol(): Protocol
    {
        return $this->protocol;
    }

    /**
     * Route connection to backend
     *
     * @param  string  $resourceId  Protocol-specific identifier
     * @return ConnectionResult Backend endpoint and metadata
     *
     * @throws ResolverException If routing fails
     */
    public function route(string $resourceId): ConnectionResult
    {
        $now = \time();

        if ($this->cacheTTL > 0) {
            $cached = $this->router->get($resourceId);

            if ($cached !== false && \is_array($cached)) {
                /** @var array{endpoint: string, updated: int} $cached */
                if (($now - $cached['updated']) < $this->cacheTTL) {
                    $this->stats['cacheHits']++;
                    $this->stats['connections']++;

                    return new ConnectionResult(
                        endpoint: $cached['endpoint'],
                        protocol: $this->getProtocol(),
                        metadata: ['cached' => true]
                    );
                }
            }
        }

        $this->stats['cacheMisses']++;

        try {
            if ($this->callback !== null) {
                $resolved = ($this->callback)($resourceId);
                if ($resolved instanceof Resolver\Result) {
                    $result = $resolved;
                } elseif (\is_string($resolved)) {
                    $result = new Resolver\Result(endpoint: $resolved);
                } else {
                    throw new ResolverException(
                        'Resolve callback must return Result or string',
                        ResolverException::INTERNAL
                    );
                }
            } elseif ($this->resolver !== null) {
                $result = $this->resolver->resolve($resourceId);
            } else {
                throw new ResolverException(
                    'No resolver or resolve callback configured',
                    ResolverException::NOT_FOUND
                );
            }
            $endpoint = $result->endpoint;

            if ($endpoint === '') {
                throw new ResolverException(
                    "Resolver returned empty endpoint for: {$resourceId}",
                    ResolverException::NOT_FOUND
                );
            }

            if (!$this->skipValidation) {
                $endpoint = $this->validate($endpoint);
            }

            if ($this->cacheTTL > 0) {
                $this->router->set($resourceId, [
                    'endpoint' => $endpoint,
                    'updated' => $now,
                ]);
            }

            $this->stats['connections']++;

            $metadata = $result->metadata;
            $metadata['cached'] = false;

            return new ConnectionResult(
                endpoint: $endpoint,
                protocol: $this->getProtocol(),
                metadata: $metadata,
            );
        } catch (\Exception $e) {
            $this->stats['routingErrors']++;
            throw $e;
        }
    }

    /**
     * Validate backend endpoint to prevent SSRF attacks.
     *
     * Returns the validated endpoint with the hostname replaced by the
     * resolved IP address to prevent DNS rebinding (TOCTOU) attacks.
     *
     * Uses the coroutine-aware DNS resolver, which keeps the reactor
     * responsive under load and caches successful lookups per worker.
     */
    protected function validate(string $endpoint): string
    {
        $parts = \explode(':', $endpoint);
        if (\count($parts) > 2) {
            throw new ResolverException("Invalid endpoint format: {$endpoint}");
        }

        $host = $parts[0];
        $hasPort = isset($parts[1]);
        $port = $hasPort ? (int) $parts[1] : 0;

        if ($hasPort && !(new Range(1, 65535))->isValid($port)) {
            throw new ResolverException("Invalid port number: {$port}");
        }

        $ip = Dns::resolve($host);
        if ($ip === $host && !(new IP())->isValid($ip)) {
            throw new ResolverException("Cannot resolve hostname: {$host}");
        }

        if ((new IP(IP::V4))->isValid($ip)) {
            $longIp = \ip2long($ip);
            if ($longIp === false) {
                throw new ResolverException("Invalid IP address: {$ip}");
            }

            $blockedRanges = [
                ['10.0.0.0', '10.255.255.255'],
                ['172.16.0.0', '172.31.255.255'],
                ['192.168.0.0', '192.168.255.255'],
                ['127.0.0.0', '127.255.255.255'],
                ['169.254.0.0', '169.254.255.255'],
                ['224.0.0.0', '239.255.255.255'],
                ['240.0.0.0', '255.255.255.255'],
                ['0.0.0.0', '0.255.255.255'],
            ];

            foreach ($blockedRanges as [$rangeStart, $rangeEnd]) {
                $rangeStartLong = \ip2long($rangeStart);
                $rangeEndLong = \ip2long($rangeEnd);
                if ($longIp >= $rangeStartLong && $longIp <= $rangeEndLong) {
                    throw new ResolverException("Access to private/reserved IP address is forbidden: {$ip}");
                }
            }
        } elseif ((new IP(IP::V6))->isValid($ip)) {
            if (
                $ip === '::1'
                || \str_starts_with($ip, 'fe80:')
                || \str_starts_with($ip, 'fc00:')
                || \str_starts_with($ip, 'fd00:')
                || \str_starts_with(\strtolower($ip), '::ffff:')
            ) {
                throw new ResolverException("Access to private/reserved IPv6 address is forbidden: {$ip}");
            }
        }

        return $hasPort ? "{$ip}:{$port}" : $ip;
    }

    /**
     * Initialize routing cache table
     */
    protected function initRouter(int $size = 10_000): void
    {
        $this->router = new Table($size);
        $this->router->column('endpoint', Table::TYPE_STRING, 256);
        $this->router->column('updated', Table::TYPE_INT, 8);
        $this->router->create();
    }

    /**
     * Parse an endpoint string into host and port.
     *
     * If the endpoint already contains a port, that port is used.
     * Otherwise the provided default port is used.
     *
     * @return array{0: string, 1: int}
     */
    public static function parseEndpoint(string $endpoint, int $defaultPort): array
    {
        $parts = \explode(':', $endpoint, 2);
        $host = $parts[0];
        $port = isset($parts[1]) && $parts[1] !== '' ? (int) $parts[1] : $defaultPort;

        return [$host, $port];
    }

    /**
     * Get routing and connection stats
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        $totalRequests = $this->stats['cacheHits'] + $this->stats['cacheMisses'];

        return [
            'adapter' => (new \ReflectionClass($this))->getShortName(),
            'protocol' => $this->getProtocol()->value,
            'connections' => $this->stats['connections'],
            'cacheHits' => $this->stats['cacheHits'],
            'cacheMisses' => $this->stats['cacheMisses'],
            'cacheHitRate' => $totalRequests > 0
                ? \round($this->stats['cacheHits'] / $totalRequests * 100, 2)
                : 0,
            'routingErrors' => $this->stats['routingErrors'],
            'routingTableMemory' => $this->router->memorySize,
            'routingTableSize' => $this->router->count(),
            'resolver' => $this->resolver?->getStats() ?? [],
        ];
    }
}
