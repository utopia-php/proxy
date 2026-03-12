<?php

namespace Utopia\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Adapter\TCP as TCPAdapter;
use Utopia\Proxy\ConnectionResult;
use Utopia\Proxy\Protocol;
use Utopia\Proxy\Resolver;
use Utopia\Proxy\Resolver\Exception as ResolverException;
use Utopia\Proxy\Resolver\ReadWriteResolver;
use Utopia\Proxy\Resolver\Result;
use Utopia\Query\Type as QueryType;

/**
 * Integration test for the protocol-proxy's ability to resolve database
 * connections via an Edge-like adapter pattern.
 *
 * These tests simulate the full resolution flow that occurs in production
 * when the protocol-proxy calls the Edge service to resolve a database ID
 * to a backend endpoint containing host, port, username, and password.
 *
 * @group integration
 */
class EdgeIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to run integration tests.');
        }
    }

    // ---------------------------------------------------------------
    // 1. Full Resolution Flow
    // ---------------------------------------------------------------

    /**
     * @group integration
     */
    public function testEdgeResolverResolvesDatabaseIdToEndpoint(): void
    {
        $resolver = new EdgeMockResolver();
        $resolver->registerDatabase('abc123', [
            'host' => '10.0.1.50',
            'port' => 5432,
            'username' => 'appwrite_user',
            'password' => 'secret_password',
        ]);

        $adapter = new TCPAdapter($resolver, port: 5432);
        $adapter->setSkipValidation(true);

        $result = $adapter->route('abc123');

        $this->assertInstanceOf(ConnectionResult::class, $result);
        $this->assertSame('10.0.1.50:5432', $result->endpoint);
        $this->assertSame(Protocol::PostgreSQL, $result->protocol);
        $this->assertSame('abc123', $result->metadata['resourceId']);
        $this->assertSame('appwrite_user', $result->metadata['username']);
        $this->assertFalse($result->metadata['cached']);
    }

    /**
     * @group integration
     */
    public function testEdgeResolverReturnsNotFoundForUnknownDatabase(): void
    {
        $resolver = new EdgeMockResolver();

        $adapter = new TCPAdapter($resolver, port: 5432);
        $adapter->setSkipValidation(true);

        $this->expectException(ResolverException::class);
        $this->expectExceptionCode(ResolverException::NOT_FOUND);

        $adapter->route('nonexistent');
    }

    /**
     * @group integration
     */
    public function testDatabaseIdExtractionFeedsIntoResolution(): void
    {
        $resolver = new EdgeMockResolver();
        $resolver->registerDatabase('abc123', [
            'host' => '10.0.1.50',
            'port' => 5432,
            'username' => 'user1',
            'password' => 'pass1',
        ]);

        $adapter = new TCPAdapter($resolver, port: 5432);
        $adapter->setSkipValidation(true);

        // Simulate PostgreSQL startup message containing "database\0db-abc123\0"
        $startupData = "user\x00appwrite\x00database\x00db-abc123\x00";

        $databaseId = $adapter->parseDatabaseId($startupData, 1);
        $this->assertSame('abc123', $databaseId);

        $result = $adapter->route($databaseId);
        $this->assertSame('10.0.1.50:5432', $result->endpoint);
    }

    /**
     * @group integration
     */
    public function testMysqlDatabaseIdExtractionFeedsIntoResolution(): void
    {
        $resolver = new EdgeMockResolver();
        $resolver->registerDatabase('xyz789', [
            'host' => '10.0.2.30',
            'port' => 3306,
            'username' => 'mysql_user',
            'password' => 'mysql_pass',
        ]);

        $adapter = new TCPAdapter($resolver, port: 3306);
        $adapter->setSkipValidation(true);

        // Simulate MySQL COM_INIT_DB packet
        $mysqlData = "\x00\x00\x00\x00\x02db-xyz789";

        $databaseId = $adapter->parseDatabaseId($mysqlData, 1);
        $this->assertSame('xyz789', $databaseId);

        $result = $adapter->route($databaseId);
        $this->assertSame('10.0.2.30:3306', $result->endpoint);
        $this->assertSame(Protocol::MySQL, $result->protocol);
    }

    // ---------------------------------------------------------------
    // 2. Read/Write Split Resolution
    // ---------------------------------------------------------------

    /**
     * @group integration
     */
    public function testReadWriteSplitResolvesToDifferentEndpoints(): void
    {
        $resolver = new EdgeMockReadWriteResolver();
        $resolver->registerDatabase('rw123', [
            'host' => '10.0.1.10',
            'port' => 5432,
            'username' => 'user',
            'password' => 'pass',
        ]);
        $resolver->registerReadReplica('rw123', [
            'host' => '10.0.1.20',
            'port' => 5432,
            'username' => 'replica_user',
            'password' => 'replica_pass',
        ]);
        $resolver->registerWritePrimary('rw123', [
            'host' => '10.0.1.10',
            'port' => 5432,
            'username' => 'primary_user',
            'password' => 'primary_pass',
        ]);

        $adapter = new TCPAdapter($resolver, port: 5432);
        $adapter->setReadWriteSplit(true);
        $adapter->setSkipValidation(true);

        $readResult = $adapter->routeQuery('rw123', QueryType::Read);
        $this->assertSame('10.0.1.20:5432', $readResult->endpoint);
        $this->assertSame('read', $readResult->metadata['route']);

        $writeResult = $adapter->routeQuery('rw123', QueryType::Write);
        $this->assertSame('10.0.1.10:5432', $writeResult->endpoint);
        $this->assertSame('write', $writeResult->metadata['route']);

        // Endpoints must be different
        $this->assertNotSame($readResult->endpoint, $writeResult->endpoint);
    }

    /**
     * @group integration
     */
    public function testReadWriteSplitDisabledUsesDefaultEndpoint(): void
    {
        $resolver = new EdgeMockReadWriteResolver();
        $resolver->registerDatabase('rw456', [
            'host' => '10.0.1.99',
            'port' => 5432,
            'username' => 'user',
            'password' => 'pass',
        ]);
        $resolver->registerReadReplica('rw456', [
            'host' => '10.0.1.20',
            'port' => 5432,
            'username' => 'replica_user',
            'password' => 'replica_pass',
        ]);

        $adapter = new TCPAdapter($resolver, port: 5432);
        // read/write split is disabled by default
        $adapter->setSkipValidation(true);

        $readResult = $adapter->routeQuery('rw456', QueryType::Read);
        $this->assertSame('10.0.1.99:5432', $readResult->endpoint);
    }

    /**
     * @group integration
     */
    public function testTransactionPinsReadsToPrimaryThroughFullFlow(): void
    {
        $resolver = new EdgeMockReadWriteResolver();
        $resolver->registerDatabase('txdb', [
            'host' => '10.0.1.10',
            'port' => 5432,
            'username' => 'user',
            'password' => 'pass',
        ]);
        $resolver->registerReadReplica('txdb', [
            'host' => '10.0.1.20',
            'port' => 5432,
            'username' => 'user',
            'password' => 'pass',
        ]);
        $resolver->registerWritePrimary('txdb', [
            'host' => '10.0.1.10',
            'port' => 5432,
            'username' => 'user',
            'password' => 'pass',
        ]);

        $adapter = new TCPAdapter($resolver, port: 5432);
        $adapter->setReadWriteSplit(true);
        $adapter->setSkipValidation(true);

        $clientFd = 42;

        // Before transaction: SELECT goes to read replica
        $selectData = $this->buildPgQuery('SELECT * FROM users');
        $classification = $adapter->classifyQuery($selectData, $clientFd);
        $this->assertSame(QueryType::Read, $classification);

        $result = $adapter->routeQuery('txdb', $classification);
        $this->assertSame('10.0.1.20:5432', $result->endpoint);

        // BEGIN pins to primary
        $beginData = $this->buildPgQuery('BEGIN');
        $classification = $adapter->classifyQuery($beginData, $clientFd);
        $this->assertSame(QueryType::Write, $classification);
        $this->assertTrue($adapter->isConnectionPinned($clientFd));

        // During transaction: SELECT goes to primary (pinned)
        $classification = $adapter->classifyQuery($selectData, $clientFd);
        $this->assertSame(QueryType::Write, $classification);

        $result = $adapter->routeQuery('txdb', $classification);
        $this->assertSame('10.0.1.10:5432', $result->endpoint);

        // COMMIT unpins
        $commitData = $this->buildPgQuery('COMMIT');
        $adapter->classifyQuery($commitData, $clientFd);
        $this->assertFalse($adapter->isConnectionPinned($clientFd));

        // After transaction: SELECT goes back to read replica
        $classification = $adapter->classifyQuery($selectData, $clientFd);
        $this->assertSame(QueryType::Read, $classification);

        $result = $adapter->routeQuery('txdb', $classification);
        $this->assertSame('10.0.1.20:5432', $result->endpoint);
    }

    // ---------------------------------------------------------------
    // 3. Failover Behavior
    // ---------------------------------------------------------------

    /**
     * @group integration
     */
    public function testFailoverResolverUsesSecondaryOnPrimaryFailure(): void
    {
        $primaryResolver = new EdgeMockResolver();
        // Primary has no databases registered, so it will throw NOT_FOUND

        $secondaryResolver = new EdgeMockResolver();
        $secondaryResolver->registerDatabase('faildb', [
            'host' => '10.0.2.50',
            'port' => 5432,
            'username' => 'failover_user',
            'password' => 'failover_pass',
        ]);

        $failoverResolver = new EdgeFailoverResolver($primaryResolver, $secondaryResolver);

        $adapter = new TCPAdapter($failoverResolver, port: 5432);
        $adapter->setSkipValidation(true);

        $result = $adapter->route('faildb');

        $this->assertSame('10.0.2.50:5432', $result->endpoint);
        $this->assertTrue($failoverResolver->didFailover());
    }

    /**
     * @group integration
     */
    public function testFailoverResolverUsesPrimaryWhenAvailable(): void
    {
        $primaryResolver = new EdgeMockResolver();
        $primaryResolver->registerDatabase('okdb', [
            'host' => '10.0.1.10',
            'port' => 5432,
            'username' => 'primary_user',
            'password' => 'primary_pass',
        ]);

        $secondaryResolver = new EdgeMockResolver();
        $secondaryResolver->registerDatabase('okdb', [
            'host' => '10.0.2.50',
            'port' => 5432,
            'username' => 'secondary_user',
            'password' => 'secondary_pass',
        ]);

        $failoverResolver = new EdgeFailoverResolver($primaryResolver, $secondaryResolver);

        $adapter = new TCPAdapter($failoverResolver, port: 5432);
        $adapter->setSkipValidation(true);

        $result = $adapter->route('okdb');

        $this->assertSame('10.0.1.10:5432', $result->endpoint);
        $this->assertFalse($failoverResolver->didFailover());
    }

    /**
     * @group integration
     */
    public function testFailoverResolverPropagatesErrorWhenBothFail(): void
    {
        $primaryResolver = new EdgeMockResolver();
        $secondaryResolver = new EdgeMockResolver();
        // Neither has databases registered

        $failoverResolver = new EdgeFailoverResolver($primaryResolver, $secondaryResolver);

        $adapter = new TCPAdapter($failoverResolver, port: 5432);
        $adapter->setSkipValidation(true);

        $this->expectException(ResolverException::class);
        $this->expectExceptionCode(ResolverException::NOT_FOUND);

        $adapter->route('nowhere');
    }

    /**
     * @group integration
     */
    public function testFailoverResolverHandlesUnavailablePrimary(): void
    {
        $primaryResolver = new EdgeMockResolver();
        $primaryResolver->setUnavailable(true);

        $secondaryResolver = new EdgeMockResolver();
        $secondaryResolver->registerDatabase('unavaildb', [
            'host' => '10.0.3.10',
            'port' => 5432,
            'username' => 'backup_user',
            'password' => 'backup_pass',
        ]);

        $failoverResolver = new EdgeFailoverResolver($primaryResolver, $secondaryResolver);

        $adapter = new TCPAdapter($failoverResolver, port: 5432);
        $adapter->setSkipValidation(true);

        $result = $adapter->route('unavaildb');

        $this->assertSame('10.0.3.10:5432', $result->endpoint);
        $this->assertTrue($failoverResolver->didFailover());
    }

    // ---------------------------------------------------------------
    // 4. Connection Caching/Pooling
    // ---------------------------------------------------------------

    /**
     * @group integration
     */
    public function testRoutingCacheReturnsCachedResultOnRepeat(): void
    {
        $resolver = new EdgeMockResolver();
        $resolver->registerDatabase('cachedb', [
            'host' => '10.0.4.10',
            'port' => 5432,
            'username' => 'cached_user',
            'password' => 'cached_pass',
        ]);

        $adapter = new TCPAdapter($resolver, port: 5432);
        $adapter->setSkipValidation(true);

        // Ensure we are at the start of a fresh second so both calls
        // land within the same 1-second cache window
        $start = time();
        while (time() === $start) {
            usleep(1000);
        }

        $first = $adapter->route('cachedb');
        $this->assertFalse($first->metadata['cached']);

        $second = $adapter->route('cachedb');
        $this->assertTrue($second->metadata['cached']);

        $this->assertSame($first->endpoint, $second->endpoint);
        $this->assertSame(1, $resolver->getResolveCount());
    }

    /**
     * @group integration
     */
    public function testCacheInvalidationForcesReResolve(): void
    {
        $resolver = new EdgeMockResolver();
        $resolver->registerDatabase('invaldb', [
            'host' => '10.0.4.20',
            'port' => 5432,
            'username' => 'user',
            'password' => 'pass',
        ]);

        $adapter = new TCPAdapter($resolver, port: 5432);
        $adapter->setSkipValidation(true);

        // Align to second boundary
        $start = time();
        while (time() === $start) {
            usleep(1000);
        }

        $first = $adapter->route('invaldb');
        $this->assertFalse($first->metadata['cached']);

        // Invalidate the resolver cache
        $resolver->purge('invaldb');

        // Wait for the routing table cache to expire (1 second TTL)
        sleep(2);

        $second = $adapter->route('invaldb');
        $this->assertFalse($second->metadata['cached']);

        // Should have resolved twice
        $this->assertSame(2, $resolver->getResolveCount());
    }

    /**
     * @group integration
     */
    public function testDifferentDatabasesResolveIndependently(): void
    {
        $resolver = new EdgeMockResolver();
        $resolver->registerDatabase('db1', [
            'host' => '10.0.5.1',
            'port' => 5432,
            'username' => 'user1',
            'password' => 'pass1',
        ]);
        $resolver->registerDatabase('db2', [
            'host' => '10.0.5.2',
            'port' => 5432,
            'username' => 'user2',
            'password' => 'pass2',
        ]);

        $adapter = new TCPAdapter($resolver, port: 5432);
        $adapter->setSkipValidation(true);

        $result1 = $adapter->route('db1');
        $result2 = $adapter->route('db2');

        $this->assertSame('10.0.5.1:5432', $result1->endpoint);
        $this->assertSame('10.0.5.2:5432', $result2->endpoint);
        $this->assertNotSame($result1->endpoint, $result2->endpoint);
    }

    // ---------------------------------------------------------------
    // 5. Concurrent Resolution for Multiple Database IDs
    // ---------------------------------------------------------------

    /**
     * @group integration
     */
    public function testConcurrentResolutionOfMultipleDatabases(): void
    {
        $resolver = new EdgeMockResolver();
        $databaseCount = 20;

        for ($i = 1; $i <= $databaseCount; $i++) {
            $resolver->registerDatabase("concurrent{$i}", [
                'host' => "10.0.10.{$i}",
                'port' => 5432,
                'username' => "user_{$i}",
                'password' => "pass_{$i}",
            ]);
        }

        $adapter = new TCPAdapter($resolver, port: 5432);
        $adapter->setSkipValidation(true);

        $results = [];
        for ($i = 1; $i <= $databaseCount; $i++) {
            $results[$i] = $adapter->route("concurrent{$i}");
        }

        // Verify each database resolved to its correct endpoint
        for ($i = 1; $i <= $databaseCount; $i++) {
            $this->assertSame("10.0.10.{$i}:5432", $results[$i]->endpoint);
            $this->assertSame(Protocol::PostgreSQL, $results[$i]->protocol);
        }

        // All should have been cache misses (first resolution)
        $stats = $adapter->getStats();
        $this->assertSame($databaseCount, $stats['cacheMisses']);
        $this->assertSame(0, $stats['cacheHits']);
        $this->assertSame($databaseCount, $stats['routingTableSize']);
    }

    /**
     * @group integration
     */
    public function testConcurrentResolutionWithMixedSuccessAndFailure(): void
    {
        $resolver = new EdgeMockResolver();
        $resolver->registerDatabase('gooddb1', [
            'host' => '10.0.11.1',
            'port' => 5432,
            'username' => 'user',
            'password' => 'pass',
        ]);
        $resolver->registerDatabase('gooddb2', [
            'host' => '10.0.11.2',
            'port' => 5432,
            'username' => 'user',
            'password' => 'pass',
        ]);
        // 'baddb' is intentionally not registered

        $adapter = new TCPAdapter($resolver, port: 5432);
        $adapter->setSkipValidation(true);

        $result1 = $adapter->route('gooddb1');
        $this->assertSame('10.0.11.1:5432', $result1->endpoint);

        $result2 = $adapter->route('gooddb2');
        $this->assertSame('10.0.11.2:5432', $result2->endpoint);

        try {
            $adapter->route('baddb');
            $this->fail('Expected ResolverException for unknown database');
        } catch (ResolverException $e) {
            $this->assertSame(ResolverException::NOT_FOUND, $e->getCode());
        }

        $stats = $adapter->getStats();
        $this->assertSame(1, $stats['routingErrors']);
        $this->assertSame(2, $stats['connections']);
    }

    // ---------------------------------------------------------------
    // 6. Lifecycle Tracking (connect/disconnect/activity)
    // ---------------------------------------------------------------

    /**
     * @group integration
     */
    public function testConnectAndDisconnectLifecycleTracked(): void
    {
        $resolver = new EdgeMockResolver();
        $resolver->registerDatabase('lifecycle1', [
            'host' => '10.0.6.1',
            'port' => 5432,
            'username' => 'user',
            'password' => 'pass',
        ]);

        $adapter = new TCPAdapter($resolver, port: 5432);
        $adapter->setSkipValidation(true);

        // Resolve the database
        $adapter->route('lifecycle1');

        // Notify connect
        $adapter->notifyConnect('lifecycle1', ['clientFd' => 1]);
        $this->assertCount(1, $resolver->getConnects());
        $this->assertSame('lifecycle1', $resolver->getConnects()[0]['resourceId']);

        // Track activity
        $adapter->setActivityInterval(0);
        $adapter->track('lifecycle1', ['query' => 'SELECT 1']);
        $this->assertCount(1, $resolver->getActivities());

        // Notify disconnect
        $adapter->notifyClose('lifecycle1', ['clientFd' => 1]);
        $this->assertCount(1, $resolver->getDisconnects());
        $this->assertSame('lifecycle1', $resolver->getDisconnects()[0]['resourceId']);
    }

    /**
     * @group integration
     */
    public function testStatsAggregateAcrossOperations(): void
    {
        $resolver = new EdgeMockResolver();
        $resolver->registerDatabase('statsdb', [
            'host' => '10.0.7.1',
            'port' => 5432,
            'username' => 'user',
            'password' => 'pass',
        ]);

        $adapter = new TCPAdapter($resolver, port: 5432);
        $adapter->setSkipValidation(true);

        // Align to second boundary
        $start = time();
        while (time() === $start) {
            usleep(1000);
        }

        // Perform multiple operations
        $adapter->route('statsdb');       // miss
        $adapter->route('statsdb');       // hit
        $adapter->route('statsdb');       // hit

        $adapter->notifyConnect('statsdb');
        $adapter->notifyClose('statsdb');

        $stats = $adapter->getStats();

        $this->assertSame('TCP', $stats['adapter']);
        $this->assertSame('postgresql', $stats['protocol']);
        $this->assertSame(3, $stats['connections']);
        $this->assertSame(2, $stats['cacheHits']);
        $this->assertSame(1, $stats['cacheMisses']);
        $this->assertGreaterThan(0.0, $stats['cacheHitRate']);
        $this->assertSame(0, $stats['routingErrors']);

        /** @var array<string, mixed> $resolverStats */
        $resolverStats = $stats['resolver'];
        $this->assertSame(1, $resolverStats['connects']);
        $this->assertSame(1, $resolverStats['disconnects']);
    }

    // ---------------------------------------------------------------
    // Helper: Build a PostgreSQL Simple Query message
    // ---------------------------------------------------------------

    private function buildPgQuery(string $sql): string
    {
        $body = $sql . "\x00";
        $length = \strlen($body) + 4;

        return 'Q' . \pack('N', $length) . $body;
    }
}

// ---------------------------------------------------------------------------
// Mock Resolvers that simulate Edge HTTP interactions
// ---------------------------------------------------------------------------

/**
 * Simulates an Edge service resolver that resolves database IDs to backend
 * endpoints via HTTP lookups. In production, the resolve() call would be an
 * HTTP request to the Edge service. Here we simulate that with an in-memory
 * registry.
 */
class EdgeMockResolver implements Resolver
{
    /** @var array<string, array{host: string, port: int, username: string, password: string}> */
    protected array $databases = [];

    /** @var array<int, array{resourceId: string, metadata: array<string, mixed>}> */
    protected array $connects = [];

    /** @var array<int, array{resourceId: string, metadata: array<string, mixed>}> */
    protected array $disconnects = [];

    /** @var array<int, array{resourceId: string, metadata: array<string, mixed>}> */
    protected array $activities = [];

    /** @var array<int, string> */
    protected array $invalidations = [];

    protected int $resolveCount = 0;

    protected bool $unavailable = false;

    /**
     * Register a database endpoint (simulates Edge service configuration)
     *
     * @param array{host: string, port: int, username: string, password: string} $config
     */
    public function registerDatabase(string $databaseId, array $config): self
    {
        $this->databases[$databaseId] = $config;

        return $this;
    }

    public function setUnavailable(bool $unavailable): self
    {
        $this->unavailable = $unavailable;

        return $this;
    }

    public function resolve(string $resourceId): Result
    {
        if ($this->unavailable) {
            throw new ResolverException(
                "Edge service unavailable",
                ResolverException::UNAVAILABLE,
                ['resourceId' => $resourceId]
            );
        }

        if (!isset($this->databases[$resourceId])) {
            throw new ResolverException(
                "Database not found: {$resourceId}",
                ResolverException::NOT_FOUND,
                ['resourceId' => $resourceId]
            );
        }

        $this->resolveCount++;
        $config = $this->databases[$resourceId];

        return new Result(
            endpoint: "{$config['host']}:{$config['port']}",
            metadata: [
                'resourceId' => $resourceId,
                'username' => $config['username'],
            ]
        );
    }

    public function onConnect(string $resourceId, array $metadata = []): void
    {
        $this->connects[] = ['resourceId' => $resourceId, 'metadata' => $metadata];
    }

    public function onDisconnect(string $resourceId, array $metadata = []): void
    {
        $this->disconnects[] = ['resourceId' => $resourceId, 'metadata' => $metadata];
    }

    public function track(string $resourceId, array $metadata = []): void
    {
        $this->activities[] = ['resourceId' => $resourceId, 'metadata' => $metadata];
    }

    public function purge(string $resourceId): void
    {
        $this->invalidations[] = $resourceId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return [
            'resolver' => 'edge-mock',
            'connects' => count($this->connects),
            'disconnects' => count($this->disconnects),
            'activities' => count($this->activities),
            'resolveCount' => $this->resolveCount,
        ];
    }

    public function getResolveCount(): int
    {
        return $this->resolveCount;
    }

    /** @return array<int, array{resourceId: string, metadata: array<string, mixed>}> */
    public function getConnects(): array
    {
        return $this->connects;
    }

    /** @return array<int, array{resourceId: string, metadata: array<string, mixed>}> */
    public function getDisconnects(): array
    {
        return $this->disconnects;
    }

    /** @return array<int, array{resourceId: string, metadata: array<string, mixed>}> */
    public function getActivities(): array
    {
        return $this->activities;
    }
}

/**
 * Extends EdgeMockResolver to support read/write split resolution.
 * In production, the Edge service would return different endpoints for
 * read replicas vs the primary writer.
 */
class EdgeMockReadWriteResolver extends EdgeMockResolver implements ReadWriteResolver
{
    /** @var array<string, array{host: string, port: int, username: string, password: string}> */
    protected array $readReplicas = [];

    /** @var array<string, array{host: string, port: int, username: string, password: string}> */
    protected array $writePrimaries = [];

    /**
     * @param array{host: string, port: int, username: string, password: string} $config
     */
    public function registerReadReplica(string $databaseId, array $config): self
    {
        $this->readReplicas[$databaseId] = $config;

        return $this;
    }

    /**
     * @param array{host: string, port: int, username: string, password: string} $config
     */
    public function registerWritePrimary(string $databaseId, array $config): self
    {
        $this->writePrimaries[$databaseId] = $config;

        return $this;
    }

    public function resolveRead(string $resourceId): Result
    {
        if (!isset($this->readReplicas[$resourceId])) {
            throw new ResolverException(
                "Read replica not found: {$resourceId}",
                ResolverException::NOT_FOUND,
                ['resourceId' => $resourceId, 'route' => 'read']
            );
        }

        $config = $this->readReplicas[$resourceId];

        return new Result(
            endpoint: "{$config['host']}:{$config['port']}",
            metadata: [
                'resourceId' => $resourceId,
                'username' => $config['username'],
                'route' => 'read',
            ]
        );
    }

    public function resolveWrite(string $resourceId): Result
    {
        if (!isset($this->writePrimaries[$resourceId])) {
            throw new ResolverException(
                "Write primary not found: {$resourceId}",
                ResolverException::NOT_FOUND,
                ['resourceId' => $resourceId, 'route' => 'write']
            );
        }

        $config = $this->writePrimaries[$resourceId];

        return new Result(
            endpoint: "{$config['host']}:{$config['port']}",
            metadata: [
                'resourceId' => $resourceId,
                'username' => $config['username'],
                'route' => 'write',
            ]
        );
    }
}

/**
 * Failover resolver that tries a primary resolver first and falls back
 * to a secondary resolver if the primary fails. This simulates the
 * production pattern where the Edge service might be unavailable and
 * a secondary backend provides resilience.
 */
class EdgeFailoverResolver implements Resolver
{
    protected bool $failedOver = false;

    /** @var array<int, array{resourceId: string, metadata: array<string, mixed>}> */
    protected array $connects = [];

    /** @var array<int, array{resourceId: string, metadata: array<string, mixed>}> */
    protected array $disconnects = [];

    /** @var array<int, array{resourceId: string, metadata: array<string, mixed>}> */
    protected array $activities = [];

    /** @var array<int, string> */
    protected array $invalidations = [];

    public function __construct(
        protected Resolver $primary,
        protected Resolver $secondary
    ) {
    }

    public function resolve(string $resourceId): Result
    {
        $this->failedOver = false;

        try {
            return $this->primary->resolve($resourceId);
        } catch (ResolverException $e) {
            $this->failedOver = true;

            // Try secondary; let its exception propagate if it also fails
            return $this->secondary->resolve($resourceId);
        }
    }

    public function didFailover(): bool
    {
        return $this->failedOver;
    }

    public function onConnect(string $resourceId, array $metadata = []): void
    {
        $this->connects[] = ['resourceId' => $resourceId, 'metadata' => $metadata];
    }

    public function onDisconnect(string $resourceId, array $metadata = []): void
    {
        $this->disconnects[] = ['resourceId' => $resourceId, 'metadata' => $metadata];
    }

    public function track(string $resourceId, array $metadata = []): void
    {
        $this->activities[] = ['resourceId' => $resourceId, 'metadata' => $metadata];
    }

    public function purge(string $resourceId): void
    {
        $this->invalidations[] = $resourceId;
        $this->primary->purge($resourceId);
        $this->secondary->purge($resourceId);
    }

    /**
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return [
            'resolver' => 'edge-failover',
            'failedOver' => $this->failedOver,
            'primary' => $this->primary->getStats(),
            'secondary' => $this->secondary->getStats(),
        ];
    }
}
