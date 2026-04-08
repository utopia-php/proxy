<?php

namespace Utopia\Proxy;

use Swoole\Coroutine;
use Swoole\Coroutine\System;

/**
 * Coroutine-aware DNS resolver with per-worker TTL cache.
 *
 * Replaces blocking gethostbyname() calls in the hot path. When called from a
 * coroutine context, uses Swoole's async resolver; otherwise falls back to the
 * blocking libc resolver (used by tests and non-server code).
 *
 * Successful lookups are cached until the TTL expires; failures are not cached
 * so transient issues resolve themselves on the next attempt.
 */
class Dns
{
    /** @var array<string, array{ip: string, expires: int}> */
    private static array $cache = [];

    private static int $ttl = 60;

    public static function setTtl(int $seconds): void
    {
        self::$ttl = $seconds;
    }

    public static function ttl(): int
    {
        return self::$ttl;
    }

    public static function clear(): void
    {
        self::$cache = [];
    }

    /**
     * Resolve a hostname to an IPv4 address.
     *
     * Returns the original input when it is already a literal IP or cannot be
     * resolved — callers are responsible for distinguishing the two via their
     * own validation, matching the contract of gethostbyname().
     */
    public static function resolve(string $host, float $timeout = 1.0): string
    {
        if ($host === '' || \filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        $now = \time();
        $entry = self::$cache[$host] ?? null;
        if ($entry !== null && $entry['expires'] > $now) {
            return $entry['ip'];
        }

        if (Coroutine::getCid() > 0) {
            /** @var string|false $ip */
            $ip = System::gethostbyname($host, AF_INET, $timeout);
            if ($ip === false) {
                return $host;
            }
        } else {
            $ip = \gethostbyname($host);
        }

        if ($ip !== $host && \filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            self::$cache[$host] = [
                'ip' => $ip,
                'expires' => $now + self::$ttl,
            ];
        }

        return $ip;
    }
}
