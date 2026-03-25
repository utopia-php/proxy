<?php

namespace Utopia\Proxy\Server\HTTP;

use Utopia\Proxy\ConnectionResult;

readonly class Telemetry
{
    public function __construct(
        public float $startTime,
        public ?ConnectionResult $result = null,
    ) {
    }
}
