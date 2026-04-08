<?php

namespace Utopia\Proxy\Server\TCP;

use Swoole\Coroutine\Client;

/**
 * Per-connection state struct.
 *
 * One instance lives in an SplFixedArray slot keyed by file descriptor while
 * a client is connected. Replaces the previous map of three independent
 * associative arrays (backends, ports, pending TLS) with a single cache-line
 * friendly object lookup.
 */
class Connection
{
    public ?Client $backend = null;

    public int $port = 0;

    public bool $pendingTls = false;

    public int $inbound = 0;

    public int $outbound = 0;

    public function reset(): void
    {
        $this->backend = null;
        $this->port = 0;
        $this->pendingTls = false;
        $this->inbound = 0;
        $this->outbound = 0;
    }
}
