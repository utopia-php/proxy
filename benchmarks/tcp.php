<?php

/**
 * TCP Proxy Benchmark
 *
 * Tests: Connections/sec, throughput, latency
 *
 * Usage:
 *   BENCH_CONCURRENCY=4000 BENCH_CONNECTIONS=400000 php benchmarks/tcp.php
 *   BENCH_PAYLOAD_BYTES=0 php benchmarks/tcp.php
 *
 * Expected results (payload disabled):
 *   - Connections/sec: 100k+
 *   - Throughput: 10GB/s+
 *   - Forwarding overhead: <1ms
 */

use Swoole\Coroutine;
use Swoole\Coroutine\Client;

Co\run(function () {
    echo "TCP Proxy Benchmark\n";
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
    $port = $envInt('BENCH_PORT', 5432); // PostgreSQL
    $protocol = strtolower(getenv('BENCH_PROTOCOL') ?: ($port === 5432 ? 'postgres' : 'mysql'));
    $cpu = function_exists('swoole_cpu_num') ? swoole_cpu_num() : 4;
    $concurrent = $envInt('BENCH_CONCURRENCY', max(2000, $cpu * 500));
    $payloadBytes = $envInt('BENCH_PAYLOAD_BYTES', 65536);
    $targetBytes = $envInt('BENCH_TARGET_BYTES', 8 * 1024 * 1024 * 1024);
    $persistent = $envBool('BENCH_PERSISTENT', false);
    $echoNewline = $envBool('BENCH_ECHO_NEWLINE', false);
    $streamBytes = $envInt('BENCH_STREAM_BYTES', 0);
    $streamDuration = $envFloat('BENCH_STREAM_DURATION', 0);
    $timeout = $envFloat('BENCH_TIMEOUT', 10);
    $connectionsEnv = getenv('BENCH_CONNECTIONS');
    if ($persistent) {
        $connections = $concurrent;
        if ($streamBytes <= 0 && $streamDuration <= 0) {
            $streamBytes = $targetBytes;
        }
    } elseif ($connectionsEnv === false) {
        $connections = max(300000, $concurrent * 100);
        if ($payloadBytes > 0) {
            $connections = max(100000, $concurrent * 20);
            $maxByTarget = (int) floor($targetBytes / max(1, $payloadBytes));
            if ($maxByTarget > 0) {
                $connections = min($connections, $maxByTarget);
            }
        }
    } else {
        $connections = (int) $connectionsEnv;
    }
    $sampleTarget = $envInt('BENCH_SAMPLE_TARGET', 200000);
    $sampleEvery = $envInt('BENCH_SAMPLE_EVERY', max(1, (int) ceil($connections / max(1, $sampleTarget))));

    if ($connections < 1) {
        echo "Invalid connection count.\n";

        return;
    }
    if ($concurrent > $connections) {
        $concurrent = $connections;
    }
    if ($concurrent < 1) {
        echo "Invalid concurrency.\n";

        return;
    }

    echo "Configuration:\n";
    echo "  Host: {$host}:{$port}\n";
    echo "  Concurrent: {$concurrent}\n";
    echo "  Total connections: {$connections}\n";
    echo "  Protocol: {$protocol}\n";
    echo "  Payload per connection: {$payloadBytes} bytes\n";
    echo "  Sample every: {$sampleEvery} conns\n\n";

    $startTime = microtime(true);
    $errors = 0;
    $channel = new Coroutine\Channel($concurrent);
    $perWorker = intdiv($connections, $concurrent);
    $remainder = $connections % $concurrent;

    $chunkSize = 65536;
    $payloadChunk = '';
    $payloadRemainder = '';
    $payloadSuffix = '';
    $payloadDataBytes = $payloadBytes;
    if ($echoNewline && $payloadBytes > 0) {
        $payloadDataBytes = $payloadBytes - 1;
        $payloadSuffix = "\n";
    }
    if ($payloadBytes > 0) {
        $chunkSize = min($chunkSize, max(1, $payloadDataBytes));
        $payloadChunk = $payloadDataBytes > 0 ? str_repeat('a', $chunkSize) : '';
        $remainderBytes = $payloadDataBytes % $chunkSize;
        if ($remainderBytes > 0) {
            $payloadRemainder = str_repeat('a', $remainderBytes);
        }
    }

    $handshake = '';
    if ($protocol === 'mysql') {
        // Minimal COM_INIT_DB packet; adapter only checks command byte + db name.
        $handshake = "\x00\x00\x00\x00\x02db-abc123";
    } else {
        // PostgreSQL startup message
        $handshake = pack('N', 196608); // Protocol version 3.0
        $handshake .= "user\0postgres\0database\0db-abc123\0\0";
    }
    if ($echoNewline && $protocol === 'mysql') {
        $handshake .= "\n";
    }

    if ($persistent) {
        if ($payloadBytes <= 0) {
            echo "Persistent mode requires BENCH_PAYLOAD_BYTES > 0.\n";

            return;
        }

        echo "Mode: persistent\n";
        if ($streamBytes > 0) {
            echo "  Stream bytes: {$streamBytes}\n";
        }
        if ($streamDuration > 0) {
            echo "  Stream duration: {$streamDuration}s\n";
        }
        echo "\n";

        $remainingBytes = null;
        if ($streamBytes > 0) {
            if (class_exists('Swoole\\Atomic\\Long')) {
                $remainingBytes = new \Swoole\Atomic\Long($streamBytes);
            } else {
                $remainingBytes = new \Swoole\Atomic($streamBytes);
            }
        }
        $deadline = $streamDuration > 0 ? (microtime(true) + $streamDuration) : null;

        $startTime = microtime(true);
        $errors = 0;
        $channel = new Coroutine\Channel($concurrent);

        for ($i = 0; $i < $concurrent; $i++) {
            Coroutine::create(function () use (
                $host,
                $port,
                $timeout,
                $payloadBytes,
                $payloadDataBytes,
                $payloadChunk,
                $payloadRemainder,
                $payloadSuffix,
                $handshake,
                $remainingBytes,
                $deadline,
                $channel
            ) {
                $bytes = 0;
                $ops = 0;
                $errors = 0;

                $client = new Client(SWOOLE_SOCK_TCP);
                $client->set(['timeout' => $timeout]);

                if (! $client->connect($host, $port, $timeout)) {
                    $errors++;
                    $channel->push([
                        'bytes' => 0,
                        'ops' => 0,
                        'errors' => $errors,
                    ]);

                    return;
                }

                if ($client->send($handshake) === false) {
                    $errors++;
                    $client->close();
                    $channel->push([
                        'bytes' => 0,
                        'ops' => 0,
                        'errors' => $errors,
                    ]);

                    return;
                }

                $handshakeResponse = $client->recv(8192);
                if ($handshakeResponse === '' || $handshakeResponse === false) {
                    $errors++;
                    $client->close();
                    $channel->push([
                        'bytes' => 0,
                        'ops' => 0,
                        'errors' => $errors,
                    ]);

                    return;
                }

                while (true) {
                    if ($deadline !== null && microtime(true) >= $deadline) {
                        break;
                    }

                    $chunkBytes = $payloadBytes;
                    $payload = $payloadChunk;
                    $payloadTail = $payloadSuffix;

                    if ($remainingBytes !== null) {
                        $remaining = $remainingBytes->get();
                        if ($remaining <= 0) {
                            break;
                        }
                        $chunkBytes = min($payloadBytes, $remaining);
                        $remainingBytes->sub($chunkBytes);
                        if ($chunkBytes !== $payloadBytes) {
                            $payloadTail = '';
                            $payload = $chunkBytes > 0 ? substr($payloadChunk, 0, $chunkBytes) : '';
                        }
                    }

                    if ($chunkBytes <= 0) {
                        break;
                    }

                    $remainingSend = $payloadDataBytes > 0 ? min($payloadDataBytes, $chunkBytes) : 0;
                    $remainingData = $remainingSend;
                    while ($remainingData > 0) {
                        if ($remainingData > strlen($payloadChunk)) {
                            if ($client->send($payloadChunk) === false) {
                                $errors++;
                                break 2;
                            }
                            $remainingData -= strlen($payloadChunk);
                        } else {
                            $chunk = $payloadRemainder !== '' ? $payloadRemainder : substr($payloadChunk, 0, $remainingData);
                            if ($client->send($chunk) === false) {
                                $errors++;
                                break 2;
                            }
                            $remainingData = 0;
                        }
                    }
                    if ($payloadTail !== '') {
                        if ($client->send($payloadTail) === false) {
                            $errors++;
                            break;
                        }
                    }

                    $received = 0;
                    while ($received < $chunkBytes) {
                        $chunk = $client->recv(min(65536, $chunkBytes - $received));
                        if ($chunk === '' || $chunk === false) {
                            $errors++;
                            break 2;
                        }
                        $received += strlen($chunk);
                    }

                    $bytes += $chunkBytes;
                    $ops++;
                }

                $client->close();

                $channel->push([
                    'bytes' => $bytes,
                    'ops' => $ops,
                    'errors' => $errors,
                ]);
            });
        }

        $totalBytes = 0;
        $totalOps = 0;

        for ($i = 0; $i < $concurrent; $i++) {
            $result = $channel->pop();
            $totalBytes += $result['bytes'];
            $totalOps += $result['ops'];
            $errors += $result['errors'];
        }

        $totalTime = microtime(true) - $startTime;
        if ($totalTime <= 0) {
            $totalTime = 0.0001;
        }

        $throughput = $totalBytes / $totalTime;
        $throughputGb = $throughput / (1024 * 1024 * 1024);
        $opsPerSec = $totalOps / $totalTime;
        $connPerSec = $connections / $totalTime;

        echo "\nResults:\n";
        echo "========\n";
        echo sprintf("Total time: %.2fs\n", $totalTime);
        echo sprintf("Connections/sec: %.2f\n", $connPerSec);
        echo sprintf("Ops/sec: %.2f\n", $opsPerSec);
        echo sprintf("Throughput: %.4f GB/s\n", $throughputGb);
        echo sprintf("Errors: %d\n", $errors);

        return;
    }

    // Spawn concurrent workers
    for ($i = 0; $i < $concurrent; $i++) {
        $workerConnections = $perWorker + ($i < $remainder ? 1 : 0);
        Coroutine::create(function () use (
            $host,
            $port,
            $workerConnections,
            $timeout,
            $payloadBytes,
            $payloadDataBytes,
            $payloadChunk,
            $payloadRemainder,
            $payloadSuffix,
            $sampleEvery,
            $handshake,
            $channel
        ) {
            $count = 0;
            $sum = 0.0;
            $min = INF;
            $max = 0.0;
            $errors = 0;
            $bytes = 0;
            $samples = [];

            if ($workerConnections < 1) {
                $channel->push([
                    'count' => 0,
                    'sum' => 0.0,
                    'min' => INF,
                    'max' => 0.0,
                    'errors' => 0,
                    'bytes' => 0,
                    'samples' => [],
                ]);

                return;
            }

            for ($j = 0; $j < $workerConnections; $j++) {
                $connStart = microtime(true);

                $client = new Client(SWOOLE_SOCK_TCP);
                $client->set([
                    'timeout' => $timeout,
                ]);

                if (! $client->connect($host, $port, $timeout)) {
                    $errors++;
                    $latency = (microtime(true) - $connStart) * 1000;
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

                    continue;
                }

                $client->send($handshake);
                $response = $client->recv(8192);

                if ($payloadBytes > 0) {
                    $remaining = $payloadDataBytes;
                    while ($remaining > 0) {
                        if ($remaining > strlen($payloadChunk)) {
                            $client->send($payloadChunk);
                            $remaining -= strlen($payloadChunk);
                        } else {
                            $chunk = $payloadRemainder !== '' ? $payloadRemainder : substr($payloadChunk, 0, $remaining);
                            $client->send($chunk);
                            $remaining = 0;
                        }
                    }
                    if ($payloadSuffix !== '') {
                        $client->send($payloadSuffix);
                    }

                    $received = 0;
                    while ($received < $payloadBytes) {
                        $chunk = $client->recv(min(65536, $payloadBytes - $received));
                        if ($chunk === '' || $chunk === false) {
                            $errors++;
                            break;
                        }
                        $received += strlen($chunk);
                    }
                    $bytes += $received;
                }

                $latency = (microtime(true) - $connStart) * 1000;
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

                if ($response === '' || $response === false) {
                    $errors++;
                }

                $client->close();
            }

            $channel->push([
                'count' => $count,
                'sum' => $sum,
                'min' => $min,
                'max' => $max,
                'errors' => $errors,
                'bytes' => $bytes,
                'samples' => $samples,
            ]);
        });
    }

    $totalCount = 0;
    $sum = 0.0;
    $min = INF;
    $max = 0.0;
    $bytes = 0;
    $samples = [];

    for ($i = 0; $i < $concurrent; $i++) {
        $result = $channel->pop();
        $totalCount += $result['count'];
        $sum += $result['sum'];
        $errors += $result['errors'];
        $bytes += $result['bytes'];
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
        echo "No connections completed.\n";

        return;
    }

    $connPerSec = $totalCount / $totalTime;
    $avgLatency = $sum / $totalCount;

    sort($samples);
    $sampleCount = count($samples);
    $p50 = $sampleCount ? $samples[(int) floor($sampleCount * 0.5)] : 0.0;
    $p95 = $sampleCount ? $samples[(int) floor($sampleCount * 0.95)] : 0.0;
    $p99 = $sampleCount ? $samples[(int) floor($sampleCount * 0.99)] : 0.0;
    $throughputGb = $bytes > 0 ? ($bytes / $totalTime / 1024 / 1024 / 1024) : 0.0;

    echo "\nResults:\n";
    echo "========\n";
    echo sprintf("Total time: %.2fs\n", $totalTime);
    echo sprintf("Connections/sec: %.0f\n", $connPerSec);
    if ($bytes > 0) {
        echo sprintf("Throughput: %.2f GB/s\n", $throughputGb);
    }
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
        "Connections/sec goal: 100k+... %s\n",
        $connPerSec >= 100000 ? '✓ PASS' : '✗ FAIL'
    );
    echo sprintf(
        "Forwarding overhead goal: <1ms... %s\n",
        $avgLatency < 1.0 ? '✓ PASS' : '✗ FAIL'
    );
});
