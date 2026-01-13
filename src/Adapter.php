<?php

namespace Utopia\Proxy;

use Swoole\Table;
use Utopia\Platform\Action;
use Utopia\Platform\Service;

/**
 * Protocol Proxy Adapter
 *
 * Base class for protocol-specific proxy implementations.
 * Focuses on routing and forwarding traffic - NOT container orchestration.
 *
 * Responsibilities:
 * - Route incoming requests to backend endpoints
 * - Cache routing decisions for performance (optional)
 * - Provide connection statistics
 * - Execute lifecycle actions
 *
 * Non-responsibilities (handled by application layer):
 * - Backend endpoint resolution (provided via resolve action)
 * - Container cold-starts and lifecycle management
 * - Health checking and orchestration
 * - Business logic (authentication, authorization, etc.)
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

    protected ?Service $service = null;

    public function __construct(?Service $service = null)
    {
        $this->service = $service ?? $this->defaultService();
        $this->initRoutingTable();
    }

    /**
     * Provide a default service for the adapter.
     *
     * @return Service|null
     */
    protected function defaultService(): ?Service
    {
        return null;
    }

    /**
     * Set action service
     *
     * @param Service $service
     * @return $this
     */
    public function setService(Service $service): static
    {
        $this->service = $service;

        return $this;
    }

    /**
     * Get action service
     *
     * @return Service|null
     */
    public function getService(): ?Service
    {
        return $this->service;
    }

    /**
     * Get adapter name
     *
     * @return string
     */
    abstract public function getName(): string;

    /**
     * Get protocol type
     *
     * @return string
     */
    abstract public function getProtocol(): string;

    /**
     * Get adapter description
     *
     * @return string
     */
    abstract public function getDescription(): string;

    /**
     * Get backend endpoint for a resource identifier
     *
     * Uses the resolve action registered on the action service.
     *
     * @param string $resourceId Protocol-specific identifier (hostname, connection string, etc.)
     * @return string Backend endpoint (host:port or IP:port)
     * @throws \Exception If resource not found or backend unavailable
     */
    protected function getBackendEndpoint(string $resourceId): string
    {
        $resolver = $this->getActionCallback($this->getResolveAction());
        $endpoint = $resolver($resourceId);

        if (empty($endpoint)) {
            throw new \Exception("Resolve action returned empty endpoint for: {$resourceId}");
        }

        return $endpoint;
    }

    /**
     * Initialize Swoole shared memory table for routing cache
     *
     * 100k entries = ~10MB memory, O(1) lookups
     */
    protected function initRoutingTable(): void
    {
        $this->routingTable = new Table(100_000);
        $this->routingTable->column('endpoint', Table::TYPE_STRING, 64);
        $this->routingTable->column('updated', Table::TYPE_INT, 8);
        $this->routingTable->create();
    }

    /**
     * Route connection to backend
     *
     * Performance: <1ms for cache hit, <10ms for cache miss
     *
     * @param string $resourceId Protocol-specific identifier
     * @return ConnectionResult Backend endpoint and metadata
     * @throws \Exception If routing fails
     */
    public function route(string $resourceId): ConnectionResult
    {
        $startTime = microtime(true);

        // Execute init actions (before route)
        $this->executeActions(Action::TYPE_INIT, $resourceId);

        // Check routing cache first (O(1) lookup)
        $cached = $this->routingTable->get($resourceId);
        if ($cached && (\time() - $cached['updated']) < 1) {
            $this->stats['cache_hits']++;
            $this->stats['connections']++;

            $result = new ConnectionResult(
                endpoint: $cached['endpoint'],
                protocol: $this->getProtocol(),
                metadata: [
                    'cached' => true,
                    'latency_ms' => \round((\microtime(true) - $startTime) * 1000, 2),
                ]
            );

            // Execute shutdown actions (after route)
            $this->executeActions(Action::TYPE_SHUTDOWN, $resourceId, $cached['endpoint'], $result);

            return $result;
        }

        $this->stats['cache_misses']++;

        try {
            // Get backend endpoint from protocol-specific logic
            $endpoint = $this->getBackendEndpoint($resourceId);

            // Update routing cache
            $this->routingTable->set($resourceId, [
                'endpoint' => $endpoint,
                'updated' => \time(),
            ]);

            $this->stats['connections']++;

            $result = new ConnectionResult(
                endpoint: $endpoint,
                protocol: $this->getProtocol(),
                metadata: [
                    'cached' => false,
                    'latency_ms' => \round((\microtime(true) - $startTime) * 1000, 2),
                ]
            );

            // Execute shutdown actions (after route)
            $this->executeActions(Action::TYPE_SHUTDOWN, $resourceId, $endpoint, $result);

            return $result;
        } catch (\Exception $e) {
            $this->stats['routing_errors']++;

            // Execute error actions (on routing error)
            $this->executeActions(Action::TYPE_ERROR, $resourceId, $e);

            throw $e;
        }
    }

    /**
     * Get the resolve action
     *
     * @return Action
     * @throws \Exception
     */
    protected function getResolveAction(): Action
    {
        $service = $this->service;
        if ($service === null) {
            throw new \Exception(
                "No action service registered. You must register a resolve action:\n" .
                "\$service->addAction('resolve', (new class extends \\Utopia\\Platform\\Action {})\n" .
                "    ->callback(fn(\$resourceId) => \$backendEndpoint));"
            );
        }

        $action = $this->getServiceAction($service, 'resolve');
        if ($action === null) {
            throw new \Exception(
                "No resolve action registered. You must register a resolve action:\n" .
                "\$service->addAction('resolve', (new class extends \\Utopia\\Platform\\Action {})\n" .
                "    ->callback(fn(\$resourceId) => \$backendEndpoint));"
            );
        }

        return $action;
    }

    /**
     * Execute actions by type.
     *
     * @param string $type
     * @param mixed ...$args
     * @return void
     */
    protected function executeActions(string $type, mixed ...$args): void
    {
        if ($this->service === null) {
            return;
        }

        foreach ($this->getServiceActions($this->service) as $action) {
            if ($action->getType() !== $type) {
                continue;
            }

            $callback = $this->getActionCallback($action);
            $callback(...$args);
        }
    }

    /**
     * Resolve action callback.
     *
     * @param Action $action
     * @return callable
     */
    protected function getActionCallback(Action $action): callable
    {
        $callback = $action->getCallback();
        if (!\is_callable($callback)) {
            throw new \InvalidArgumentException('Action callback must be callable.');
        }

        return $callback;
    }

    /**
     * Safely read actions from the service.
     *
     * @param Service $service
     * @return array<string, Action>
     */
    protected function getServiceActions(Service $service): array
    {
        try {
            return $service->getActions();
        } catch (\Error) {
            return [];
        }
    }

    /**
     * Safely read a single action from the service.
     *
     * @param Service $service
     * @param string $key
     * @return Action|null
     */
    protected function getServiceAction(Service $service, string $key): ?Action
    {
        try {
            return $service->getAction($key);
        } catch (\Error) {
            return null;
        }
    }

    /**
     * Get routing and connection stats for monitoring
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
        ];
    }
}
