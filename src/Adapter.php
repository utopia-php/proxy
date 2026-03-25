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
        protected string $name = 'Generic',
        protected Protocol $protocol = Protocol::TCP,
        protected string $description = 'Generic proxy adapter',
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
        if (!isset($this->bytes[$resourceId])) {
            $this->bytes[$resourceId] = new Bytes();
        }

        $this->bytes[$resourceId]->inbound += $inbound;
        $this->bytes[$resourceId]->outbound += $outbound;
    }

    /**
     * @param  array<string, mixed>  $metadata  Activity metadata
     */
    public function track(string $resourceId, array $metadata = []): void
    {
        $now = time();
        $lastUpdate = $this->lastActivity[$resourceId] ?? 0;

        if (($now - $lastUpdate) < $this->interval) {
            return;
        }

        $this->lastActivity[$resourceId] = $now;

        if (isset($this->bytes[$resourceId])) {
            $metadata['inboundBytes'] = $this->bytes[$resourceId]->inbound;
            $metadata['outboundBytes'] = $this->bytes[$resourceId]->outbound;
            $this->bytes[$resourceId] = new Bytes();
        }

        $this->resolver?->track($resourceId, $metadata);
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
                    "No resolver or resolve callback configured",
                    ResolverException::NOT_FOUND
                );
            }
            $endpoint = $result->endpoint;

            if (empty($endpoint)) {
                throw new ResolverException(
                    "Resolver returned empty endpoint for: {$resourceId}",
                    ResolverException::NOT_FOUND
                );
            }

            if (!$this->skipValidation) {
                $this->validate($endpoint);
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
    protected function validate(string $endpoint): void
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
        if ($ip === $host && !\filter_var($ip, FILTER_VALIDATE_IP)) {
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
            'resolver' => $this->resolver?->getStats() ?? [],
        ];
    }
}
