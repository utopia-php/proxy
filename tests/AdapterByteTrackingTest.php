<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Adapter;
use Utopia\Proxy\Protocol;

class AdapterByteTrackingTest extends TestCase
{
    protected MockResolver $resolver;

    protected function setUp(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to run adapter tests.');
        }

        $this->resolver = new MockResolver();
    }

    public function testRecordBytesInitializesCounters(): void
    {
        $adapter = new Adapter($this->resolver, protocol: Protocol::TCP);

        $adapter->recordBytes('resource-1', inbound: 100, outbound: 200);

        // Verify via notifyClose which flushes byte counters
        $adapter->notifyClose('resource-1');
        $disconnects = $this->resolver->getDisconnects();

        $this->assertSame(100, $disconnects[0]['metadata']['inboundBytes']);
        $this->assertSame(200, $disconnects[0]['metadata']['outboundBytes']);
    }

    public function testRecordBytesAccumulatesValues(): void
    {
        $adapter = new Adapter($this->resolver, protocol: Protocol::TCP);

        $adapter->recordBytes('resource-1', inbound: 100, outbound: 200);
        $adapter->recordBytes('resource-1', inbound: 50, outbound: 75);
        $adapter->recordBytes('resource-1', inbound: 25, outbound: 25);

        $adapter->notifyClose('resource-1');
        $disconnects = $this->resolver->getDisconnects();

        $this->assertSame(175, $disconnects[0]['metadata']['inboundBytes']);
        $this->assertSame(300, $disconnects[0]['metadata']['outboundBytes']);
    }

    public function testRecordBytesDefaultsToZero(): void
    {
        $adapter = new Adapter($this->resolver, protocol: Protocol::TCP);

        $adapter->recordBytes('resource-1');

        $adapter->notifyClose('resource-1');
        $disconnects = $this->resolver->getDisconnects();

        $this->assertSame(0, $disconnects[0]['metadata']['inboundBytes']);
        $this->assertSame(0, $disconnects[0]['metadata']['outboundBytes']);
    }

    public function testRecordBytesInboundOnly(): void
    {
        $adapter = new Adapter($this->resolver, protocol: Protocol::TCP);

        $adapter->recordBytes('resource-1', inbound: 500);

        $adapter->notifyClose('resource-1');
        $disconnects = $this->resolver->getDisconnects();

        $this->assertSame(500, $disconnects[0]['metadata']['inboundBytes']);
        $this->assertSame(0, $disconnects[0]['metadata']['outboundBytes']);
    }

    public function testRecordBytesOutboundOnly(): void
    {
        $adapter = new Adapter($this->resolver, protocol: Protocol::TCP);

        $adapter->recordBytes('resource-1', outbound: 300);

        $adapter->notifyClose('resource-1');
        $disconnects = $this->resolver->getDisconnects();

        $this->assertSame(0, $disconnects[0]['metadata']['inboundBytes']);
        $this->assertSame(300, $disconnects[0]['metadata']['outboundBytes']);
    }

    public function testRecordBytesTracksMultipleResources(): void
    {
        $adapter = new Adapter($this->resolver, protocol: Protocol::TCP);

        $adapter->recordBytes('resource-1', inbound: 100, outbound: 200);
        $adapter->recordBytes('resource-2', inbound: 300, outbound: 400);

        $adapter->notifyClose('resource-1');
        $adapter->notifyClose('resource-2');
        $disconnects = $this->resolver->getDisconnects();

        $this->assertSame(100, $disconnects[0]['metadata']['inboundBytes']);
        $this->assertSame(200, $disconnects[0]['metadata']['outboundBytes']);
        $this->assertSame(300, $disconnects[1]['metadata']['inboundBytes']);
        $this->assertSame(400, $disconnects[1]['metadata']['outboundBytes']);
    }

    public function testNotifyCloseFlushesAndClearsCounters(): void
    {
        $adapter = new Adapter($this->resolver, protocol: Protocol::TCP);

        $adapter->recordBytes('resource-1', inbound: 100, outbound: 200);
        $adapter->notifyClose('resource-1');

        // Second close should not include byte data
        $adapter->notifyClose('resource-1');
        $disconnects = $this->resolver->getDisconnects();

        $this->assertArrayHasKey('inboundBytes', $disconnects[0]['metadata']);
        $this->assertArrayNotHasKey('inboundBytes', $disconnects[1]['metadata']);
    }

    public function testNotifyCloseWithoutByteRecordingOmitsByteMetadata(): void
    {
        $adapter = new Adapter($this->resolver, protocol: Protocol::TCP);

        $adapter->notifyClose('resource-1', ['reason' => 'timeout']);
        $disconnects = $this->resolver->getDisconnects();

        $this->assertArrayNotHasKey('inboundBytes', $disconnects[0]['metadata']);
        $this->assertSame('timeout', $disconnects[0]['metadata']['reason']);
    }

    public function testNotifyCloseMergesByteDataWithExistingMetadata(): void
    {
        $adapter = new Adapter($this->resolver, protocol: Protocol::TCP);

        $adapter->recordBytes('resource-1', inbound: 100, outbound: 200);
        $adapter->notifyClose('resource-1', ['reason' => 'client_disconnect']);
        $disconnects = $this->resolver->getDisconnects();

        $this->assertSame(100, $disconnects[0]['metadata']['inboundBytes']);
        $this->assertSame(200, $disconnects[0]['metadata']['outboundBytes']);
        $this->assertSame('client_disconnect', $disconnects[0]['metadata']['reason']);
    }

    public function testTrackFlushesAccumulatedBytes(): void
    {
        $adapter = new Adapter($this->resolver, protocol: Protocol::TCP);
        $adapter->setInterval(0);

        $adapter->recordBytes('resource-1', inbound: 100, outbound: 200);
        $adapter->track('resource-1');

        $activities = $this->resolver->getActivities();
        $this->assertCount(1, $activities);
        $this->assertSame(100, $activities[0]['metadata']['inboundBytes']);
        $this->assertSame(200, $activities[0]['metadata']['outboundBytes']);
    }

    public function testTrackResetsCountersAfterFlush(): void
    {
        $adapter = new Adapter($this->resolver, protocol: Protocol::TCP);
        $adapter->setInterval(0);

        $adapter->recordBytes('resource-1', inbound: 100, outbound: 200);
        $adapter->track('resource-1');

        // Record more bytes and track again
        $adapter->recordBytes('resource-1', inbound: 50, outbound: 25);

        // Need to wait for throttle to pass (interval is 0 but time() is same second)
        // Force a new second
        sleep(1);
        $adapter->track('resource-1');

        $activities = $this->resolver->getActivities();
        $this->assertCount(2, $activities);
        $this->assertSame(50, $activities[1]['metadata']['inboundBytes']);
        $this->assertSame(25, $activities[1]['metadata']['outboundBytes']);
    }

    public function testTrackWithoutBytesOmitsByteMetadata(): void
    {
        $adapter = new Adapter($this->resolver, protocol: Protocol::TCP);
        $adapter->setInterval(0);

        $adapter->track('resource-1', ['type' => 'query']);

        $activities = $this->resolver->getActivities();
        $this->assertCount(1, $activities);
        $this->assertArrayNotHasKey('inboundBytes', $activities[0]['metadata']);
        $this->assertSame('query', $activities[0]['metadata']['type']);
    }

    public function testNotifyCloseClearsActivityTimestamp(): void
    {
        $adapter = new Adapter($this->resolver, protocol: Protocol::TCP);
        $adapter->setInterval(9999);

        // Track once to set the timestamp
        $adapter->track('resource-1');
        $this->assertCount(1, $this->resolver->getActivities());

        // Normally this would be throttled
        $adapter->track('resource-1');
        $this->assertCount(1, $this->resolver->getActivities());

        // Close clears the timestamp
        $adapter->notifyClose('resource-1');

        // Now tracking should work again immediately
        $adapter->track('resource-1');
        $this->assertCount(2, $this->resolver->getActivities());
    }

    public function testSetActivityIntervalReturnsSelf(): void
    {
        $adapter = new Adapter($this->resolver, protocol: Protocol::TCP);

        $result = $adapter->setInterval(60);
        $this->assertSame($adapter, $result);
    }

    public function testSetSkipValidationReturnsSelf(): void
    {
        $adapter = new Adapter($this->resolver, protocol: Protocol::TCP);

        $result = $adapter->setSkipValidation(true);
        $this->assertSame($adapter, $result);
    }
}
