<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Server\TCP\Config;
use Utopia\Proxy\Server\TCP\TLS;
use Utopia\Proxy\Server\TCP\TlsContext;

class ConfigTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to run Config tests.');
        }
    }

    public function testDefaultHost(): void
    {
        $config = new Config();
        $this->assertSame('0.0.0.0', $config->host);
    }

    public function testDefaultPorts(): void
    {
        $config = new Config();
        $this->assertSame([5432, 3306, 27017], $config->ports);
    }

    public function testDefaultWorkers(): void
    {
        $config = new Config();
        $this->assertSame(16, $config->workers);
    }

    public function testDefaultMaxConnections(): void
    {
        $config = new Config();
        $this->assertSame(200_000, $config->maxConnections);
    }

    public function testDefaultMaxCoroutine(): void
    {
        $config = new Config();
        $this->assertSame(200_000, $config->maxCoroutine);
    }

    public function testDefaultBufferSizes(): void
    {
        $config = new Config();
        $this->assertSame(16 * 1024 * 1024, $config->socketBufferSize);
        $this->assertSame(16 * 1024 * 1024, $config->bufferOutputSize);
    }

    public function testDefaultReactorNumIsCpuBased(): void
    {
        $config = new Config();
        $this->assertSame(swoole_cpu_num() * 2, $config->reactorNum);
    }

    public function testDefaultDispatchMode(): void
    {
        $config = new Config();
        $this->assertSame(2, $config->dispatchMode);
    }

    public function testDefaultEnableReusePort(): void
    {
        $config = new Config();
        $this->assertTrue($config->enableReusePort);
    }

    public function testDefaultBacklog(): void
    {
        $config = new Config();
        $this->assertSame(65535, $config->backlog);
    }

    public function testDefaultPackageMaxLength(): void
    {
        $config = new Config();
        $this->assertSame(32 * 1024 * 1024, $config->packageMaxLength);
    }

    public function testDefaultTcpKeepaliveSettings(): void
    {
        $config = new Config();
        $this->assertSame(30, $config->tcpKeepidle);
        $this->assertSame(10, $config->tcpKeepinterval);
        $this->assertSame(3, $config->tcpKeepcount);
    }

    public function testDefaultEnableCoroutine(): void
    {
        $config = new Config();
        $this->assertTrue($config->enableCoroutine);
    }

    public function testDefaultMaxWaitTime(): void
    {
        $config = new Config();
        $this->assertSame(60, $config->maxWaitTime);
    }

    public function testDefaultLogLevel(): void
    {
        $config = new Config();
        $this->assertSame(SWOOLE_LOG_ERROR, $config->logLevel);
    }

    public function testDefaultLogConnections(): void
    {
        $config = new Config();
        $this->assertFalse($config->logConnections);
    }

    public function testDefaultRecvBufferSize(): void
    {
        $config = new Config();
        $this->assertSame(131072, $config->recvBufferSize);
    }

    public function testDefaultBackendConnectTimeout(): void
    {
        $config = new Config();
        $this->assertSame(5.0, $config->backendConnectTimeout);
    }

    public function testDefaultSkipValidation(): void
    {
        $config = new Config();
        $this->assertFalse($config->skipValidation);
    }

    public function testDefaultReadWriteSplit(): void
    {
        $config = new Config();
        $this->assertFalse($config->readWriteSplit);
    }

    public function testDefaultTlsIsNull(): void
    {
        $config = new Config();
        $this->assertNull($config->tls);
    }

    public function testCustomReactorNum(): void
    {
        $config = new Config(reactorNum: 4);
        $this->assertSame(4, $config->reactorNum);
    }

    public function testCustomPorts(): void
    {
        $config = new Config(ports: [5432]);
        $this->assertSame([5432], $config->ports);
    }

    public function testCustomHost(): void
    {
        $config = new Config(host: '127.0.0.1');
        $this->assertSame('127.0.0.1', $config->host);
    }

    public function testCustomWorkers(): void
    {
        $config = new Config(workers: 4);
        $this->assertSame(4, $config->workers);
    }

    public function testCustomBackendConnectTimeout(): void
    {
        $config = new Config(backendConnectTimeout: 10.5);
        $this->assertSame(10.5, $config->backendConnectTimeout);
    }

    public function testCustomSkipValidation(): void
    {
        $config = new Config(skipValidation: true);
        $this->assertTrue($config->skipValidation);
    }

    public function testCustomReadWriteSplit(): void
    {
        $config = new Config(readWriteSplit: true);
        $this->assertTrue($config->readWriteSplit);
    }

    public function testCustomLogConnections(): void
    {
        $config = new Config(logConnections: true);
        $this->assertTrue($config->logConnections);
    }

    public function testIsTlsEnabledFalseByDefault(): void
    {
        $config = new Config();
        $this->assertFalse($config->isTlsEnabled());
    }

    public function testIsTlsEnabledTrueWhenConfigured(): void
    {
        $tls = new TLS(certPath: '/certs/server.crt', keyPath: '/certs/server.key');
        $config = new Config(tls: $tls);
        $this->assertTrue($config->isTlsEnabled());
    }

    public function testGetTlsContextNullByDefault(): void
    {
        $config = new Config();
        $this->assertNull($config->getTlsContext());
    }

    public function testGetTlsContextReturnsInstanceWhenConfigured(): void
    {
        $tls = new TLS(certPath: '/certs/server.crt', keyPath: '/certs/server.key');
        $config = new Config(tls: $tls);

        $ctx = $config->getTlsContext();
        $this->assertInstanceOf(TlsContext::class, $ctx);
        $this->assertSame($tls, $ctx->getTls());
    }

    public function testGetTlsContextReturnsNewInstanceEachCall(): void
    {
        $tls = new TLS(certPath: '/certs/server.crt', keyPath: '/certs/server.key');
        $config = new Config(tls: $tls);

        $ctx1 = $config->getTlsContext();
        $ctx2 = $config->getTlsContext();
        $this->assertNotSame($ctx1, $ctx2);
    }
}
