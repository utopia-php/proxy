<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Protocol;

class ProtocolTest extends TestCase
{
    public function testAllProtocolValues(): void
    {
        $this->assertSame('http', Protocol::HTTP->value);
        $this->assertSame('smtp', Protocol::SMTP->value);
        $this->assertSame('tcp', Protocol::TCP->value);
        $this->assertSame('postgresql', Protocol::PostgreSQL->value);
        $this->assertSame('mysql', Protocol::MySQL->value);
        $this->assertSame('mongodb', Protocol::MongoDB->value);
    }

    public function testProtocolCount(): void
    {
        $cases = Protocol::cases();
        $this->assertCount(6, $cases);
    }

    public function testProtocolFromValue(): void
    {
        $this->assertSame(Protocol::HTTP, Protocol::from('http'));
        $this->assertSame(Protocol::SMTP, Protocol::from('smtp'));
        $this->assertSame(Protocol::TCP, Protocol::from('tcp'));
        $this->assertSame(Protocol::PostgreSQL, Protocol::from('postgresql'));
        $this->assertSame(Protocol::MySQL, Protocol::from('mysql'));
        $this->assertSame(Protocol::MongoDB, Protocol::from('mongodb'));
    }

    public function testProtocolTryFromInvalidReturnsNull(): void
    {
        $invalid = Protocol::tryFrom('invalid');
        $empty = Protocol::tryFrom('');
        $uppercase = Protocol::tryFrom('HTTP'); // case-sensitive

        $this->assertSame(null, $invalid);
        $this->assertSame(null, $empty);
        $this->assertSame(null, $uppercase);
    }

    public function testProtocolFromInvalidThrows(): void
    {
        $this->expectException(\ValueError::class);
        Protocol::from('invalid');
    }

    public function testProtocolIsBackedEnum(): void
    {
        $reflection = new \ReflectionEnum(Protocol::class);
        $this->assertTrue($reflection->isBacked());
        $this->assertSame('string', $reflection->getBackingType()->getName());
    }
}
