<?php

namespace Utopia\Proxy\Server\SMTP;

use Swoole\Coroutine\Client;

class Connection
{
    public string $state = 'greeting';

    public ?string $domain = null;

    public ?Client $backend = null;
}
