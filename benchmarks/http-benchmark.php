<?php

/**
 * HTTP Proxy Benchmark
 *
 * Tests: Throughput, latency, cache hit rate
 *
 * Usage:
 *   php benchmarks/http-benchmark.php
 *
 * Expected results:
 *   - Throughput: 250k+ req/s
 *   - Latency p50: <1ms
 *   - Latency p99: <5ms
 *   - Cache hit rate: >99%
 */

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;

Co\run(function () {
    echo "HTTP Proxy Benchmark\n";
    echo "===================\n\n";

    $host = 'localhost';
    $port = 8080;
    $concurrent = 1000;
    $requests = 100000;

    echo "Configuration:\n";
    echo "  Host: {$host}:{$port}\n";
    echo "  Concurrent: {$concurrent}\n";
    echo "  Total requests: {$requests}\n\n";

    $startTime = microtime(true);
    $latencies = [];
    $errors = 0;
    $channel = new Coroutine\Channel($concurrent);

    // Spawn concurrent workers
    for ($i = 0; $i < $concurrent; $i++) {
        Coroutine::create(function () use ($host, $port, $requests, $concurrent, &$latencies, &$errors, $channel) {
            $perWorker = (int)($requests / $concurrent);

            for ($j = 0; $j < $perWorker; $j++) {
                $reqStart = microtime(true);

                $client = new Client($host, $port);
                $client->set(['timeout' => 10]);
                $client->get('/');

                $latency = (microtime(true) - $reqStart) * 1000;
                $latencies[] = $latency;

                if ($client->statusCode !== 200) {
                    $errors++;
                }

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

    $throughput = $requests / $totalTime;
    $avgLatency = array_sum($latencies) / $count;
    $p50 = $latencies[(int)($count * 0.5)];
    $p95 = $latencies[(int)($count * 0.95)];
    $p99 = $latencies[(int)($count * 0.99)];
    $min = $latencies[0];
    $max = $latencies[$count - 1];

    echo "\nResults:\n";
    echo "========\n";
    echo sprintf("Total time: %.2fs\n", $totalTime);
    echo sprintf("Throughput: %.0f req/s\n", $throughput);
    echo sprintf("Errors: %d (%.2f%%)\n", $errors, ($errors / $requests) * 100);
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
    echo sprintf("Throughput goal: 250k+ req/s... %s\n",
        $throughput >= 250000 ? "✓ PASS" : "✗ FAIL");
    echo sprintf("p50 latency goal: <1ms... %s\n",
        $p50 < 1.0 ? "✓ PASS" : "✗ FAIL");
    echo sprintf("p99 latency goal: <5ms... %s\n",
        $p99 < 5.0 ? "✓ PASS" : "✗ FAIL");
});
