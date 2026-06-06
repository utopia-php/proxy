<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Utopia\Proxy\Server\TCP\Swoole\Coroutine as CoroutineTCPServer;
use Utopia\Proxy\Server\TCP\TLS;

class TLSTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to run TLS tests.');
        }
        if (!\defined('SWOOLE_SSL_TLSv1_2')) {
            $this->markTestSkipped('Swoole was built without OpenSSL support.');
        }
    }

    public function testConstructorSetsRequiredPaths(): void
    {
        $tls = new TLS(certificate: '/certs/server.crt', key: '/certs/server.key');

        $this->assertSame('/certs/server.crt', $tls->certificate);
        $this->assertSame('/certs/server.key', $tls->key);
    }

    public function testConstructorDefaultValues(): void
    {
        $tls = new TLS(certificate: '/certs/server.crt', key: '/certs/server.key');

        $this->assertSame('', $tls->ca);
        $this->assertFalse($tls->requireClientCert);
        $this->assertSame(TLS::DEFAULT_CIPHERS, $tls->ciphers);
        $this->assertSame(TLS::MIN_TLS_VERSION, $tls->minProtocol);
    }

    public function testConstructorCustomValues(): void
    {
        $tls = new TLS(
            certificate: '/certs/server.crt',
            key: '/certs/server.key',
            ca: '/certs/ca.crt',
            requireClientCert: true,
            ciphers: 'ECDHE-RSA-AES128-GCM-SHA256',
            minProtocol: SWOOLE_SSL_TLSv1_3,
        );

        $this->assertSame('/certs/ca.crt', $tls->ca);
        $this->assertTrue($tls->requireClientCert);
        $this->assertSame('ECDHE-RSA-AES128-GCM-SHA256', $tls->ciphers);
        $this->assertSame(SWOOLE_SSL_TLSv1_3, $tls->minProtocol);
    }

    public function testDefaultCiphersContainsModernSuites(): void
    {
        $this->assertStringContainsString('ECDHE-ECDSA-AES128-GCM-SHA256', TLS::DEFAULT_CIPHERS);
        $this->assertStringContainsString('ECDHE-RSA-AES256-GCM-SHA384', TLS::DEFAULT_CIPHERS);
        $this->assertStringContainsString('CHACHA20-POLY1305', TLS::DEFAULT_CIPHERS);
    }

    public function testValidatePassesWithReadableFiles(): void
    {
        $certFile = tempnam(sys_get_temp_dir(), 'cert_');
        $keyFile = tempnam(sys_get_temp_dir(), 'key_');

        try {
            $tls = new TLS(certificate: $certFile, key: $keyFile);
            $tls->validate();
            $this->addToAssertionCount(1);
        } finally {
            unlink($certFile);
            unlink($keyFile);
        }
    }

    public function testValidateThrowsForUnreadableCert(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TLS certificate file not readable');

        $tls = new TLS(certificate: '/nonexistent/cert.crt', key: '/tmp/key.key');
        $tls->validate();
    }

    public function testValidateThrowsForUnreadableKey(): void
    {
        $certFile = tempnam(sys_get_temp_dir(), 'cert_');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('TLS private key file not readable');

            $tls = new TLS(certificate: $certFile, key: '/nonexistent/key.key');
            $tls->validate();
        } finally {
            unlink($certFile);
        }
    }

    public function testValidateThrowsWhenClientCertRequiredButNoCaPath(): void
    {
        $certFile = tempnam(sys_get_temp_dir(), 'cert_');
        $keyFile = tempnam(sys_get_temp_dir(), 'key_');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('CA certificate path is required when client certificate verification is enabled');

            $tls = new TLS(
                certificate: $certFile,
                key: $keyFile,
                requireClientCert: true,
            );
            $tls->validate();
        } finally {
            unlink($certFile);
            unlink($keyFile);
        }
    }

    public function testValidateThrowsForUnreadableCaFile(): void
    {
        $certFile = tempnam(sys_get_temp_dir(), 'cert_');
        $keyFile = tempnam(sys_get_temp_dir(), 'key_');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('TLS CA certificate file not readable');

            $tls = new TLS(
                certificate: $certFile,
                key: $keyFile,
                ca: '/nonexistent/ca.crt',
            );
            $tls->validate();
        } finally {
            unlink($certFile);
            unlink($keyFile);
        }
    }

    public function testValidatePassesWithAllReadableFiles(): void
    {
        $certFile = tempnam(sys_get_temp_dir(), 'cert_');
        $keyFile = tempnam(sys_get_temp_dir(), 'key_');
        $caFile = tempnam(sys_get_temp_dir(), 'ca_');

        try {
            $tls = new TLS(
                certificate: $certFile,
                key: $keyFile,
                ca: $caFile,
                requireClientCert: true,
            );
            $tls->validate();
            $this->addToAssertionCount(1);
        } finally {
            unlink($certFile);
            unlink($keyFile);
            unlink($caFile);
        }
    }

    public function testValidateCaPathOptionalWithoutClientCert(): void
    {
        $certFile = tempnam(sys_get_temp_dir(), 'cert_');
        $keyFile = tempnam(sys_get_temp_dir(), 'key_');

        try {
            // ca is empty and requireClientCert is false — should pass
            $tls = new TLS(certificate: $certFile, key: $keyFile);
            $tls->validate();
            $this->addToAssertionCount(1);
        } finally {
            unlink($certFile);
            unlink($keyFile);
        }
    }

    public function testIsMutualTLSReturnsTrueWhenBothConditionsMet(): void
    {
        $tls = new TLS(
            certificate: '/certs/server.crt',
            key: '/certs/server.key',
            ca: '/certs/ca.crt',
            requireClientCert: true,
        );

        $this->assertTrue($tls->isMutual());
    }

    public function testIsMutualTLSReturnsFalseWhenClientCertNotRequired(): void
    {
        $tls = new TLS(
            certificate: '/certs/server.crt',
            key: '/certs/server.key',
            ca: '/certs/ca.crt',
            requireClientCert: false,
        );

        $this->assertFalse($tls->isMutual());
    }

    public function testIsMutualTLSReturnsFalseWhenCaPathEmpty(): void
    {
        $tls = new TLS(
            certificate: '/certs/server.crt',
            key: '/certs/server.key',
            requireClientCert: true,
        );

        $this->assertFalse($tls->isMutual());
    }

    public function testIsMutualTLSReturnsFalseWithDefaults(): void
    {
        $tls = new TLS(certificate: '/certs/server.crt', key: '/certs/server.key');

        $this->assertFalse($tls->isMutual());
    }

    public function testCoroutineServerUsesPlainListenerForCustomConnectionHandlers(): void
    {
        $source = \file_get_contents(__DIR__ . '/../src/Server/TCP/Swoole/Coroutine.php');

        $this->assertIsString($source);
        $this->assertStringContainsString('new CoroutineServer($this->config->host, $port, false, $this->config->enableReusePort)', $source);
        $this->assertStringContainsString('$this->config->connectionHandler', $source);
        $this->assertStringContainsString('protected function startTLS(Socket $socket): bool', $source);
        $this->assertStringContainsString('$socket->setProtocol($this->tlsContext->toSwooleProtocolConfig())', $source);
        $this->assertStringContainsString('$socket->sslHandshake()', $source);
    }

    public function testTcpConfigAcceptsConnectionHandler(): void
    {
        $handler = static fn (): bool => true;
        $config = new \Utopia\Proxy\Server\TCP\Config(
            ports: [5432],
            connectionHandler: $handler,
        );

        $this->assertSame($handler, $config->connectionHandler);
    }

    public function testCoroutineServerReportsConnectionStats(): void
    {
        $reflection = new ReflectionClass(CoroutineTCPServer::class);
        /** @var CoroutineTCPServer $server */
        $server = $reflection->newInstanceWithoutConstructor();

        $this->assertSame(['connection_num' => 0], $server->stats());
    }

}
