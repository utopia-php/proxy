<?php

/**
 * TCP Proxy Benchmark
 *
 * Tests: Connections/sec, throughput, latency
 *
 * Usage:
 *   php benchmarks/tcp-benchmark.php
 *
 * Expected results:
 *   - Connections/sec: 100k+
 *   - Throughput: 10GB/s+
 *   - Forwarding overhead: <1ms
 */

use Swoole\Coroutine;
use Swoole\Coroutine\Client;

Co\run(function () {
    echo "TCP Proxy Benchmark\n";
    echo "===================\n\n";

    $host = 'localhost';
    $port = 5432; // PostgreSQL
    $concurrent = 1000;
    $connections = 100000;

    echo "Configuration:\n";
    echo "  Host: {$host}:{$port}\n";
    echo "  Concurrent: {$concurrent}\n";
    echo "  Total connections: {$connections}\n\n";

    $startTime = microtime(true);
    $latencies = [];
    $errors = 0;
    $channel = new Coroutine\Channel($concurrent);

    // Spawn concurrent workers
    for ($i = 0; $i < $concurrent; $i++) {
        Coroutine::create(function () use ($host, $port, $connections, $concurrent, &$latencies, &$errors, $channel) {
            $perWorker = (int)($connections / $concurrent);

            for ($j = 0; $j < $perWorker; $j++) {
                $connStart = microtime(true);

                $client = new Client(SWOOLE_SOCK_TCP);

                if (!$client->connect($host, $port, 10)) {
                    $errors++;
                    continue;
                }

                // Send PostgreSQL startup message
                $data = pack('N', 196608); // Protocol version 3.0
                $data .= "user\0postgres\0database\0db-abc123\0\0";

                $client->send($data);
                $response = $client->recv(8192, 5);

                $latency = (microtime(true) - $connStart) * 1000;
                $latencies[] = $latency;

                $client->close();
            }

            $channel->push(true);
        });
    }

    // Wait for all workers to complete
    for ($i = 0; $i < $concurrent; $i++) {
        $channel->pop();
    }

    $totalTime = microtime(true) - $startTime;

    // Calculate statistics
    sort($latencies);
    $count = count($latencies);

    $connPerSec = $connections / $totalTime;
    $avgLatency = array_sum($latencies) / $count;
    $p50 = $latencies[(int)($count * 0.5)];
    $p95 = $latencies[(int)($count * 0.95)];
    $p99 = $latencies[(int)($count * 0.99)];
    $min = $latencies[0];
    $max = $latencies[$count - 1];

    echo "\nResults:\n";
    echo "========\n";
    echo sprintf("Total time: %.2fs\n", $totalTime);
    echo sprintf("Connections/sec: %.0f\n", $connPerSec);
    echo sprintf("Errors: %d (%.2f%%)\n", $errors, ($errors / $connections) * 100);
    echo "\nLatency:\n";
    echo sprintf("  Min: %.2fms\n", $min);
    echo sprintf("  Avg: %.2fms\n", $avgLatency);
    echo sprintf("  p50: %.2fms\n", $p50);
    echo sprintf("  p95: %.2fms\n", $p95);
    echo sprintf("  p99: %.2fms\n", $p99);
    echo sprintf("  Max: %.2fms\n", $max);

    // Performance goals
    echo "\nPerformance Goals:\n";
    echo "==================\n";
    echo sprintf("Connections/sec goal: 100k+... %s\n",
        $connPerSec >= 100000 ? "✓ PASS" : "✗ FAIL");
    echo sprintf("Forwarding overhead goal: <1ms... %s\n",
        $avgLatency < 1.0 ? "✓ PASS" : "✗ FAIL");
});
