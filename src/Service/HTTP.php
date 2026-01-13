<?php

namespace Utopia\Proxy\Service;

use Utopia\Platform\Service;

class HTTP extends Service
{
    public function __construct()
    {
        $this->setType('proxy.http');
    }
}
