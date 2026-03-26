<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Server\TCP\Config;

class AdapterFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to run Config tests.');
        }
    }

    public function testDefaultAdapterFactoryIsNull(): void
    {
        $config = new Config(ports: [5432]);
        $this->assertNull($config->adapterFactory);
    }

    public function testAdapterFactoryAcceptsClosure(): void
    {
        $factory = function (int $port) {
            return 'adapter-for-port-' . $port;
        };

        $config = new Config(ports: [5432], adapterFactory: $factory);
        $this->assertNotNull($config->adapterFactory);
        $this->assertInstanceOf(\Closure::class, $config->adapterFactory);
    }

    public function testAdapterFactoryClosureIsInvokable(): void
    {
        $factory = function (int $port): string {
            return 'adapter-for-port-' . $port;
        };

        $config = new Config(ports: [5432], adapterFactory: $factory);
        $callable = $config->adapterFactory;
        \assert($callable !== null);
        $result = $callable(5432);
        $this->assertSame('adapter-for-port-5432', $result);
    }

    public function testAdapterFactoryClosureReceivesPort(): void
    {
        $receivedPorts = [];
        $factory = function (int $port) use (&$receivedPorts): string {
            $receivedPorts[] = $port;
            return 'adapter';
        };

        $config = new Config(ports: [5432], adapterFactory: $factory);
        $callable = $config->adapterFactory;
        \assert($callable !== null);
        $callable(5432);
        $callable(3306);
        $callable(27017);

        $this->assertSame([5432, 3306, 27017], $receivedPorts);
    }

    public function testOtherConfigValuesPreservedWithFactory(): void
    {
        $factory = function (int $port) {
            return 'adapter';
        };

        $config = new Config(
            host: '127.0.0.1',
            ports: [5432],
            workers: 8,
            adapterFactory: $factory,
        );

        $this->assertSame('127.0.0.1', $config->host);
        $this->assertSame([5432], $config->ports);
        $this->assertSame(8, $config->workers);
        $this->assertNotNull($config->adapterFactory);
    }

    public function testNullAdapterFactoryPreservesDefaults(): void
    {
        $config = new Config(ports: [5432], adapterFactory: null);
        $this->assertNull($config->adapterFactory);
        $this->assertSame('0.0.0.0', $config->host);
        $this->assertSame([5432], $config->ports);
    }
}
