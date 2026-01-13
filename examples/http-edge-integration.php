<?php

/**
 * Example: Integrating Appwrite Edge with Protocol Proxy
 *
 * This example shows how Appwrite Edge can use the protocol-proxy
 * with custom actions to inject business logic like:
 * - Rule caching and resolution
 * - JWT authentication
 * - Runtime resolution
 * - Logging and telemetry
 *
 * Usage:
 *   php examples/http-edge-integration.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Utopia\Platform\Action;
use Utopia\Proxy\Adapter\HTTP\Swoole as HTTPAdapter;
use Utopia\Proxy\Service\HTTP as HTTPService;
use Utopia\Proxy\Server\HTTP\Swoole as HTTPServer;

// Create HTTP adapter
$adapter = new HTTPAdapter();
$service = $adapter->getService() ?? new HTTPService();

// Action: Resolve backend endpoint (REQUIRED)
// This is where Appwrite Edge provides the backend resolution logic
$service->addAction('resolve', (new class extends Action {})
    ->callback(function (string $hostname): string {
    echo "[Action] Resolving backend for: {$hostname}\n";

    // Example resolution strategies:

    // Option 1: Kubernetes service discovery (recommended for Edge)
    // Extract runtime info and return K8s service
    if (preg_match('/^func-([a-z0-9]+)\.appwrite\.network$/', $hostname, $matches)) {
        $functionId = $matches[1];
        // Edge would query its runtime registry here
        return "runtime-{$functionId}.runtimes.svc.cluster.local:8080";
    }

    // Option 2: Query database (traditional approach)
    // $doc = $db->findOne('functions', [Query::equal('hostname', [$hostname])]);
    // return $doc->getAttribute('endpoint');

    // Option 3: Query external API (Cloud Platform API)
    // $runtime = $edgeApi->getRuntime($hostname);
    // return $runtime['endpoint'];

    // Option 4: Redis cache + fallback
    // $endpoint = $redis->get("endpoint:{$hostname}");
    // if (!$endpoint) {
    //     $endpoint = $api->resolve($hostname);
    //     $redis->setex("endpoint:{$hostname}", 60, $endpoint);
    // }
    // return $endpoint;

    throw new \Exception("No backend found for hostname: {$hostname}");
}));

// Action 1: Before routing - Validate domain and extract project/deployment info
$service->addAction('beforeRoute', (new class extends Action {})
    ->setType(Action::TYPE_INIT)
    ->callback(function (string $hostname) {
    echo "[Action] Before routing for: {$hostname}\n";

    // Example: Edge could validate domain format here
    if (!preg_match('/^[a-z0-9-]+\.appwrite\.network$/', $hostname)) {
        throw new \Exception("Invalid hostname format: {$hostname}");
    }
}));

// Action 2: After routing - Log successful routes and cache rule data
$service->addAction('afterRoute', (new class extends Action {})
    ->setType(Action::TYPE_SHUTDOWN)
    ->callback(function (string $hostname, string $endpoint, $result) {
    echo "[Action] Routed {$hostname} -> {$endpoint}\n";
    echo "[Action] Cache: " . ($result->metadata['cached'] ? 'HIT' : 'MISS') . "\n";
    echo "[Action] Latency: {$result->metadata['latency_ms']}ms\n";

    // Example: Edge could:
    // - Log to telemetry
    // - Update metrics
    // - Cache rule/runtime data
    // - Add custom headers to response
}));

// Action 3: On routing error - Log errors and provide custom error handling
$service->addAction('onRoutingError', (new class extends Action {})
    ->setType(Action::TYPE_ERROR)
    ->callback(function (string $hostname, \Exception $e) {
    echo "[Action] Routing error for {$hostname}: {$e->getMessage()}\n";

    // Example: Edge could:
    // - Log to Sentry
    // - Return custom error pages
    // - Trigger alerts
    // - Fallback to different region
}));

$adapter->setService($service);

// Create server with custom adapter
$server = new HTTPServer(
    host: '0.0.0.0',
    port: 8080,
    workers: swoole_cpu_num() * 2,
    config: [
        // Pass the configured adapter to workers
        'adapter_factory' => fn() => $adapter,
    ]
);

echo "Edge-integrated HTTP Proxy Server\n";
echo "==================================\n";
echo "Listening on: http://0.0.0.0:8080\n";
echo "\nActions registered:\n";
echo "- resolve: K8s service discovery\n";
echo "- beforeRoute: Domain validation\n";
echo "- afterRoute: Logging and telemetry\n";
echo "- onRoutingError: Error handling\n\n";

$server->start();
