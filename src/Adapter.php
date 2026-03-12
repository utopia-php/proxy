<?php

namespace Utopia\Proxy;

use Swoole\Table;
use Utopia\Proxy\Resolver\Exception as ResolverException;

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

    /** @var int Activity tracking interval in seconds */
    protected int $activityInterval = 30;

    /** @var array<string, int> Last activity timestamp per resource */
    protected array $lastActivityUpdate = [];

    /** @var array<string, array{inbound: int, outbound: int}> Byte counters per resource since last flush */
    protected array $byteCounters = [];

    public function __construct(
        public Resolver $resolver {
            get {
                return $this->resolver;
            }
        },
        protected string $name = 'Generic',
        protected Protocol $protocol = Protocol::TCP,
        protected string $description = 'Generic proxy adapter',
    ) {
        $this->initRouter();
    }

    /**
     * Set activity tracking interval
     */
    public function setActivityInterval(int $seconds): static
    {
        $this->activityInterval = $seconds;

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

    /**
     * Notify connect event
     *
     * @param  array<string, mixed>  $metadata  Additional connection metadata
     */
    public function notifyConnect(string $resourceId, array $metadata = []): void
    {
        $this->resolver->onConnect($resourceId, $metadata);
    }

    /**
     * Notify close event
     *
     * @param  array<string, mixed>  $metadata  Additional disconnection metadata
     */
    public function notifyClose(string $resourceId, array $metadata = []): void
    {
        // Flush remaining bytes on disconnect
        if (isset($this->byteCounters[$resourceId])) {
            $metadata['inboundBytes'] = $this->byteCounters[$resourceId]['inbound'];
            $metadata['outboundBytes'] = $this->byteCounters[$resourceId]['outbound'];
            unset($this->byteCounters[$resourceId]);
        }

        $this->resolver->onDisconnect($resourceId, $metadata);
        unset($this->lastActivityUpdate[$resourceId]);
    }

    /**
     * Track activity for a resource
     *
     * @param  array<string, mixed>  $metadata  Activity metadata
     */
    /**
     * Record bytes transferred for a resource
     */
    public function recordBytes(
        string $resourceId,
        int $inbound = 0,
        int $outbound = 0,
    ): void {
        if (!isset($this->byteCounters[$resourceId])) {
            $this->byteCounters[$resourceId] = ['inbound' => 0, 'outbound' => 0];
        }

        $this->byteCounters[$resourceId]['inbound'] += $inbound;
        $this->byteCounters[$resourceId]['outbound'] += $outbound;
    }

    /**
     * @param  array<string, mixed>  $metadata  Activity metadata
     */
    public function track(string $resourceId, array $metadata = []): void
    {
        $now = time();
        $lastUpdate = $this->lastActivityUpdate[$resourceId] ?? 0;

        if (($now - $lastUpdate) < $this->activityInterval) {
            return;
        }

        $this->lastActivityUpdate[$resourceId] = $now;

        // Flush accumulated byte counters into the activity metadata
        if (isset($this->byteCounters[$resourceId])) {
            $metadata['inboundBytes'] = $this->byteCounters[$resourceId]['inbound'];
            $metadata['outboundBytes'] = $this->byteCounters[$resourceId]['outbound'];
            $this->byteCounters[$resourceId] = ['inbound' => 0, 'outbound' => 0];
        }

        $this->resolver->track($resourceId, $metadata);
    }

    /**
     * Get adapter name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get protocol type
     */
    public function getProtocol(): Protocol
    {
        return $this->protocol;
    }

    /**
     * Get adapter description
     */
    public function getDescription(): string
    {
        return $this->description;
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
        $cached = $this->router->get($resourceId);
        $now = \time();

        if ($cached !== false && \is_array($cached)) {
            /** @var array{endpoint: string, updated: int} $cached */
            if (($now - $cached['updated']) < 1) {
                $this->stats['cacheHits']++;
                $this->stats['connections']++;

                return new ConnectionResult(
                    endpoint: $cached['endpoint'],
                    protocol: $this->getProtocol(),
                    metadata: ['cached' => true]
                );
            }
        }

        $this->stats['cacheMisses']++;

        try {
            $result = $this->resolver->resolve($resourceId);
            $endpoint = $result->endpoint;

            if (empty($endpoint)) {
                throw new ResolverException(
                    "Resolver returned empty endpoint for: {$resourceId}",
                    ResolverException::NOT_FOUND
                );
            }

            if (! $this->skipValidation) {
                $this->validateEndpoint($endpoint);
            }

            $this->router->set($resourceId, [
                'endpoint' => $endpoint,
                'updated' => $now,
            ]);

            $this->stats['connections']++;

            return new ConnectionResult(
                endpoint: $endpoint,
                protocol: $this->getProtocol(),
                metadata: \array_merge(['cached' => false], $result->metadata)
            );
        } catch (\Exception $e) {
            $this->stats['routingErrors']++;
            throw $e;
        }
    }

    /**
     * Validate backend endpoint to prevent SSRF attacks
     */
    protected function validateEndpoint(string $endpoint): void
    {
        $parts = \explode(':', $endpoint);
        if (\count($parts) > 2) {
            throw new ResolverException("Invalid endpoint format: {$endpoint}");
        }

        $host = $parts[0];
        $port = isset($parts[1]) ? (int) $parts[1] : 0;

        if ($port > 65535) {
            throw new ResolverException("Invalid port number: {$port}");
        }

        $ip = \gethostbyname($host);
        if ($ip === $host && ! \filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new ResolverException("Cannot resolve hostname: {$host}");
        }

        if (\filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
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
        } elseif (\filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if ($ip === '::1' || \str_starts_with($ip, 'fe80:') || \str_starts_with($ip, 'fc00:') || \str_starts_with($ip, 'fd00:')) {
                throw new ResolverException("Access to private/reserved IPv6 address is forbidden: {$ip}");
            }
        }
    }

    /**
     * Initialize routing cache table
     */
    protected function initRouter(): void
    {
        $this->router = new Table(200_000);
        $this->router->column('endpoint', Table::TYPE_STRING, 256);
        $this->router->column('updated', Table::TYPE_INT, 8);
        $this->router->create();
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
            'adapter' => $this->getName(),
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
            'resolver' => $this->resolver->getStats(),
        ];
    }
}
