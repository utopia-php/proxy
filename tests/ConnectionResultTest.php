<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\ConnectionResult;

class ConnectionResultTest extends TestCase
{
    public function testConnectionResultStoresValues(): void
    {
        $result = new ConnectionResult(
            endpoint: '127.0.0.1:8080',
            protocol: 'http',
            metadata: ['cached' => false]
        );

        $this->assertSame('127.0.0.1:8080', $result->endpoint);
        $this->assertSame('http', $result->protocol);
        $this->assertSame(['cached' => false], $result->metadata);
    }
}
