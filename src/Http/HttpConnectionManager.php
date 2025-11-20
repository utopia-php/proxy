<?php

namespace Appwrite\ProtocolProxy\Http;

use Appwrite\ProtocolProxy\ConnectionManager;
use Appwrite\ProtocolProxy\Resource;
use Utopia\Database\Query;

/**
 * HTTP-specific connection manager
 *
 * Handles routing for HTTP function executions
 */
class HttpConnectionManager extends ConnectionManager
{
    protected function identifyResource(string $resourceId): Resource
    {
        // For HTTP: resourceId is hostname (e.g., func-abc123.appwrite.network)
        $db = $this->dbPool->get();

        try {
            $doc = $db->findOne('functions', [
                Query::equal('hostname', [$resourceId])
            ]);

            if (empty($doc)) {
                throw new \Exception("Function not found for hostname: {$resourceId}");
            }

            return new Resource(
                id: $doc->getId(),
                containerId: $doc->getAttribute('containerId'),
                type: 'function',
                tier: $doc->getAttribute('tier', 'shared'),
                region: $doc->getAttribute('region')
            );
        } finally {
            $this->dbPool->put($db);
        }
    }

    protected function getProtocol(): string
    {
        return 'http';
    }
}
