<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Server\TCP\Config;
use Utopia\Proxy\Server\TCP\TLS;
use Utopia\Proxy\Server\TCP\TLSContext;

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
        $config = new Config(ports: [5432]);
        $this->assertSame('0.0.0.0', $config->host);
    }

    public function testPortsAreRequired(): void
    {
        $config = new Config(ports: [5432, 3306]);
        $this->assertSame([5432, 3306], $config->ports);
    }

    public function testDefaultWorkersMatchesCpuCount(): void
    {
        $config = new Config(ports: [5432]);
        $this->assertSame(\swoole_cpu_num(), $config->workers);
    }

    public function testDefaultMaxConnections(): void
    {
        $config = new Config(ports: [5432]);
        $this->assertSame(200_000, $config->maxConnections);
    }

    public function testDefaultMaxCoroutineIsDoubleMaxConnections(): void
    {
        $config = new Config(ports: [5432]);
        $this->assertSame(400_000, $config->maxCoroutine);
    }

    public function testDefaultBufferSizesAreRightSized(): void
    {
        $config = new Config(ports: [5432]);
        $this->assertSame(1 * 1024 * 1024, $config->socketBufferSize);
        $this->assertSame(1 * 1024 * 1024, $config->bufferOutputSize);
    }

    public function testDefaultReactorNumMatchesCpuCount(): void
    {
        $config = new Config(ports: [5432]);
        $this->assertSame(\swoole_cpu_num(), $config->reactorNum);
    }

    public function testDefaultServerModeIsBase(): void
    {
        $config = new Config(ports: [5432]);
        $this->assertSame(SWOOLE_BASE, $config->serverMode);
    }

    public function testDefaultEnableReusePort(): void
    {
        $config = new Config(ports: [5432]);
        $this->assertTrue($config->enableReusePort);
    }

    public function testDefaultBacklog(): void
    {
        $config = new Config(ports: [5432]);
        $this->assertSame(65535, $config->backlog);
    }

    public function testDefaultPackageMaxLength(): void
    {
        $config = new Config(ports: [5432]);
        $this->assertSame(32 * 1024 * 1024, $config->packageMaxLength);
    }

    public function testDefaultTcpKeepaliveSettings(): void
    {
        $config = new Config(ports: [5432]);
        $this->assertSame(30, $config->tcpKeepidle);
        $this->assertSame(10, $config->tcpKeepinterval);
        $this->assertSame(3, $config->tcpKeepcount);
    }

    public function testDefaultTcpUserTimeoutMs(): void
    {
        $config = new Config(ports: [5432]);
        $this->assertSame(10_000, $config->tcpUserTimeoutMs);
    }

    public function testDefaultTcpQuickAck(): void
    {
        $config = new Config(ports: [5432]);
        $this->assertTrue($config->tcpQuickAck);
    }

    public function testDefaultCoroutineStackSize(): void
    {
        $config = new Config(ports: [5432]);
        $this->assertSame(262_144, $config->coroutineStackSize);
    }

    public function testDefaultGcIntervalMs(): void
    {
        $config = new Config(ports: [5432]);
        $this->assertSame(5_000, $config->gcIntervalMs);
    }

    public function testDefaultDnsCacheTtl(): void
    {
        $config = new Config(ports: [5432]);
        $this->assertSame(60, $config->dnsCacheTtl);
    }

    public function testDefaultTcpNotsentLowat(): void
    {
        $config = new Config(ports: [5432]);
        $this->assertSame(16_384, $config->tcpNotsentLowat);
    }

    public function testDefaultEnableCoroutine(): void
    {
        $config = new Config(ports: [5432]);
        $this->assertTrue($config->enableCoroutine);
    }

    public function testDefaultMaxWaitTime(): void
    {
        $config = new Config(ports: [5432]);
        $this->assertSame(60, $config->maxWaitTime);
    }

    public function testDefaultLogLevel(): void
    {
        $config = new Config(ports: [5432]);
        $this->assertSame(SWOOLE_LOG_ERROR, $config->logLevel);
    }

    public function testDefaultLogConnections(): void
    {
        $config = new Config(ports: [5432]);
        $this->assertFalse($config->logConnections);
    }

    public function testDefaultReceiveBufferSize(): void
    {
        $config = new Config(ports: [5432]);
        $this->assertSame(65_536, $config->receiveBufferSize);
    }

    public function testDefaultBackendConnectTimeout(): void
    {
        $config = new Config(ports: [5432]);
        $this->assertSame(5.0, $config->connectTimeout);
    }

    public function testDefaultSkipValidation(): void
    {
        $config = new Config(ports: [5432]);
        $this->assertFalse($config->skipValidation);
    }

    public function testDefaultTlsIsNull(): void
    {
        $config = new Config(ports: [5432]);
        $this->assertNull($config->tls);
    }

    public function testCustomReactorNum(): void
    {
        $config = new Config(ports: [5432], reactorNum: 4);
        $this->assertSame(4, $config->reactorNum);
    }

    public function testCustomPorts(): void
    {
        $config = new Config(ports: [5432]);
        $this->assertSame([5432], $config->ports);
    }

    public function testCustomHost(): void
    {
        $config = new Config(ports: [5432], host: '127.0.0.1');
        $this->assertSame('127.0.0.1', $config->host);
    }

    public function testCustomWorkers(): void
    {
        $config = new Config(ports: [5432], workers: 4);
        $this->assertSame(4, $config->workers);
    }

    public function testCustomMaxCoroutineOverridesDefault(): void
    {
        $config = new Config(ports: [5432], maxCoroutine: 1_000);
        $this->assertSame(1_000, $config->maxCoroutine);
    }

    public function testCustomServerMode(): void
    {
        $config = new Config(ports: [5432], serverMode: SWOOLE_PROCESS);
        $this->assertSame(SWOOLE_PROCESS, $config->serverMode);
    }

    public function testCustomBackendConnectTimeout(): void
    {
        $config = new Config(ports: [5432], connectTimeout: 10.5);
        $this->assertSame(10.5, $config->connectTimeout);
    }

    public function testCustomSkipValidation(): void
    {
        $config = new Config(ports: [5432], skipValidation: true);
        $this->assertTrue($config->skipValidation);
    }

    public function testCustomLogConnections(): void
    {
        $config = new Config(ports: [5432], logConnections: true);
        $this->assertTrue($config->logConnections);
    }

    public function testIsTlsEnabledFalseByDefault(): void
    {
        $config = new Config(ports: [5432]);
        $this->assertFalse($config->isTlsEnabled());
    }

    public function testIsTlsEnabledTrueWhenConfigured(): void
    {
        $tls = new TLS(certificate: '/certs/server.crt', key: '/certs/server.key');
        $config = new Config(ports: [5432], tls: $tls);
        $this->assertTrue($config->isTlsEnabled());
    }

    public function testGetTLSContextNullByDefault(): void
    {
        $config = new Config(ports: [5432]);
        $this->assertNull($config->getTLSContext());
    }

    public function testGetTLSContextReturnsInstanceWhenConfigured(): void
    {
        $tls = new TLS(certificate: '/certs/server.crt', key: '/certs/server.key');
        $config = new Config(ports: [5432], tls: $tls);

        $context = $config->getTLSContext();
        $this->assertInstanceOf(TLSContext::class, $context);
        $this->assertSame($tls, $context->getTls());
    }

    public function testGetTLSContextReturnsNewInstanceEachCall(): void
    {
        $tls = new TLS(certificate: '/certs/server.crt', key: '/certs/server.key');
        $config = new Config(ports: [5432], tls: $tls);

        $context1 = $config->getTLSContext();
        $context2 = $config->getTLSContext();
        $this->assertNotSame($context1, $context2);
    }
}
