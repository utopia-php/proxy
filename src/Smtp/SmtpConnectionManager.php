<?php

namespace Appwrite\ProtocolProxy\Smtp;

use Appwrite\ProtocolProxy\ConnectionManager;
use Appwrite\ProtocolProxy\Resource;
use Utopia\Database\Query;

/**
 * SMTP-specific connection manager
 *
 * Handles routing for SMTP servers based on domain
 */
class SmtpConnectionManager extends ConnectionManager
{
    protected function identifyResource(string $resourceId): Resource
    {
        // For SMTP: resourceId is email domain (e.g., tenant123.appwrite.io)
        $db = $this->dbPool->get();

        try {
            $doc = $db->findOne('smtpServers', [
                Query::equal('domain', [$resourceId])
            ]);

            if (empty($doc)) {
                throw new \Exception("SMTP server not found for domain: {$resourceId}");
            }

            return new Resource(
                id: $doc->getId(),
                containerId: $doc->getAttribute('containerId'),
                type: 'smtp-server',
                tier: $doc->getAttribute('tier', 'shared'),
                region: $doc->getAttribute('region')
            );
        } finally {
            $this->dbPool->put($db);
        }
    }

    protected function getProtocol(): string
    {
        return 'smtp';
    }
}
