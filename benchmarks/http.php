<?php

/**
 * HTTP Proxy Benchmark
 *
 * Tests: Throughput, latency, cache hit rate
 *
 * Usage:
 *   BENCH_CONCURRENCY=5000 BENCH_REQUESTS=2000000 php benchmarks/http.php
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

    $envInt = static function (string $key, int $default): int {
        $value = getenv($key);

        return $value === false ? $default : (int) $value;
    };
    $envFloat = static function (string $key, float $default): float {
        $value = getenv($key);

        return $value === false ? $default : (float) $value;
    };
    $envBool = static function (string $key, bool $default): bool {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? $default;
    };

    $host = getenv('BENCH_HOST') ?: 'localhost';
    $port = $envInt('BENCH_PORT', 8080);
    $cpu = function_exists('swoole_cpu_num') ? swoole_cpu_num() : 4;
    $concurrent = $envInt('BENCH_CONCURRENCY', max(2000, $cpu * 500));
    $requests = $envInt('BENCH_REQUESTS', max(1000000, $concurrent * 500));
    $timeout = $envFloat('BENCH_TIMEOUT', 10);
    $keepAlive = $envBool('BENCH_KEEP_ALIVE', true);
    $sampleTarget = $envInt('BENCH_SAMPLE_TARGET', 200000);
    $sampleEvery = $envInt('BENCH_SAMPLE_EVERY', max(1, (int) ceil($requests / max(1, $sampleTarget))));

    if ($requests < 1) {
        echo "Invalid request count.\n";

        return;
    }
    if ($concurrent > $requests) {
        $concurrent = $requests;
    }
    if ($concurrent < 1) {
        echo "Invalid concurrency.\n";

        return;
    }

    echo "Configuration:\n";
    echo "  Host: {$host}:{$port}\n";
    echo "  Concurrent: {$concurrent}\n";
    echo "  Total requests: {$requests}\n";
    echo '  Keep-alive: '.($keepAlive ? 'yes' : 'no')."\n";
    echo "  Sample every: {$sampleEvery} req\n\n";

    $startTime = microtime(true);
    $errors = 0;
    $channel = new Coroutine\Channel($concurrent);
    $perWorker = intdiv($requests, $concurrent);
    $remainder = $requests % $concurrent;

    // Spawn concurrent workers
    for ($i = 0; $i < $concurrent; $i++) {
        $workerRequests = $perWorker + ($i < $remainder ? 1 : 0);
        Coroutine::create(function () use (
            $host,
            $port,
            $workerRequests,
            $timeout,
            $keepAlive,
            $sampleEvery,
            $channel
        ) {
            $count = 0;
            $sum = 0.0;
            $min = INF;
            $max = 0.0;
            $errors = 0;
            $samples = [];

            if ($workerRequests < 1) {
                $channel->push([
                    'count' => 0,
                    'sum' => 0.0,
                    'min' => INF,
                    'max' => 0.0,
                    'errors' => 0,
                    'samples' => [],
                ]);

                return;
            }

            $createClient = static function () use ($host, $port, $timeout, $keepAlive): Client {
                $client = new Client($host, $port);
                $client->set([
                    'timeout' => $timeout,
                    'keep_alive' => $keepAlive,
                ]);
                $client->setHeaders(['Host' => $host]);

                return $client;
            };

            $client = $keepAlive ? $createClient() : null;

            for ($j = 0; $j < $workerRequests; $j++) {
                if ($keepAlive && $client === null) {
                    $client = $createClient();
                }

                $reqStart = microtime(true);

                if ($keepAlive) {
                    $ok = $client->get('/');
                    $status = $client->statusCode;
                } else {
                    $client = $createClient();
                    $ok = $client->get('/');
                    $status = $client->statusCode;
                    $client->close();
                }

                $latency = (microtime(true) - $reqStart) * 1000;
                $count++;
                $sum += $latency;

                if ($latency < $min) {
                    $min = $latency;
                }
                if ($latency > $max) {
                    $max = $latency;
                }
                if (($count % $sampleEvery) === 0) {
                    $samples[] = $latency;
                }

                if ($ok === false || $status !== 200) {
                    $errors++;
                    if ($keepAlive && $client !== null) {
                        $client->close();
                        $client = null;
                    }
                }
            }

            if ($keepAlive && $client !== null) {
                $client->close();
            }

            $channel->push([
                'count' => $count,
                'sum' => $sum,
                'min' => $min,
                'max' => $max,
                'errors' => $errors,
                'samples' => $samples,
            ]);
        });
    }

    $totalCount = 0;
    $sum = 0.0;
    $min = INF;
    $max = 0.0;
    $samples = [];

    for ($i = 0; $i < $concurrent; $i++) {
        $result = $channel->pop();
        $totalCount += $result['count'];
        $sum += $result['sum'];
        $errors += $result['errors'];
        if ($result['count'] > 0) {
            if ($result['min'] < $min) {
                $min = $result['min'];
            }
            if ($result['max'] > $max) {
                $max = $result['max'];
            }
        }
        if (! empty($result['samples'])) {
            $samples = array_merge($samples, $result['samples']);
        }
    }

    $totalTime = microtime(true) - $startTime;

    // Calculate statistics
    if ($totalCount === 0) {
        echo "No requests completed.\n";

        return;
    }

    $throughput = $totalCount / $totalTime;
    $avgLatency = $sum / $totalCount;

    sort($samples);
    $sampleCount = count($samples);
    $p50 = $sampleCount ? $samples[(int) floor($sampleCount * 0.5)] : 0.0;
    $p95 = $sampleCount ? $samples[(int) floor($sampleCount * 0.95)] : 0.0;
    $p99 = $sampleCount ? $samples[(int) floor($sampleCount * 0.99)] : 0.0;

    echo "\nResults:\n";
    echo "========\n";
    echo sprintf("Total time: %.2fs\n", $totalTime);
    echo sprintf("Throughput: %.0f req/s\n", $throughput);
    echo sprintf("Errors: %d (%.2f%%)\n", $errors, ($errors / $totalCount) * 100);
    echo "\nLatency (sampled):\n";
    echo sprintf("  Min: %.2fms\n", $min);
    echo sprintf("  Avg: %.2fms\n", $avgLatency);
    echo sprintf("  p50: %.2fms\n", $p50);
    echo sprintf("  p95: %.2fms\n", $p95);
    echo sprintf("  p99: %.2fms\n", $p99);
    echo sprintf("  Max: %.2fms\n", $max);

    // Performance goals
    echo "\nPerformance Goals:\n";
    echo "==================\n";
    echo sprintf(
        "Throughput goal: 250k+ req/s... %s\n",
        $throughput >= 250000 ? '✓ PASS' : '✗ FAIL'
    );
    echo sprintf(
        "p50 latency goal: <1ms... %s\n",
        $p50 < 1.0 ? '✓ PASS' : '✗ FAIL'
    );
    echo sprintf(
        "p99 latency goal: <5ms... %s\n",
        $p99 < 5.0 ? '✓ PASS' : '✗ FAIL'
    );
});
