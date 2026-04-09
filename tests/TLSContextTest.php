<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Server\TCP\TLS;
use Utopia\Proxy\Server\TCP\TLSContext;

class TLSContextTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to run TLSContext tests.');
        }
        if (!\defined('SWOOLE_SSL_TLSv1_2')) {
            $this->markTestSkipped('Swoole was built without OpenSSL support.');
        }
    }

    public function testToSwooleConfigBasic(): void
    {
        $tls = new TLS(certificate: '/certs/server.crt', key: '/certs/server.key');
        $ctx = new TLSContext($tls);

        $config = $ctx->toSwooleConfig();

        $this->assertSame('/certs/server.crt', $config['ssl_cert_file']);
        $this->assertSame('/certs/server.key', $config['ssl_key_file']);
        $this->assertSame(TLS::DEFAULT_CIPHERS, $config['ssl_ciphers']);
        $this->assertSame(SWOOLE_SSL_TLSv1_2 | SWOOLE_SSL_TLSv1_3, $config['ssl_protocols']);
        $this->assertFalse($config['ssl_allow_self_signed']);
        $this->assertFalse($config['ssl_verify_peer']);
        $this->assertArrayNotHasKey('ssl_client_cert_file', $config);
        $this->assertArrayNotHasKey('ssl_verify_depth', $config);
    }

    public function testToSwooleConfigWithCaPath(): void
    {
        $tls = new TLS(
            certificate: '/certs/server.crt',
            key: '/certs/server.key',
            ca: '/certs/ca.crt',
        );
        $ctx = new TLSContext($tls);

        $config = $ctx->toSwooleConfig();

        $this->assertSame('/certs/ca.crt', $config['ssl_client_cert_file']);
        $this->assertFalse($config['ssl_verify_peer']);
    }

    public function testToSwooleConfigWithMutualTLS(): void
    {
        $tls = new TLS(
            certificate: '/certs/server.crt',
            key: '/certs/server.key',
            ca: '/certs/ca.crt',
            requireClientCert: true,
        );
        $ctx = new TLSContext($tls);

        $config = $ctx->toSwooleConfig();

        $this->assertSame('/certs/ca.crt', $config['ssl_client_cert_file']);
        $this->assertTrue($config['ssl_verify_peer']);
        $this->assertSame(10, $config['ssl_verify_depth']);
    }

    public function testToSwooleConfigWithCustomCiphers(): void
    {
        $customCiphers = 'ECDHE-RSA-AES128-GCM-SHA256';
        $tls = new TLS(
            certificate: '/certs/server.crt',
            key: '/certs/server.key',
            ciphers: $customCiphers,
        );
        $ctx = new TLSContext($tls);

        $config = $ctx->toSwooleConfig();

        $this->assertSame($customCiphers, $config['ssl_ciphers']);
    }

    public function testToStreamContextReturnsResource(): void
    {
        $tls = new TLS(certificate: '/certs/server.crt', key: '/certs/server.key');
        $ctx = new TLSContext($tls);

        $streamCtx = $ctx->toStreamContext();

        $this->assertSame('stream-context', get_resource_type($streamCtx));
    }

    public function testToStreamContextHasCorrectSslOptions(): void
    {
        $tls = new TLS(certificate: '/certs/server.crt', key: '/certs/server.key');
        $ctx = new TLSContext($tls);

        $streamCtx = $ctx->toStreamContext();
        /** @var array<string, array<string, mixed>> $options */
        $options = stream_context_get_options($streamCtx);

        $this->assertArrayHasKey('ssl', $options);
        /** @var array<string, mixed> $ssl */
        $ssl = $options['ssl'];
        $this->assertSame('/certs/server.crt', $ssl['local_cert']);
        $this->assertSame('/certs/server.key', $ssl['local_pk']);
        $this->assertTrue($ssl['disable_compression']);
        $this->assertFalse($ssl['allow_self_signed']);
        $this->assertFalse($ssl['verify_peer']);
        $this->assertFalse($ssl['verify_peer_name']);
    }

    public function testToStreamContextWithCaFile(): void
    {
        $tls = new TLS(
            certificate: '/certs/server.crt',
            key: '/certs/server.key',
            ca: '/certs/ca.crt',
        );
        $ctx = new TLSContext($tls);

        $streamCtx = $ctx->toStreamContext();
        /** @var array<string, array<string, mixed>> $options */
        $options = stream_context_get_options($streamCtx);
        /** @var array<string, mixed> $ssl */
        $ssl = $options['ssl'];

        $this->assertSame('/certs/ca.crt', $ssl['cafile']);
    }

    public function testToStreamContextWithMutualTLS(): void
    {
        $tls = new TLS(
            certificate: '/certs/server.crt',
            key: '/certs/server.key',
            ca: '/certs/ca.crt',
            requireClientCert: true,
        );
        $ctx = new TLSContext($tls);

        $streamCtx = $ctx->toStreamContext();
        /** @var array<string, array<string, mixed>> $options */
        $options = stream_context_get_options($streamCtx);
        /** @var array<string, mixed> $ssl */
        $ssl = $options['ssl'];

        $this->assertTrue($ssl['verify_peer']);
        $this->assertFalse($ssl['verify_peer_name']);
        $this->assertSame(10, $ssl['verify_depth']);
    }

    public function testToStreamContextWithoutCaFile(): void
    {
        $tls = new TLS(certificate: '/certs/server.crt', key: '/certs/server.key');
        $ctx = new TLSContext($tls);

        $streamCtx = $ctx->toStreamContext();
        /** @var array<string, array<string, mixed>> $options */
        $options = stream_context_get_options($streamCtx);
        /** @var array<string, mixed> $ssl */
        $ssl = $options['ssl'];

        $this->assertArrayNotHasKey('cafile', $ssl);
    }

    public function testGetSocketTypeIncludesSslFlag(): void
    {
        $tls = new TLS(certificate: '/certs/server.crt', key: '/certs/server.key');
        $ctx = new TLSContext($tls);

        $socketType = $ctx->getSocketType();

        $this->assertSame(SWOOLE_SOCK_TCP | SWOOLE_SSL, $socketType);
    }

    public function testGetTlsReturnsOriginalInstance(): void
    {
        $tls = new TLS(certificate: '/certs/server.crt', key: '/certs/server.key');
        $ctx = new TLSContext($tls);

        $this->assertSame($tls, $ctx->getTls());
    }
}
