<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\ConnectionResult;
use Utopia\Proxy\Protocol;

class ConnectionResultExtendedTest extends TestCase
{
    public function testAllProtocolTypes(): void
    {
        $protocols = [
            Protocol::HTTP,
            Protocol::SMTP,
            Protocol::TCP,
            Protocol::PostgreSQL,
            Protocol::MySQL,
            Protocol::MongoDB,
        ];

        foreach ($protocols as $protocol) {
            $result = new ConnectionResult(
                endpoint: '127.0.0.1:8080',
                protocol: $protocol,
            );

            $this->assertSame($protocol, $result->protocol);
        }
    }

    public function testDefaultEmptyMetadata(): void
    {
        $result = new ConnectionResult(
            endpoint: '127.0.0.1:8080',
            protocol: Protocol::HTTP,
        );

        $this->assertSame([], $result->metadata);
    }

    public function testMetadataWithMultipleTypes(): void
    {
        $result = new ConnectionResult(
            endpoint: '127.0.0.1:8080',
            protocol: Protocol::HTTP,
            metadata: [
                'cached' => true,
                'latency' => 1.5,
                'count' => 42,
                'tags' => ['fast', 'reliable'],
                'config' => ['timeout' => 30],
            ]
        );

        $this->assertTrue($result->metadata['cached']);
        $this->assertSame(1.5, $result->metadata['latency']);
        $this->assertSame(42, $result->metadata['count']);
        $this->assertSame(['fast', 'reliable'], $result->metadata['tags']);
        $this->assertSame(['timeout' => 30], $result->metadata['config']);
    }

    public function testEndpointWithHostOnly(): void
    {
        $result = new ConnectionResult(
            endpoint: 'db.example.com',
            protocol: Protocol::PostgreSQL,
        );

        $this->assertSame('db.example.com', $result->endpoint);
    }

    public function testEndpointWithHostAndPort(): void
    {
        $result = new ConnectionResult(
            endpoint: 'db.example.com:5432',
            protocol: Protocol::PostgreSQL,
        );

        $this->assertSame('db.example.com:5432', $result->endpoint);
    }

    public function testEndpointWithIpAddress(): void
    {
        $result = new ConnectionResult(
            endpoint: '192.168.1.100:3306',
            protocol: Protocol::MySQL,
        );

        $this->assertSame('192.168.1.100:3306', $result->endpoint);
    }
}
