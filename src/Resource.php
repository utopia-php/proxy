<?php

namespace Appwrite\ProtocolProxy;

/**
 * Value object representing a proxied resource
 */
class Resource
{
    public function __construct(
        public string $id,
        public string $containerId,
        public string $type,  // 'database', 'function', 'smtp-server'
        public string $tier,  // 'shared', 'dedicated'
        public string $region
    ) {}
}
