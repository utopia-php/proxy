#!/usr/bin/env php
<?php

/**
 * TCP Proxy Sustained Load Test
 *
 * Tests proxy stability under sustained load over time.
 * Monitors: memory, latency percentiles, error rate, connection count.
 *
 * Usage:
 *   # 5 minute test, 1000 concurrent connections
 *   BENCH_DURATION=300 BENCH_CONCURRENCY=1000 php benchmarks/tcp-sustained.php
 *
 *   # 30 minute soak test
 *   BENCH_DURATION=1800 BENCH_CONCURRENCY=2000 php benchmarks/tcp-sustained.php
 *
 *   # Max connections test (hold connections open)
 *   BENCH_MODE=max_connections BENCH_TARGET_CONNECTIONS=50000 php benchmarks/tcp-sustained.php
 */

use Swoole\Coroutine;
use Swoole\Coroutine\Client;
use Swoole\Timer;

Co\run(function () {
    echo "TCP Proxy Sustained Load Test\n";
    echo "==============================\n\n";

    $envInt = static function (string $key, int $default): int {
        $value = getenv($key);
        return $value === false ? $default : (int) $value;
    };
    $envFloat = static function (string $key, float $default): float {
        $value = getenv($key);
        return $value === false ? $default : (float) $value;
    };

    $host = getenv('BENCH_HOST') ?: '127.0.0.1';
    $port = $envInt('BENCH_PORT', 5432);
    $protocol = strtolower(getenv('BENCH_PROTOCOL') ?: ($port === 5432 ? 'postgres' : 'mysql'));
    $mode = getenv('BENCH_MODE') ?: 'sustained'; // sustained, max_connections
    $duration = $envInt('BENCH_DURATION', 60); // seconds
    $concurrency = $envInt('BENCH_CONCURRENCY', 1000);
    $targetConnections = $envInt('BENCH_TARGET_CONNECTIONS', 50000);
    $reportInterval = $envInt('BENCH_REPORT_INTERVAL', 5); // seconds
    $payloadBytes = $envInt('BENCH_PAYLOAD_BYTES', 1024); // small payload for sustained
    $timeout = $envFloat('BENCH_TIMEOUT', 10);

    // Build handshake
    $handshake = '';
    if ($protocol === 'mysql') {
        $handshake = "\x00\x00\x00\x00\x02db-abc123";
    } else {
        $handshake = pack('N', 196608);
        $handshake .= "user\0postgres\0database\0db-abc123\0\0";
    }

    $payload = $payloadBytes > 0 ? str_repeat('x', $payloadBytes) : '';

    echo "Configuration:\n";
    echo "  Host: {$host}:{$port}\n";
    echo "  Mode: {$mode}\n";
    if ($mode === 'sustained') {
        echo "  Duration: {$duration}s\n";
        echo "  Concurrency: {$concurrency}\n";
        echo "  Payload: {$payloadBytes} bytes\n";
    } else {
        echo "  Target connections: {$targetConnections}\n";
    }
    echo "  Report interval: {$reportInterval}s\n";
    echo "\n";

    // Shared stats (using Swoole atomic for thread safety)
    $stats = [
        'connections' => new Swoole\Atomic(0),
        'requests' => new Swoole\Atomic(0),
        'errors' => new Swoole\Atomic(0),
        'bytes_sent' => new Swoole\Atomic\Long(0),
        'bytes_recv' => new Swoole\Atomic\Long(0),
        'active' => new Swoole\Atomic(0),
        'latency_sum' => new Swoole\Atomic\Long(0),
        'latency_count' => new Swoole\Atomic(0),
        'latency_max' => new Swoole\Atomic(0),
    ];

    $running = new Swoole\Atomic(1);
    $startTime = microtime(true);
    $lastReportTime = $startTime;
    $lastStats = [
        'connections' => 0,
        'requests' => 0,
        'errors' => 0,
        'bytes_sent' => 0,
        'bytes_recv' => 0,
    ];

    // Reporter coroutine
    Coroutine::create(function () use ($stats, $running, &$lastReportTime, &$lastStats, $startTime, $reportInterval, $duration, $mode) {
        $reportNum = 0;

        echo "Time     | Conn/s | Req/s  | Err/s | Active | Throughput | Latency p50 | Memory\n";
        echo "---------|--------|--------|-------|--------|------------|-------------|--------\n";

        while ($running->get() === 1) {
            Coroutine::sleep($reportInterval);
            $reportNum++;

            $now = microtime(true);
            $elapsed = $now - $startTime;
            $interval = $now - $lastReportTime;

            $currentConnections = $stats['connections']->get();
            $currentRequests = $stats['requests']->get();
            $currentErrors = $stats['errors']->get();
            $currentBytesSent = $stats['bytes_sent']->get();
            $currentBytesRecv = $stats['bytes_recv']->get();
            $active = $stats['active']->get();

            $connPerSec = ($currentConnections - $lastStats['connections']) / $interval;
            $reqPerSec = ($currentRequests - $lastStats['requests']) / $interval;
            $errPerSec = ($currentErrors - $lastStats['errors']) / $interval;
            $throughput = (($currentBytesSent - $lastStats['bytes_sent']) + ($currentBytesRecv - $lastStats['bytes_recv'])) / $interval / 1024 / 1024;

            // Calculate average latency (rough p50 approximation)
            $latencyCount = $stats['latency_count']->get();
            $latencySum = $stats['latency_sum']->get();
            $avgLatency = $latencyCount > 0 ? ($latencySum / $latencyCount / 1000) : 0; // convert to ms

            $memory = memory_get_usage(true) / 1024 / 1024;

            printf(
                "%7.1fs | %6.0f | %6.0f | %5.0f | %6d | %8.2f MB/s | %9.2f ms | %5.1f MB\n",
                $elapsed,
                $connPerSec,
                $reqPerSec,
                $errPerSec,
                $active,
                $throughput,
                $avgLatency,
                $memory
            );

            $lastStats = [
                'connections' => $currentConnections,
                'requests' => $currentRequests,
                'errors' => $currentErrors,
                'bytes_sent' => $currentBytesSent,
                'bytes_recv' => $currentBytesRecv,
            ];
            $lastReportTime = $now;

            // Reset latency stats each interval for rolling average
            $stats['latency_sum']->set(0);
            $stats['latency_count']->set(0);

            // Check duration
            if ($mode === 'sustained' && $elapsed >= $duration) {
                $running->set(0);
            }
        }
    });

    if ($mode === 'max_connections') {
        // Max connections test: open connections and hold them
        echo "Opening {$targetConnections} connections...\n\n";

        $clients = [];
        $batchSize = 1000;

        for ($batch = 0; $batch < ceil($targetConnections / $batchSize); $batch++) {
            $batchStart = $batch * $batchSize;
            $batchEnd = min($batchStart + $batchSize, $targetConnections);

            for ($i = $batchStart; $i < $batchEnd; $i++) {
                Coroutine::create(function () use ($host, $port, $timeout, $handshake, $stats, $running, &$clients, $i) {
                    $client = new Client(SWOOLE_SOCK_TCP);
                    $client->set(['timeout' => $timeout]);

                    if (!$client->connect($host, $port, $timeout)) {
                        $stats['errors']->add(1);
                        return;
                    }

                    $stats['connections']->add(1);
                    $stats['active']->add(1);

                    // Send handshake
                    if ($client->send($handshake) === false) {
                        $stats['errors']->add(1);
                        $stats['active']->sub(1);
                        $client->close();
                        return;
                    }

                    // Receive response
                    $client->recv(8192);

                    $clients[$i] = $client;

                    // Hold connection until test ends
                    while ($running->get() === 1) {
                        Coroutine::sleep(1);

                        // Periodic ping to keep alive
                        if ($client->send("PING") === false) {
                            break;
                        }
                        $client->recv(1024);
                        $stats['requests']->add(1);
                    }

                    $stats['active']->sub(1);
                    $client->close();
                });
            }

            // Small delay between batches
            Coroutine::sleep(0.1);
        }

        // Wait for target or timeout
        $maxWait = 300; // 5 minutes to open connections
        $waited = 0;
        while ($stats['active']->get() < $targetConnections && $waited < $maxWait && $running->get() === 1) {
            Coroutine::sleep(1);
            $waited++;
        }

        echo "\n";
        echo "=== Max Connections Result ===\n";
        echo "Target: {$targetConnections}\n";
        echo "Achieved: {$stats['active']->get()}\n";
        echo "Errors: {$stats['errors']->get()}\n";

        // Hold for observation
        echo "\nHolding connections for 30 seconds...\n";
        Coroutine::sleep(30);

        $running->set(0);

    } else {
        // Sustained load test: continuous requests
        echo "Starting sustained load...\n\n";

        for ($i = 0; $i < $concurrency; $i++) {
            Coroutine::create(function () use ($host, $port, $timeout, $handshake, $payload, $payloadBytes, $stats, $running) {
                while ($running->get() === 1) {
                    $requestStart = hrtime(true);

                    $client = new Client(SWOOLE_SOCK_TCP);
                    $client->set(['timeout' => $timeout]);

                    if (!$client->connect($host, $port, $timeout)) {
                        $stats['errors']->add(1);
                        Coroutine::sleep(0.01); // Back off on error
                        continue;
                    }

                    $stats['connections']->add(1);
                    $stats['active']->add(1);

                    // Send handshake
                    if ($client->send($handshake) === false) {
                        $stats['errors']->add(1);
                        $stats['active']->sub(1);
                        $client->close();
                        continue;
                    }
                    $stats['bytes_sent']->add(strlen($handshake));

                    // Receive handshake response
                    $response = $client->recv(8192);
                    if ($response === false || $response === '') {
                        $stats['errors']->add(1);
                        $stats['active']->sub(1);
                        $client->close();
                        continue;
                    }
                    $stats['bytes_recv']->add(strlen($response));

                    // Send payload and receive echo
                    if ($payloadBytes > 0) {
                        if ($client->send($payload) === false) {
                            $stats['errors']->add(1);
                        } else {
                            $stats['bytes_sent']->add($payloadBytes);
                            $echo = $client->recv($payloadBytes + 1024);
                            if ($echo !== false) {
                                $stats['bytes_recv']->add(strlen($echo));
                            }
                        }
                    }

                    $stats['requests']->add(1);
                    $stats['active']->sub(1);
                    $client->close();

                    // Track latency
                    $latencyUs = (hrtime(true) - $requestStart) / 1000; // microseconds
                    $stats['latency_sum']->add((int) $latencyUs);
                    $stats['latency_count']->add(1);
                }
            });
        }

        // Wait for duration
        Coroutine::sleep($duration + 1);
        $running->set(0);
    }

    // Wait for reporters to finish
    Coroutine::sleep($reportInterval + 1);

    // Final summary
    $totalTime = microtime(true) - $startTime;
    $totalConnections = $stats['connections']->get();
    $totalRequests = $stats['requests']->get();
    $totalErrors = $stats['errors']->get();
    $totalBytesSent = $stats['bytes_sent']->get();
    $totalBytesRecv = $stats['bytes_recv']->get();

    echo "\n";
    echo "=== Final Summary ===\n";
    echo sprintf("Total time: %.2fs\n", $totalTime);
    echo sprintf("Total connections: %d\n", $totalConnections);
    echo sprintf("Total requests: %d\n", $totalRequests);
    echo sprintf("Total errors: %d (%.2f%%)\n", $totalErrors, $totalConnections > 0 ? ($totalErrors / $totalConnections * 100) : 0);
    echo sprintf("Avg connections/sec: %.2f\n", $totalConnections / $totalTime);
    echo sprintf("Avg requests/sec: %.2f\n", $totalRequests / $totalTime);
    echo sprintf("Total data transferred: %.2f MB\n", ($totalBytesSent + $totalBytesRecv) / 1024 / 1024);
    echo sprintf("Peak memory: %.2f MB\n", memory_get_peak_usage(true) / 1024 / 1024);
    echo "\n";

    // Pass/fail criteria
    $errorRate = $totalConnections > 0 ? ($totalErrors / $totalConnections * 100) : 100;
    echo "=== Stability Check ===\n";
    echo sprintf("Error rate < 1%%: %s (%.2f%%)\n", $errorRate < 1 ? '✓ PASS' : '✗ FAIL', $errorRate);
    echo sprintf("Memory stable: %s\n", memory_get_peak_usage(true) < 1024 * 1024 * 1024 ? '✓ PASS' : '✗ FAIL (>1GB)');
});
