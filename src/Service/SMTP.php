<?php

namespace Utopia\Proxy\Service;

use Utopia\Platform\Service;

class SMTP extends Service
{
    public function __construct()
    {
        $this->setType('proxy.smtp');
    }
}
