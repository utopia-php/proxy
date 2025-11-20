<?php

namespace Appwrite\ProtocolProxy\Tcp;

use Appwrite\ProtocolProxy\ConnectionManager;
use Appwrite\ProtocolProxy\Resource;
use Swoole\Coroutine\Client;
use Utopia\Cache\Cache;
use Utopia\Database\Query;
use Utopia\Pools\Group;

/**
 * TCP-specific connection manager for database connections
 *
 * Handles PostgreSQL (5432) and MySQL (3306) connections
 */
class TcpConnectionManager extends ConnectionManager
{
    protected int $port;
    protected array $backendConnections = [];

    public function __construct(
        Cache $cache,
        Group $dbPool,
        string $computeApiUrl,
        string $computeApiKey,
        int $port,
        int $coldStartTimeout = 30000,
        int $healthCheckInterval = 100
    ) {
        parent::__construct($cache, $dbPool, $computeApiUrl, $computeApiKey, $coldStartTimeout, $healthCheckInterval);
        $this->port = $port;
    }

    protected function identifyResource(string $resourceId): Resource
    {
        // For TCP: resourceId is database ID extracted from SNI/hostname
        $db = $this->dbPool->get();

        try {
            $doc = $db->findOne('databases', [
                Query::equal('hostname', [$resourceId])
            ]);

            if (empty($doc)) {
                throw new \Exception("Database not found for hostname: {$resourceId}");
            }

            return new Resource(
                id: $doc->getId(),
                containerId: $doc->getAttribute('containerId'),
                type: 'database',
                tier: $doc->getAttribute('tier', 'shared'),
                region: $doc->getAttribute('region')
            );
        } finally {
            $this->dbPool->put($db);
        }
    }

    protected function getProtocol(): string
    {
        return $this->port === 5432 ? 'postgresql' : 'mysql';
    }

    /**
     * Parse database ID from TCP packet
     *
     * For PostgreSQL: Extract from SNI or startup message
     * For MySQL: Extract from initial handshake
     */
    public function parseDatabaseId(string $data, int $fd): string
    {
        if ($this->port === 5432) {
            return $this->parsePostgreSQLDatabaseId($data);
        } else {
            return $this->parseMySQLDatabaseId($data);
        }
    }

    /**
     * Parse PostgreSQL database ID from startup message
     *
     * Format: "database\0db-abc123\0"
     */
    protected function parsePostgreSQLDatabaseId(string $data): string
    {
        // PostgreSQL startup message contains database name
        if (preg_match('/database\x00([^\x00]+)\x00/', $data, $matches)) {
            $dbName = $matches[1];

            // Extract database ID from format: db-{id}.appwrite.network
            if (preg_match('/^db-([a-z0-9]+)/', $dbName, $idMatches)) {
                return $idMatches[1];
            }
        }

        throw new \Exception('Invalid PostgreSQL database name');
    }

    /**
     * Parse MySQL database ID from connection
     *
     * For MySQL, we typically get the database from subsequent COM_INIT_DB packet
     */
    protected function parseMySQLDatabaseId(string $data): string
    {
        // MySQL COM_INIT_DB packet (0x02)
        if (strlen($data) > 5 && ord($data[4]) === 0x02) {
            $dbName = substr($data, 5);

            // Extract database ID from format: db-{id}
            if (preg_match('/^db-([a-z0-9]+)/', $dbName, $matches)) {
                return $matches[1];
            }
        }

        throw new \Exception('Invalid MySQL database name');
    }

    /**
     * Get or create backend connection
     *
     * Performance: Reuses connections for same database
     */
    public function getBackendConnection(string $databaseId, int $clientFd): int
    {
        // Check if we already have a connection for this database
        $cacheKey = "backend:connection:{$databaseId}:{$clientFd}";

        if (isset($this->backendConnections[$cacheKey])) {
            return $this->backendConnections[$cacheKey];
        }

        // Get backend endpoint
        $result = $this->handleConnection($databaseId);

        // Create new TCP connection to backend
        [$host, $port] = explode(':', $result->endpoint . ':' . $this->port);
        $port = (int)$port;

        $client = new Client(SWOOLE_SOCK_TCP);

        if (!$client->connect($host, $port, $this->coldStartTimeout / 1000)) {
            throw new \Exception("Failed to connect to backend: {$host}:{$port}");
        }

        // Store backend file descriptor
        $backendFd = $client->sock;
        $this->backendConnections[$cacheKey] = $backendFd;

        return $backendFd;
    }

    /**
     * Close backend connection
     */
    public function closeBackendConnection(string $databaseId, int $clientFd): void
    {
        $cacheKey = "backend:connection:{$databaseId}:{$clientFd}";

        if (isset($this->backendConnections[$cacheKey])) {
            unset($this->backendConnections[$cacheKey]);
        }
    }
}
