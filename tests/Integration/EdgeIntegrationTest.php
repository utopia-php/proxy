<?php

namespace Utopia\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Adapter\TCP as TCPAdapter;
use Utopia\Proxy\ConnectionResult;
use Utopia\Proxy\Protocol;
use Utopia\Proxy\Resolver;
use Utopia\Proxy\Resolver\Exception as ResolverException;
use Utopia\Proxy\Resolver\Result;

/**
 * Integration test for the proxy's ability to resolve resource
 * connections via an Edge-like adapter pattern.
 *
 * These tests simulate the full resolution flow that occurs in production
 * when the proxy calls the Edge service to resolve a resource ID
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

        $adapter = new TCPAdapter(port: 5432, resolver: $resolver);
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

        $adapter = new TCPAdapter(port: 5432, resolver: $resolver);
        $adapter->setSkipValidation(true);

        $this->expectException(ResolverException::class);
        $this->expectExceptionCode(ResolverException::NOT_FOUND);

        $adapter->route('nonexistent');
    }

    /**
     * @group integration
     */
    public function testResolverReceivesRawDataForRouting(): void
    {
        $resolver = new EdgeMockResolver();
        $resolver->registerDatabase('raw-packet-data', [
            'host' => '10.0.1.50',
            'port' => 5432,
            'username' => 'user1',
            'password' => 'pass1',
        ]);

        $adapter = new TCPAdapter(port: 5432, resolver: $resolver);
        $adapter->setSkipValidation(true);

        // The resolver receives the raw data directly and routes based on it
        $result = $adapter->route('raw-packet-data');
        $this->assertSame('10.0.1.50:5432', $result->endpoint);
    }

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

        $adapter = new TCPAdapter(port: 5432, resolver: $failoverResolver);
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

        $adapter = new TCPAdapter(port: 5432, resolver: $failoverResolver);
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

        $adapter = new TCPAdapter(port: 5432, resolver: $failoverResolver);
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

        $adapter = new TCPAdapter(port: 5432, resolver: $failoverResolver);
        $adapter->setSkipValidation(true);

        $result = $adapter->route('unavaildb');

        $this->assertSame('10.0.3.10:5432', $result->endpoint);
        $this->assertTrue($failoverResolver->didFailover());
    }

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

        $adapter = new TCPAdapter(port: 5432, resolver: $resolver);
        $adapter->setSkipValidation(true);
        $adapter->setCacheTTL(60);

        // Ensure we are at the start of a fresh second so both calls
        // land within the same cache window
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

        $adapter = new TCPAdapter(port: 5432, resolver: $resolver);
        $adapter->setSkipValidation(true);
        $adapter->setCacheTTL(1);

        // Align to second boundary
        $start = time();
        while (time() === $start) {
            usleep(1000);
        }

        $first = $adapter->route('invaldb');
        $this->assertFalse($first->metadata['cached']);

        $resolver->purge('invaldb');

        // Wait for the routing table cache to expire
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

        $adapter = new TCPAdapter(port: 5432, resolver: $resolver);
        $adapter->setSkipValidation(true);

        $result1 = $adapter->route('db1');
        $result2 = $adapter->route('db2');

        $this->assertSame('10.0.5.1:5432', $result1->endpoint);
        $this->assertSame('10.0.5.2:5432', $result2->endpoint);
        $this->assertNotSame($result1->endpoint, $result2->endpoint);
    }

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

        $adapter = new TCPAdapter(port: 5432, resolver: $resolver);
        $adapter->setSkipValidation(true);
        $adapter->setCacheTTL(60);

        $results = [];
        for ($i = 1; $i <= $databaseCount; $i++) {
            $results[$i] = $adapter->route("concurrent{$i}");
        }

        for ($i = 1; $i <= $databaseCount; $i++) {
            $this->assertSame("10.0.10.{$i}:5432", $results[$i]->endpoint);
            $this->assertSame(Protocol::PostgreSQL, $results[$i]->protocol);
        }

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

        $adapter = new TCPAdapter(port: 5432, resolver: $resolver);
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

    }

}

/**
 * Simulates an Edge service resolver that resolves resource IDs to backend
 * endpoints. In production, the resolve() call would be an HTTP request to
 * the Edge service. Here we simulate that with an in-memory registry.
 */
class EdgeMockResolver implements Resolver
{
    /** @var array<string, array{host: string, port: int, username: string, password: string}> */
    protected array $databases = [];

    protected int $resolveCount = 0;

    protected bool $unavailable = false;

    /**
     * Register a database endpoint (simulates Edge service configuration)
     *
     * @param  array{host: string, port: int, username: string, password: string}  $config
     */
    public function registerDatabase(string $resourceId, array $config): self
    {
        $this->databases[$resourceId] = $config;

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
                'Edge service unavailable',
                ResolverException::UNAVAILABLE,
                ['resourceId' => $resourceId]
            );
        }

        if (! isset($this->databases[$resourceId])) {
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

    public function getResolveCount(): int
    {
        return $this->resolveCount;
    }

    public function purge(string $resourceId): void
    {
        unset($this->databases[$resourceId]);
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

            return $this->secondary->resolve($resourceId);
        }
    }

    public function didFailover(): bool
    {
        return $this->failedOver;
    }
}
