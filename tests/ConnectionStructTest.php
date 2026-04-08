<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Server\TCP\Connection;

class ConnectionStructTest extends TestCase
{
    public function testDefaultsAreZeroed(): void
    {
        $connection = new Connection();
        $this->assertNull($connection->backend);
        $this->assertSame(0, $connection->port);
        $this->assertFalse($connection->pendingTls);
        $this->assertSame(0, $connection->inbound);
        $this->assertSame(0, $connection->outbound);
    }

    public function testResetClearsAllFields(): void
    {
        $connection = new Connection();
        $connection->port = 5432;
        $connection->pendingTls = true;
        $connection->inbound = 100;
        $connection->outbound = 200;

        $connection->reset();

        $this->assertNull($connection->backend);
        $this->assertSame(0, $connection->port);
        $this->assertFalse($connection->pendingTls);
        $this->assertSame(0, $connection->inbound);
        $this->assertSame(0, $connection->outbound);
    }
}
