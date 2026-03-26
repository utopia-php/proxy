<?php

declare(strict_types=1);

namespace Utopia\Tests\Performance;

use PHPUnit\Framework\TestCase;

/**
 * Performance benchmark tests for the proxy TCP server.
 *
 * These tests measure throughput, latency, and scalability of the Swoole TCP
 * proxy server. They require a running proxy server and should only be run
 * with PERF_TEST_ENABLED=1.
 *
 * Run with: phpunit --group performance
 *
 * Environment variables:
 *   PERF_TEST_ENABLED=1          Required to run these tests
 *   PERF_PROXY_HOST              Proxy host (default: 127.0.0.1)
 *   PERF_PROXY_PORT              Proxy PostgreSQL port (default: 5432)
 *   PERF_PROXY_MYSQL_PORT        Proxy MySQL port (default: 3306)
 *   PERF_ITERATIONS              Number of iterations per benchmark (default: 1000)
 *   PERF_WARMUP_ITERATIONS       Number of warmup iterations (default: 100)
 *   PERF_DATABASE_ID             Resource ID for resolver (default: test-db)
 *   PERF_TARGET_CONN_RATE        Target connections/sec (default: 10000)
 *   PERF_MAX_CONNECTIONS          Max connections for exhaustion test (default: 10000)
 *
 * Architecture note:
 *   These tests connect to a running Swoole TCP proxy server via raw TCP sockets.
 *   The proxy server must be started separately before running these tests. The
 *   tests use the PostgreSQL wire protocol to communicate through the proxy.
 *
 * @group performance
 */
final class PerformanceTest extends TestCase
{
    private string $host;
    private int $port;
    private int $iterations;
    private int $warmupIterations;
    private string $resourceId;
    private int $targetConnRate;
    private int $maxConnections;

    /**
     * Collected benchmark results for structured output.
     *
     * @var array<string, array{metric: string, value: float, unit: string, target: float|null, passed: bool|null}>
     */
    private static array $results = [];

    public static function tearDownAfterClass(): void
    {
        if (empty(self::$results)) {
            return;
        }

        echo "\n";
        echo "=================================================================\n";
        echo "  PERFORMANCE BENCHMARK RESULTS\n";
        echo "=================================================================\n";
        echo sprintf("%-35s %15s %10s %10s\n", 'Metric', 'Value', 'Target', 'Status');
        echo "-----------------------------------------------------------------\n";

        foreach (self::$results as $name => $result) {
            $targetStr = $result['target'] !== null
                ? sprintf('%.2f', $result['target'])
                : 'N/A';

            $statusStr = match ($result['passed']) {
                true => 'PASS',
                false => 'FAIL',
                null => '-',
            };

            echo sprintf(
                "%-35s %12.2f %s %10s %10s\n",
                $name,
                $result['value'],
                $result['unit'],
                $targetStr,
                $statusStr,
            );
        }

        echo "=================================================================\n\n";
    }

    protected function setUp(): void
    {
        if (empty(getenv('PERF_TEST_ENABLED'))) {
            $this->markTestSkipped('Performance tests disabled. Set PERF_TEST_ENABLED=1 to run.');
        }

        $this->host = getenv('PERF_PROXY_HOST') ?: '127.0.0.1';
        $this->port = (int) (getenv('PERF_PROXY_PORT') ?: 5432);
        $this->iterations = (int) (getenv('PERF_ITERATIONS') ?: 1000);
        $this->warmupIterations = (int) (getenv('PERF_WARMUP_ITERATIONS') ?: 100);
        $this->resourceId = getenv('PERF_DATABASE_ID') ?: 'test-db';
        $this->targetConnRate = (int) (getenv('PERF_TARGET_CONN_RATE') ?: 10000);
        $this->maxConnections = (int) (getenv('PERF_MAX_CONNECTIONS') ?: 10000);
    }

    /**
     * Measure how many TCP connections per second can be established
     * and complete the PostgreSQL startup handshake through the proxy.
     */
    public function testConnectionRate(): void
    {
        self::log("Measuring connection rate (target: >{$this->targetConnRate}/sec)");

        // Warmup
        for ($i = 0; $i < $this->warmupIterations; $i++) {
            $sock = $this->connectAndStartup();
            if ($sock !== false) {
                fclose($sock);
            }
        }

        // Benchmark
        $successful = 0;
        $failed = 0;
        $start = hrtime(true);

        for ($i = 0; $i < $this->iterations; $i++) {
            $sock = $this->connectAndStartup();
            if ($sock !== false) {
                $successful++;
                fclose($sock);
            } else {
                $failed++;
            }
        }

        $elapsed = (hrtime(true) - $start) / 1e9; // seconds
        $rate = $successful / $elapsed;

        self::log(sprintf(
            "Connection rate: %.0f/sec (%d successful, %d failed in %.3fs)",
            $rate,
            $successful,
            $failed,
            $elapsed,
        ));

        $this->recordResult('connection_rate', $rate, '/sec', $this->targetConnRate);

        $this->assertGreaterThan(0, $successful, 'Should establish at least one connection');
        $this->assertGreaterThan(
            $this->targetConnRate,
            $rate,
            sprintf('Connection rate %.0f/sec is below target %d/sec', $rate, $this->targetConnRate),
        );
    }

    /**
     * Measure queries per second through the proxy by sending PostgreSQL
     * simple query protocol messages and counting responses.
     */
    public function testQueryThroughput(): void
    {
        self::log("Measuring query throughput over {$this->iterations} queries");

        $sock = $this->connectAndStartup();
        $this->assertNotFalse($sock, 'Failed to establish connection for throughput test');

        // Read and discard the startup response
        $this->readResponse($sock, 1.0);

        // Warmup
        for ($i = 0; $i < $this->warmupIterations; $i++) {
            $this->sendSimpleQuery($sock, 'SELECT 1');
            $this->readResponse($sock, 1.0);
        }

        // Benchmark
        $successful = 0;
        $start = hrtime(true);

        for ($i = 0; $i < $this->iterations; $i++) {
            $this->sendSimpleQuery($sock, 'SELECT 1');
            $response = $this->readResponse($sock, 1.0);
            if ($response !== false && strlen($response) > 0) {
                $successful++;
            }
        }

        $elapsed = (hrtime(true) - $start) / 1e9;
        $qps = $successful / $elapsed;
        $avgLatencyUs = ($elapsed / $successful) * 1e6;

        fclose($sock);

        self::log(sprintf(
            "Query throughput: %.0f QPS (%.1f us avg latency, %d/%d successful in %.3fs)",
            $qps,
            $avgLatencyUs,
            $successful,
            $this->iterations,
            $elapsed,
        ));

        $this->recordResult('query_throughput', $qps, 'QPS', null);
        $this->recordResult('query_avg_latency', $avgLatencyUs, 'us', null);

        $this->assertGreaterThan(0, $successful, 'Should complete at least one query');
    }

    /**
     * Measure time from first connection to first query response. This includes
     * the resolver lookup, backend connection establishment, and initial handshake.
     */
    public function testColdStartLatency(): void
    {
        self::log("Measuring cold start latency");

        // Run multiple cold starts and compute percentiles
        $latencies = [];
        $attempts = min($this->iterations, 50); // Cold starts are expensive

        for ($i = 0; $i < $attempts; $i++) {
            $start = hrtime(true);

            $sock = $this->connectAndStartup();
            if ($sock === false) {
                continue;
            }

            // Read startup response
            $startupResponse = $this->readResponse($sock, 5.0);

            // Send first query
            $this->sendSimpleQuery($sock, 'SELECT 1');
            $queryResponse = $this->readResponse($sock, 5.0);

            $elapsed = (hrtime(true) - $start) / 1e6; // milliseconds

            if ($queryResponse !== false) {
                $latencies[] = $elapsed;
            }

            fclose($sock);
        }

        $this->assertNotEmpty($latencies, 'Should complete at least one cold start');

        sort($latencies);
        $count = count($latencies);
        $p50 = $latencies[(int) ($count * 0.5)];
        $p95 = $latencies[(int) ($count * 0.95)];
        $p99 = $latencies[min((int) ($count * 0.99), $count - 1)];
        $avg = array_sum($latencies) / $count;

        self::log(sprintf(
            "Cold start latency: avg=%.2fms p50=%.2fms p95=%.2fms p99=%.2fms (%d samples)",
            $avg,
            $p50,
            $p95,
            $p99,
            $count,
        ));

        $this->recordResult('cold_start_avg', $avg, 'ms', null);
        $this->recordResult('cold_start_p50', $p50, 'ms', null);
        $this->recordResult('cold_start_p95', $p95, 'ms', null);
        $this->recordResult('cold_start_p99', $p99, 'ms', null);
    }

    /**
     * Measure the time to detect backend failure and establish a new connection.
     * This simulates what happens when the resolver returns a different backend
     * after the current one goes down.
     *
     * Note: This test measures the client-side reconnection overhead, not the
     * resolver failover itself (which depends on external state).
     */
    public function testFailoverLatency(): void
    {
        self::log("Measuring failover/reconnection latency");

        $latencies = [];
        $attempts = min($this->iterations, 100);

        for ($i = 0; $i < $attempts; $i++) {
            // Establish initial connection
            $sock = $this->connectAndStartup();
            if ($sock === false) {
                continue;
            }

            $this->readResponse($sock, 1.0);

            // Close the connection (simulates backend going away)
            fclose($sock);

            // Measure reconnection time
            $start = hrtime(true);

            $newSock = $this->connectAndStartup();
            if ($newSock === false) {
                continue;
            }

            $reconnectResponse = $this->readResponse($newSock, 5.0);
            $elapsed = (hrtime(true) - $start) / 1e6; // milliseconds

            if ($reconnectResponse !== false) {
                $latencies[] = $elapsed;
            }

            fclose($newSock);
        }

        $this->assertNotEmpty($latencies, 'Should complete at least one reconnection');

        sort($latencies);
        $count = count($latencies);
        $p50 = $latencies[(int) ($count * 0.5)];
        $p95 = $latencies[(int) ($count * 0.95)];
        $avg = array_sum($latencies) / $count;

        self::log(sprintf(
            "Failover latency: avg=%.2fms p50=%.2fms p95=%.2fms (%d samples)",
            $avg,
            $p50,
            $p95,
            $count,
        ));

        $this->recordResult('failover_avg', $avg, 'ms', null);
        $this->recordResult('failover_p50', $p50, 'ms', null);
        $this->recordResult('failover_p95', $p95, 'ms', null);
    }

    /**
     * Send increasingly large payloads (1KB, 10KB, 100KB, 1MB, 10MB) through
     * the proxy and measure throughput at each size.
     */
    public function testLargePayloadThroughput(): void
    {
        $sizes = [
            '1KB' => 1024,
            '10KB' => 10 * 1024,
            '100KB' => 100 * 1024,
            '1MB' => 1024 * 1024,
            '10MB' => 10 * 1024 * 1024,
        ];

        foreach ($sizes as $label => $size) {
            self::log("Testing payload throughput at {$label}");

            $sock = $this->connectAndStartup();
            if ($sock === false) {
                self::log("  Skipping {$label}: connection failed");
                continue;
            }

            // Read startup response
            $this->readResponse($sock, 2.0);

            // Build a query payload of the target size
            // Use a PostgreSQL simple query with a large string literal
            $padding = str_repeat('X', max(0, $size - 64));
            $query = "SELECT '{$padding}'";

            $iterationsForSize = match (true) {
                $size <= 1024 => 500,
                $size <= 10240 => 200,
                $size <= 102400 => 50,
                $size <= 1048576 => 10,
                default => 3,
            };

            $totalBytes = 0;
            $successful = 0;
            $start = hrtime(true);

            for ($i = 0; $i < $iterationsForSize; $i++) {
                $this->sendSimpleQuery($sock, $query);
                $response = $this->readResponse($sock, 10.0);
                if ($response !== false) {
                    $totalBytes += strlen($query) + strlen($response);
                    $successful++;
                }
            }

            $elapsed = (hrtime(true) - $start) / 1e9;
            $throughputMBps = ($totalBytes / (1024 * 1024)) / $elapsed;

            fclose($sock);

            self::log(sprintf(
                "  %s: %.2f MB/s throughput (%d/%d successful, %.3fs elapsed)",
                $label,
                $throughputMBps,
                $successful,
                $iterationsForSize,
                $elapsed,
            ));

            $this->recordResult("payload_{$label}_throughput", $throughputMBps, 'MB/s', null);

            $this->assertGreaterThan(0, $successful, "Should complete at least one {$label} transfer");
        }
    }

    /**
     * Open connections until the max_connections limit is reached.
     * Verify the proxy handles this gracefully (rejects with an error
     * rather than crashing or hanging).
     */
    public function testConnectionPoolExhaustion(): void
    {
        $targetConnections = min($this->maxConnections, 5000); // Cap for safety
        self::log("Testing connection exhaustion up to {$targetConnections} connections");

        /** @var resource[] $sockets */
        $sockets = [];
        $peakConnections = 0;
        $firstRefusalAt = null;

        for ($i = 0; $i < $targetConnections; $i++) {
            $sock = @stream_socket_client(
                "tcp://{$this->host}:{$this->port}",
                $errno,
                $errstr,
                0.5,
            );

            if ($sock === false) {
                $firstRefusalAt = $i;
                self::log("  Connection refused at connection #{$i}: [{$errno}] {$errstr}");
                break;
            }

            stream_set_timeout($sock, 0, 100000); // 100ms timeout
            $sockets[] = $sock;
            $peakConnections = $i + 1;

            // Log progress every 1000 connections
            if ($peakConnections % 1000 === 0) {
                self::log("  Opened {$peakConnections} connections...");
            }
        }

        self::log(sprintf(
            "Peak connections: %d (refusal at: %s)",
            $peakConnections,
            $firstRefusalAt !== null ? "#{$firstRefusalAt}" : 'none',
        ));

        $this->recordResult('peak_connections', (float) $peakConnections, 'conn', null);

        // Verify we can still connect after closing some connections
        $closedCount = min(100, count($sockets));
        for ($i = 0; $i < $closedCount; $i++) {
            $sock = array_pop($sockets);
            if ($sock !== null) {
                fclose($sock);
            }
        }

        // Small delay for the proxy to process disconnections
        usleep(100000); // 100ms

        $recoverySock = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            2.0,
        );

        if ($recoverySock !== false) {
            self::log("  Recovery connection successful after releasing {$closedCount} connections");
            fclose($recoverySock);
        } else {
            self::log("  Recovery connection failed: [{$errno}] {$errstr}");
        }

        // Clean up remaining sockets
        foreach ($sockets as $sock) {
            fclose($sock);
        }

        $this->assertGreaterThan(0, $peakConnections, 'Should open at least one connection');

        // If we hit refusal, verify it was at a reasonable point
        if ($firstRefusalAt !== null) {
            $this->assertGreaterThan(
                10,
                $firstRefusalAt,
                'Proxy should handle at least 10 connections before refusing',
            );
        }
    }

    /**
     * Measure query latency with 10, 100, 1000, and 10000 concurrent connections
     * to observe how the proxy scales under increasing load.
     */
    public function testConcurrentConnectionScaling(): void
    {
        $concurrencyLevels = [10, 100, 1000, 10000];

        foreach ($concurrencyLevels as $level) {
            if ($level > $this->maxConnections) {
                self::log("Skipping concurrency level {$level} (exceeds max {$this->maxConnections})");
                continue;
            }

            self::log("Testing with {$level} concurrent connections");

            // Establish connections
            /** @var resource[] $sockets */
            $sockets = [];
            $established = 0;

            for ($i = 0; $i < $level; $i++) {
                $sock = @stream_socket_client(
                    "tcp://{$this->host}:{$this->port}",
                    $errno,
                    $errstr,
                    1.0,
                );

                if ($sock === false) {
                    break;
                }

                stream_set_timeout($sock, 1);
                stream_set_blocking($sock, false);
                $sockets[] = $sock;
                $established++;
            }

            if ($established < $level) {
                self::log("  Only established {$established}/{$level} connections");
            }

            if ($established === 0) {
                self::log("  No connections established, skipping");
                $this->recordResult("latency_at_{$level}", 0, 'ms', null);
                continue;
            }

            // Send startup on all connections
            foreach ($sockets as $sock) {
                stream_set_blocking($sock, true);
                stream_set_timeout($sock, 1);
                $startupMsg = $this->buildStartupMessage($this->resourceId);
                @fwrite($sock, $startupMsg);
            }

            // Small settle time
            usleep(50000);

            // Measure round-trip latency on a sample of connections
            $sampleSize = min(100, $established);
            $sampleSockets = array_slice($sockets, 0, $sampleSize);
            $latencies = [];

            foreach ($sampleSockets as $sock) {
                stream_set_blocking($sock, true);
                stream_set_timeout($sock, 1);

                // Drain any pending data
                $this->readResponse($sock, 0.1);

                $start = hrtime(true);
                $this->sendSimpleQuery($sock, 'SELECT 1');
                $response = $this->readResponse($sock, 2.0);
                $elapsed = (hrtime(true) - $start) / 1e6;

                if ($response !== false && strlen($response) > 0) {
                    $latencies[] = $elapsed;
                }
            }

            // Clean up
            foreach ($sockets as $sock) {
                @fclose($sock);
            }

            if (!empty($latencies)) {
                sort($latencies);
                $count = count($latencies);
                $avg = array_sum($latencies) / $count;
                $p50 = $latencies[(int) ($count * 0.5)];
                $p99 = $latencies[min((int) ($count * 0.99), $count - 1)];

                self::log(sprintf(
                    "  %d conns: avg=%.2fms p50=%.2fms p99=%.2fms (%d samples)",
                    $level,
                    $avg,
                    $p50,
                    $p99,
                    $count,
                ));

                $this->recordResult("latency_at_{$level}_avg", $avg, 'ms', null);
                $this->recordResult("latency_at_{$level}_p99", $p99, 'ms', null);
            } else {
                self::log("  No successful queries at {$level} concurrency");
                $this->recordResult("latency_at_{$level}_avg", 0, 'ms', null);
            }
        }

        // At minimum, the lowest concurrency level should work
        $this->assertArrayHasKey('latency_at_10_avg', self::$results);
    }

    /**
     * Build a PostgreSQL StartupMessage with the database name encoding the
     * database ID for the proxy resolver.
     *
     * Wire format:
     *   Int32 length (includes self)
     *   Int32 protocol version (3.0 = 196608)
     *   String "user" \0 String <user> \0
     *   String "database" \0 String "db-<id>" \0
     *   \0 (terminator)
     */
    private function buildStartupMessage(string $resourceId): string
    {
        $params = "user\x00appwrite\x00database\x00db-{$resourceId}\x00\x00";
        $protocolVersion = pack('N', 196608); // 3.0
        $length = 4 + strlen($protocolVersion) + strlen($params);

        return pack('N', $length) . $protocolVersion . $params;
    }

    /**
     * Build a PostgreSQL Simple Query message.
     *
     * Wire format:
     *   Byte1 'Q'
     *   Int32 length (includes self but not message type)
     *   String query \0
     */
    private function buildSimpleQueryMessage(string $query): string
    {
        $queryWithNull = $query . "\x00";
        $length = 4 + strlen($queryWithNull);

        return 'Q' . pack('N', $length) . $queryWithNull;
    }

    /**
     * Connect to the proxy and send a PostgreSQL startup message.
     *
     * @return resource|false Socket resource on success, false on failure
     */
    private function connectAndStartup(): mixed
    {
        $sock = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            2.0,
        );

        if ($sock === false) {
            return false;
        }

        stream_set_timeout($sock, 5);

        $startupMsg = $this->buildStartupMessage($this->resourceId);
        $written = @fwrite($sock, $startupMsg);

        if ($written === false || $written === 0) {
            fclose($sock);
            return false;
        }

        return $sock;
    }

    /**
     * Send a PostgreSQL simple query on an established connection.
     *
     * @param resource $sock
     */
    private function sendSimpleQuery($sock, string $query): bool
    {
        $msg = $this->buildSimpleQueryMessage($query);
        $written = @fwrite($sock, $msg);

        return $written !== false && $written > 0;
    }

    /**
     * Read a response from the proxy with a timeout.
     *
     * @param resource $sock
     * @return string|false Response data or false on failure/timeout
     */
    private function readResponse($sock, float $timeoutSeconds): string|false
    {
        $timeoutSec = (int) $timeoutSeconds;
        $timeoutUsec = (int) (($timeoutSeconds - $timeoutSec) * 1e6);
        stream_set_timeout($sock, $timeoutSec, $timeoutUsec);

        $data = @fread($sock, 65536);

        if ($data === false || $data === '') {
            $meta = stream_get_meta_data($sock);
            if ($meta['timed_out']) {
                return false;
            }
            return false;
        }

        return $data;
    }

    /**
     * Record a benchmark result for the summary table.
     */
    private function recordResult(string $name, float $value, string $unit, ?float $target): void
    {
        $passed = null;
        if ($target !== null) {
            // For rates/throughput, higher is better
            if (str_contains($unit, '/sec') || str_contains($unit, 'QPS') || str_contains($unit, 'MB/s')) {
                $passed = $value >= $target;
            } else {
                // For latency, lower is better
                $passed = $value <= $target;
            }
        }

        self::$results[$name] = [
            'metric' => $name,
            'value' => $value,
            'unit' => $unit,
            'target' => $target,
            'passed' => $passed,
        ];
    }

    /**
     * Log a message with timestamp.
     */
    private static function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        echo "[{$timestamp}] [PERF] {$message}\n";
    }
}
