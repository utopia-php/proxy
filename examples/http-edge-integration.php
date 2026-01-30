<?php

/**
 * Example: Integrating Appwrite Edge with Protocol Proxy
 *
 * This example shows how Appwrite Edge can use the protocol-proxy
 * with a custom Resolver to inject business logic like:
 * - Rule caching and resolution
 * - Domain validation
 * - Runtime resolution
 * - Logging and telemetry
 *
 * Usage:
 *   php examples/http-edge-integration.php
 */

require __DIR__.'/../vendor/autoload.php';

use Utopia\Proxy\Resolver;
use Utopia\Proxy\Resolver\Exception;
use Utopia\Proxy\Resolver\Result;
use Utopia\Proxy\Server\HTTP\Swoole as HTTPServer;

/**
 * Edge Resolver - Custom resolver for Appwrite Edge integration
 *
 * Demonstrates how to implement a full-featured resolver with:
 * - Domain validation
 * - Kubernetes service discovery
 * - Connection lifecycle tracking
 * - Statistics and telemetry
 */
$resolver = new class () implements Resolver {
    /** @var array<string, int> */
    private array $connectionCounts = [];

    /** @var array<string, float> */
    private array $lastActivity = [];

    /** @var int */
    private int $totalRequests = 0;

    /** @var int */
    private int $totalErrors = 0;

    public function resolve(string $resourceId): Result
    {
        $this->totalRequests++;

        echo "[Resolver] Resolving backend for: {$resourceId}\n";

        // Validate domain format
        if (!preg_match('/^[a-z0-9-]+\.appwrite\.network$/', $resourceId)) {
            $this->totalErrors++;
            throw new Exception(
                "Invalid hostname format: {$resourceId}",
                Exception::FORBIDDEN
            );
        }

        // Example resolution strategies:

        // Option 1: Kubernetes service discovery (recommended for Edge)
        // Extract runtime info and return K8s service
        if (preg_match('/^func-([a-z0-9]+)\.appwrite\.network$/', $resourceId, $matches)) {
            $functionId = $matches[1];
            $endpoint = "runtime-{$functionId}.runtimes.svc.cluster.local:8080";

            echo "[Resolver] Resolved to K8s service: {$endpoint}\n";

            return new Result(
                endpoint: $endpoint,
                metadata: [
                    'type' => 'function',
                    'function_id' => $functionId,
                ]
            );
        }

        // Option 2: Query database (traditional approach)
        // $doc = $db->findOne('functions', [Query::equal('hostname', [$resourceId])]);
        // return new Result(endpoint: $doc->getAttribute('endpoint'));

        // Option 3: Query external API (Cloud Platform API)
        // $runtime = $edgeApi->getRuntime($resourceId);
        // return new Result(endpoint: $runtime['endpoint']);

        // Option 4: Redis cache + fallback
        // $endpoint = $redis->get("endpoint:{$resourceId}");
        // if (!$endpoint) {
        //     $endpoint = $api->resolve($resourceId);
        //     $redis->setex("endpoint:{$resourceId}", 60, $endpoint);
        // }
        // return new Result(endpoint: $endpoint);

        $this->totalErrors++;
        throw new Exception(
            "No backend found for hostname: {$resourceId}",
            Exception::NOT_FOUND
        );
    }

    public function onConnect(string $resourceId, array $metadata = []): void
    {
        $this->connectionCounts[$resourceId] = ($this->connectionCounts[$resourceId] ?? 0) + 1;
        $this->lastActivity[$resourceId] = microtime(true);

        echo "[Resolver] Connection opened for: {$resourceId} (active: {$this->connectionCounts[$resourceId]})\n";
    }

    public function onDisconnect(string $resourceId, array $metadata = []): void
    {
        if (isset($this->connectionCounts[$resourceId])) {
            $this->connectionCounts[$resourceId]--;
        }

        echo "[Resolver] Connection closed for: {$resourceId}\n";

        // Example: Log to telemetry, update metrics
    }

    public function trackActivity(string $resourceId, array $metadata = []): void
    {
        $this->lastActivity[$resourceId] = microtime(true);

        // Example: Update activity metrics for cold-start detection
    }

    public function invalidateCache(string $resourceId): void
    {
        echo "[Resolver] Cache invalidated for: {$resourceId}\n";

        // Example: Clear Redis cache, notify other workers
    }

    public function getStats(): array
    {
        return [
            'resolver' => 'edge',
            'total_requests' => $this->totalRequests,
            'total_errors' => $this->totalErrors,
            'active_connections' => array_sum($this->connectionCounts),
            'connections_by_host' => $this->connectionCounts,
        ];
    }
};

// Create server with custom resolver
$server = new HTTPServer(
    $resolver,
    host: '0.0.0.0',
    port: 8080,
    workers: swoole_cpu_num() * 2
);

echo "Edge-integrated HTTP Proxy Server\n";
echo "==================================\n";
echo "Listening on: http://0.0.0.0:8080\n";
echo "\nResolver features:\n";
echo "- resolve: K8s service discovery with domain validation\n";
echo "- onConnect/onDisconnect: Connection lifecycle tracking\n";
echo "- trackActivity: Activity metrics for cold-start detection\n";
echo "- getStats: Statistics and telemetry\n\n";

$server->start();
