<?php

namespace Utopia\Proxy\Server\SMTP;

use Swoole\Coroutine\Client;

class Connection
{
    public string $state = 'command';

    public ?string $domain = null;

    public ?Client $backend = null;

    public function isData(): bool
    {
        return $this->state === 'data';
    }
}
