<?php

namespace Utopia\Proxy\Server\TCP;

/**
 * TLS Context Builder
 *
 * Wraps TLS configuration into formats consumable by:
 * - Swoole Server SSL settings (for the event-driven server)
 * - PHP stream_context_create (for coroutine server / manual sockets)
 *
 * Encapsulates the translation from our TLS config to the underlying
 * SSL library parameters.
 *
 * Example:
 * ```php
 * $tls = new TLS(certificate: '/certs/server.crt', key: '/certs/server.key');
 * $ctx = new TLSContext($tls);
 *
 * // For Swoole Server::set()
 * $server->set($ctx->toSwooleConfig());
 *
 * // For stream_context_create
 * $streamCtx = $ctx->toStreamContext();
 * ```
 */
class TLSContext
{
    public function __construct(
        protected TLS $tls,
    ) {
    }

    /**
     * Build Swoole server SSL configuration array
     *
     * Returns settings suitable for Swoole\Server::set() when the server
     * is created with SWOOLE_SOCK_TCP | SWOOLE_SSL socket type.
     *
     * @return array<string, mixed>
     */
    public function toSwooleConfig(): array
    {
        $config = [
            'ssl_cert_file' => $this->tls->certificate,
            'ssl_key_file' => $this->tls->key,
            'ssl_protocols' => $this->protocolMask($this->tls->minProtocol),
            'ssl_ciphers' => $this->tls->ciphers,
            'ssl_allow_self_signed' => false,
        ];

        if ($this->tls->ca !== '') {
            $config['ssl_client_cert_file'] = $this->tls->ca;
        }

        if ($this->tls->requireClientCert) {
            $config['ssl_verify_peer'] = true;
            $config['ssl_verify_depth'] = 10;
        } else {
            $config['ssl_verify_peer'] = false;
        }

        return $config;
    }

    /**
     * Build a PHP stream context resource for SSL connections
     *
     * Returns a context resource that can be used with stream_socket_server,
     * stream_socket_enable_crypto, and similar stream functions.
     *
     * @return resource
     */
    public function toStreamContext(): mixed
    {
        $sslOptions = [
            'local_cert' => $this->tls->certificate,
            'local_pk' => $this->tls->key,
            'disable_compression' => true,
            'allow_self_signed' => false,
            'ciphers' => $this->tls->ciphers,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER,
        ];

        if ($this->tls->ca !== '') {
            $sslOptions['cafile'] = $this->tls->ca;
        }

        if ($this->tls->requireClientCert) {
            $sslOptions['verify_peer'] = true;
            $sslOptions['verify_peer_name'] = false;
            $sslOptions['verify_depth'] = 10;
        } else {
            $sslOptions['verify_peer'] = false;
            $sslOptions['verify_peer_name'] = false;
        }

        return stream_context_create(['ssl' => $sslOptions]);
    }

    private function protocolMask(int $minimum): int
    {
        $protocols = [
            SWOOLE_SSL_TLSv1 => 1,
            SWOOLE_SSL_TLSv1_1 => 2,
            SWOOLE_SSL_TLSv1_2 => 3,
            SWOOLE_SSL_TLSv1_3 => 4,
        ];

        $minOrder = $protocols[$minimum] ?? 3;
        $mask = 0;

        foreach ($protocols as $constant => $order) {
            if ($order >= $minOrder) {
                $mask |= $constant;
            }
        }

        return $mask;
    }

    /**
     * Get the Swoole socket type flag for TLS-enabled TCP
     *
     * Combines SWOOLE_SOCK_TCP with SWOOLE_SSL when TLS is configured.
     */
    public function getSocketType(): int
    {
        return SWOOLE_SOCK_TCP | SWOOLE_SSL;
    }

    /**
     * Get the underlying TLS configuration
     */
    public function getTls(): TLS
    {
        return $this->tls;
    }
}
