<?php

namespace Utopia\Proxy\Service;

use Utopia\Platform\Service;

class TCP extends Service
{
    public function __construct()
    {
        $this->setType('proxy.tcp');
    }
}
