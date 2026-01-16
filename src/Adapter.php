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
abstract class Adapter
{
    protected Table $routingTable;

    /** @var array<string, int> Connection pool stats */
    protected array $stats = [
        'connections' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'routing_errors' => 0,
    ];

    /** @var bool Skip SSRF validation for trusted backends */
    protected bool $skipValidation = false;

    /** @var int Activity tracking interval in seconds */
    protected int $activityInterval = 30;

    /** @var array<string, int> Last activity timestamp per resource */
    protected array $lastActivityUpdate = [];

    public function __construct(
        protected Resolver $resolver
    ) {
        $this->initRoutingTable();
    }

    /**
     * Get the resolver
     */
    public function getResolver(): Resolver
    {
        return $this->resolver;
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
        $this->resolver->onDisconnect($resourceId, $metadata);
        unset($this->lastActivityUpdate[$resourceId]);
    }

    /**
     * Track activity for a resource
     *
     * @param  array<string, mixed>  $metadata  Activity metadata
     */
    public function trackActivity(string $resourceId, array $metadata = []): void
    {
        $now = time();
        $lastUpdate = $this->lastActivityUpdate[$resourceId] ?? 0;

        if (($now - $lastUpdate) < $this->activityInterval) {
            return;
        }

        $this->lastActivityUpdate[$resourceId] = $now;
        $this->resolver->trackActivity($resourceId, $metadata);
    }

    /**
     * Get adapter name
     */
    abstract public function getName(): string;

    /**
     * Get protocol type
     */
    abstract public function getProtocol(): string;

    /**
     * Get adapter description
     */
    abstract public function getDescription(): string;

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
        // Fast path: check cache first
        $cached = $this->routingTable->get($resourceId);
        $now = \time();

        if ($cached !== false && is_array($cached) && ($now - (int) $cached['updated']) < 1) {
            $this->stats['cache_hits']++;
            $this->stats['connections']++;

            return new ConnectionResult(
                endpoint: (string) $cached['endpoint'],
                protocol: $this->getProtocol(),
                metadata: ['cached' => true]
            );
        }

        $this->stats['cache_misses']++;

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

            $this->routingTable->set($resourceId, [
                'endpoint' => $endpoint,
                'updated' => $now,
            ]);

            $this->stats['connections']++;

            return new ConnectionResult(
                endpoint: $endpoint,
                protocol: $this->getProtocol(),
                metadata: array_merge(['cached' => false], $result->metadata)
            );
        } catch (\Exception $e) {
            $this->stats['routing_errors']++;
            throw $e;
        }
    }

    /**
     * Validate backend endpoint to prevent SSRF attacks
     */
    protected function validateEndpoint(string $endpoint): void
    {
        $parts = explode(':', $endpoint);
        if (count($parts) > 2) {
            throw new ResolverException("Invalid endpoint format: {$endpoint}");
        }

        $host = $parts[0];
        $port = isset($parts[1]) ? (int) $parts[1] : 0;

        if ($port > 65535) {
            throw new ResolverException("Invalid port number: {$port}");
        }

        $ip = gethostbyname($host);
        if ($ip === $host && ! filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new ResolverException("Cannot resolve hostname: {$host}");
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $longIp = ip2long($ip);
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
                $rangeStartLong = ip2long($rangeStart);
                $rangeEndLong = ip2long($rangeEnd);
                if ($longIp >= $rangeStartLong && $longIp <= $rangeEndLong) {
                    throw new ResolverException("Access to private/reserved IP address is forbidden: {$ip}");
                }
            }
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if ($ip === '::1' || strpos($ip, 'fe80:') === 0 || strpos($ip, 'fc00:') === 0 || strpos($ip, 'fd00:') === 0) {
                throw new ResolverException("Access to private/reserved IPv6 address is forbidden: {$ip}");
            }
        }
    }

    /**
     * Initialize routing cache table
     */
    protected function initRoutingTable(): void
    {
        $this->routingTable = new Table(100_000);
        $this->routingTable->column('endpoint', Table::TYPE_STRING, 64);
        $this->routingTable->column('updated', Table::TYPE_INT, 8);
        $this->routingTable->create();
    }

    /**
     * Get routing and connection stats
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        $totalRequests = $this->stats['cache_hits'] + $this->stats['cache_misses'];

        return [
            'adapter' => $this->getName(),
            'protocol' => $this->getProtocol(),
            'connections' => $this->stats['connections'],
            'cache_hits' => $this->stats['cache_hits'],
            'cache_misses' => $this->stats['cache_misses'],
            'cache_hit_rate' => $totalRequests > 0
                ? \round($this->stats['cache_hits'] / $totalRequests * 100, 2)
                : 0,
            'routing_errors' => $this->stats['routing_errors'],
            'routing_table_memory' => $this->routingTable->memorySize,
            'routing_table_size' => $this->routingTable->count(),
            'resolver' => $this->resolver->getStats(),
        ];
    }
}
