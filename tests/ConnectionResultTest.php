<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\ConnectionResult;
use Utopia\Proxy\Protocol;

class ConnectionResultTest extends TestCase
{
    public function testConnectionResultStoresValues(): void
    {
        $result = new ConnectionResult(
            endpoint: '127.0.0.1:8080',
            protocol: Protocol::HTTP,
            metadata: ['cached' => false]
        );

        $this->assertSame('127.0.0.1:8080', $result->endpoint);
        $this->assertSame(Protocol::HTTP, $result->protocol);
        $this->assertSame(['cached' => false], $result->metadata);
    }
}
