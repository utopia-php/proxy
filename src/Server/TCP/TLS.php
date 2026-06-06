<?php

namespace Utopia\Proxy\Server\TCP;

use Utopia\Validator\Text;

/**
 * TLS Configuration for TCP Proxy Server
 *
 * Holds certificate paths, protocol constraints, cipher configuration,
 * and mTLS (mutual TLS) settings for TLS-terminated TCP connections.
 *
 * This class only describes TLS material and policy. Protocols that negotiate
 * TLS after plaintext bytes should implement that negotiation outside the
 * generic proxy core.
 *
 * Example:
 * ```php
 * $tls = new TLS(
 *     certificate: '/certs/server.crt',
 *     key: '/certs/server.key',
 *     ca: '/certs/ca.crt',
 *     requireClientCert: true,
 * );
 * $config = new Config(tls: $tls);
 * ```
 */
class TLS
{
    /**
     * Default cipher suites — strong, modern, broadly compatible
     */
    public const DEFAULT_CIPHERS = 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:'
        . 'ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:'
        . 'ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:'
        . 'DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384';

    /**
     * Minimum TLS protocol version (TLS 1.2).
     *
     * The numeric value matches SWOOLE_SSL_TLSv1_2 in Swoole's source and
     * is hardcoded here so the class loads on Swoole builds compiled
     * without OpenSSL support — the constant would otherwise be
     * undefined at class load time, before any TLS code runs.
     */
    public const MIN_TLS_VERSION = 32;

    public function __construct(
        public readonly string $certificate,
        public readonly string $key,
        public readonly string $ca = '',
        public readonly bool $requireClientCert = false,
        public readonly string $ciphers = self::DEFAULT_CIPHERS,
        public readonly int $minProtocol = self::MIN_TLS_VERSION,
    ) {
    }

    /**
     * Validate that the configured certificate files exist and are readable
     *
     * @throws \RuntimeException If any required file is missing or unreadable
     */
    public function validate(): void
    {
        $path = new Text(4096);

        if (!$path->isValid($this->certificate)) {
            throw new \RuntimeException("TLS certificate path is invalid: {$path->getDescription()}");
        }

        if (!is_readable($this->certificate)) {
            throw new \RuntimeException("TLS certificate file not readable: {$this->certificate}");
        }

        if (!$path->isValid($this->key)) {
            throw new \RuntimeException("TLS key path is invalid: {$path->getDescription()}");
        }

        if (!is_readable($this->key)) {
            throw new \RuntimeException("TLS private key file not readable: {$this->key}");
        }

        if ($this->requireClientCert && $this->ca === '') {
            throw new \RuntimeException('CA certificate path is required when client certificate verification is enabled');
        }

        if ($this->ca !== '' && !$path->isValid($this->ca)) {
            throw new \RuntimeException("TLS CA path is invalid: {$path->getDescription()}");
        }

        if ($this->ca !== '' && !is_readable($this->ca)) {
            throw new \RuntimeException("TLS CA certificate file not readable: {$this->ca}");
        }
    }

    /**
     * Check if this is an mTLS configuration (requires client certificates)
     */
    public function isMutual(): bool
    {
        return $this->requireClientCert && $this->ca !== '';
    }

}
