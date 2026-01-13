<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Platform\Action;
use Utopia\Proxy\Adapter\HTTP\Swoole as HTTPAdapter;
use Utopia\Proxy\Service\HTTP as HTTPService;

class AdapterStatsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to run adapter tests.');
        }
    }

    public function testCacheHitUpdatesStats(): void
    {
        $adapter = new HTTPAdapter();
        $service = new HTTPService();

        $service->addAction('resolve', (new class extends Action {})
            ->callback(function (string $hostname): string {
                return '127.0.0.1:8080';
            }));

        $adapter->setService($service);

        $start = time();
        while (time() === $start) {
            usleep(1000);
        }

        $first = $adapter->route('api.example.com');
        $second = $adapter->route('api.example.com');

        $this->assertFalse($first->metadata['cached']);
        $this->assertTrue($second->metadata['cached']);

        $stats = $adapter->getStats();
        $this->assertSame(2, $stats['connections']);
        $this->assertSame(1, $stats['cache_hits']);
        $this->assertSame(1, $stats['cache_misses']);
        $this->assertSame(50.0, $stats['cache_hit_rate']);
        $this->assertSame(0, $stats['routing_errors']);
        $this->assertSame(1, $stats['routing_table_size']);
        $this->assertGreaterThan(0, $stats['routing_table_memory']);
    }

    public function testRoutingErrorIncrementsStats(): void
    {
        $adapter = new HTTPAdapter();
        $service = new HTTPService();

        $service->addAction('resolve', (new class extends Action {})
            ->callback(function (string $hostname): string {
                throw new \Exception('No backend');
            }));

        $adapter->setService($service);

        try {
            $adapter->route('api.example.com');
            $this->fail('Expected routing error was not thrown.');
        } catch (\Exception $e) {
            $this->assertSame('No backend', $e->getMessage());
        }

        $stats = $adapter->getStats();
        $this->assertSame(1, $stats['routing_errors']);
        $this->assertSame(1, $stats['cache_misses']);
        $this->assertSame(0, $stats['cache_hits']);
        $this->assertSame(0.0, $stats['cache_hit_rate']);
    }
}
