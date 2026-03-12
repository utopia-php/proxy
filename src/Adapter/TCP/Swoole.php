<?php

namespace Utopia\Proxy\Adapter\TCP;

use Swoole\Coroutine\Client;
use Utopia\Proxy\Adapter;
use Utopia\Proxy\ConnectionResult;
use Utopia\Proxy\QueryParser;
use Utopia\Proxy\Resolver;
use Utopia\Proxy\Resolver\Exception as ResolverException;
use Utopia\Proxy\Resolver\ReadWriteResolver;

/**
 * TCP Protocol Adapter (Swoole Implementation)
 *
 * Routes TCP connections (PostgreSQL, MySQL) based on database hostname/SNI.
 * Supports optional read/write split routing via QueryParser and ReadWriteResolver.
 *
 * Routing:
 * - Input: Database hostname extracted from SNI or startup message
 * - Resolution: Provided by Resolver implementation
 * - Output: Backend endpoint (IP:port)
 *
 * Read/Write Split:
 * - When enabled, inspects each query packet to determine read vs write
 * - Read queries route to replicas via resolveRead()
 * - Write queries and transactions pin to primary via resolveWrite()
 * - Transaction state tracked per-connection (BEGIN pins, COMMIT/ROLLBACK unpins)
 *
 * Performance (validated on 8-core/32GB):
 * - 670k+ concurrent connections
 * - 18k connections/sec establishment rate
 * - ~33KB memory per connection
 * - Minimal-copy forwarding (128KB buffers, no payload parsing)
 *
 * Example:
 * ```php
 * $resolver = new MyDatabaseResolver();
 * $adapter = new TCP($resolver, port: 5432);
 * $adapter->setReadWriteSplit(true); // Enable read/write routing
 * ```
 */
class Swoole extends Adapter
{
    /** @var array<string, Client> */
    protected array $backendConnections = [];

    /** @var float Backend connection timeout in seconds */
    protected float $connectTimeout = 5.0;

    /** @var bool Whether read/write split routing is enabled */
    protected bool $readWriteSplit = false;

    /** @var QueryParser|null Lazy-initialized query parser */
    protected ?QueryParser $queryParser = null;

    /**
     * Per-connection transaction pinning state.
     * When a connection is in a transaction, all queries are routed to primary.
     *
     * @var array<int, bool>
     */
    protected array $pinnedConnections = [];

    public function __construct(
        Resolver $resolver,
        public int $port {
            get {
                return $this->port;
            }
        }
    ) {
        parent::__construct($resolver);
    }

    /**
     * Set backend connection timeout
     */
    public function setConnectTimeout(float $timeout): static
    {
        $this->connectTimeout = $timeout;

        return $this;
    }

    /**
     * Enable or disable read/write split routing
     *
     * When enabled, the adapter inspects each data packet to classify queries
     * and route reads to replicas and writes to the primary.
     * Requires the resolver to implement ReadWriteResolver for full functionality.
     * Falls back to normal resolve() if the resolver does not implement it.
     */
    public function setReadWriteSplit(bool $enabled): static
    {
        $this->readWriteSplit = $enabled;

        return $this;
    }

    /**
     * Check if read/write split is enabled
     */
    public function isReadWriteSplit(): bool
    {
        return $this->readWriteSplit;
    }

    /**
     * Check if a connection is pinned to primary (in a transaction)
     */
    public function isConnectionPinned(int $clientFd): bool
    {
        return $this->pinnedConnections[$clientFd] ?? false;
    }

    /**
     * Get adapter name
     */
    public function getName(): string
    {
        return 'TCP';
    }

    /**
     * Get protocol type
     */
    public function getProtocol(): string
    {
        return match ($this->port) {
            5432 => 'postgresql',
            27017 => 'mongodb',
            default => 'mysql',
        };
    }

    /**
     * Get adapter description
     */
    public function getDescription(): string
    {
        return 'TCP proxy adapter for database connections (PostgreSQL, MySQL, MongoDB)';
    }

    /**
     * Parse database ID from TCP packet
     *
     * For PostgreSQL: Extract from SNI or startup message
     * For MySQL: Extract from initial handshake
     *
     * @throws \Exception
     */
    public function parseDatabaseId(string $data, int $fd): string
    {
        return match ($this->getProtocol()) {
            'postgresql' => $this->parsePostgreSQLDatabaseId($data),
            'mongodb' => $this->parseMongoDatabaseId($data),
            default => $this->parseMySQLDatabaseId($data),
        };
    }

    /**
     * Classify a data packet for read/write routing
     *
     * Determines whether a query packet should be routed to a read replica
     * or the primary writer. Handles transaction pinning automatically.
     *
     * @param  string  $data  Raw protocol data packet
     * @param  int  $clientFd  Client file descriptor for transaction tracking
     * @return string QueryParser::READ or QueryParser::WRITE
     */
    public function classifyQuery(string $data, int $clientFd): string
    {
        if (!$this->readWriteSplit) {
            return QueryParser::WRITE;
        }

        // If connection is pinned to primary (in transaction), everything goes to primary
        if ($this->isConnectionPinned($clientFd)) {
            $classification = $this->getQueryParser()->parse($data, $this->getProtocol());

            // Check for transaction end to unpin
            if ($classification === QueryParser::TRANSACTION) {
                $query = $this->extractQueryText($data);
                $keyword = $this->getQueryParser()->extractKeyword($query);

                if ($keyword === 'COMMIT' || $keyword === 'ROLLBACK') {
                    unset($this->pinnedConnections[$clientFd]);
                }
            }

            return QueryParser::WRITE;
        }

        $classification = $this->getQueryParser()->parse($data, $this->getProtocol());

        // Transaction commands pin to primary
        if ($classification === QueryParser::TRANSACTION) {
            $query = $this->extractQueryText($data);
            $keyword = $this->getQueryParser()->extractKeyword($query);

            // BEGIN/START pin to primary
            if ($keyword === 'BEGIN' || $keyword === 'START') {
                $this->pinnedConnections[$clientFd] = true;
            }

            return QueryParser::WRITE;
        }

        // UNKNOWN goes to primary for safety
        if ($classification === QueryParser::UNKNOWN) {
            return QueryParser::WRITE;
        }

        return $classification;
    }

    /**
     * Route a query to the appropriate backend (read replica or primary)
     *
     * @param  string  $resourceId  Database/resource identifier
     * @param  string  $queryType  QueryParser::READ or QueryParser::WRITE
     * @return ConnectionResult Resolved backend endpoint
     *
     * @throws ResolverException
     */
    public function routeQuery(string $resourceId, string $queryType): ConnectionResult
    {
        // If read/write split is disabled or resolver doesn't support it, use default routing
        if (!$this->readWriteSplit || !($this->resolver instanceof ReadWriteResolver)) {
            return $this->route($resourceId);
        }

        if ($queryType === QueryParser::READ) {
            return $this->routeRead($resourceId);
        }

        return $this->routeWrite($resourceId);
    }

    /**
     * Clear transaction pinning state for a connection
     *
     * Should be called when a client disconnects to clean up state.
     */
    public function clearConnectionState(int $clientFd): void
    {
        unset($this->pinnedConnections[$clientFd]);
    }

    /**
     * Parse PostgreSQL database ID from startup message
     *
     * Format: "database\0db-abc123\0"
     *
     * @throws \Exception
     */
    protected function parsePostgreSQLDatabaseId(string $data): string
    {
        // Fast path: find "database\0" marker
        $marker = "database\x00";
        $pos = \strpos($data, $marker);
        if ($pos === false) {
            throw new \Exception('Invalid PostgreSQL database name');
        }

        // Extract database name until next null byte
        $start = $pos + 9; // strlen("database\0")
        $end = strpos($data, "\x00", $start);
        if ($end === false) {
            throw new \Exception('Invalid PostgreSQL database name');
        }

        $dbName = substr($data, $start, $end - $start);

        // Must start with "db-"
        if (strncmp($dbName, 'db-', 3) !== 0) {
            throw new \Exception('Invalid PostgreSQL database name');
        }

        // Extract ID (alphanumeric after "db-", stop at dot or end)
        $idStart = 3;
        $len = \strlen($dbName);
        $idEnd = $idStart;

        while ($idEnd < $len) {
            $c = $dbName[$idEnd];
            if ($c === '.') {
                break;
            }
            // Allow a-z, A-Z, 0-9
            if (($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z') || ($c >= '0' && $c <= '9')) {
                $idEnd++;
            } else {
                throw new \Exception('Invalid PostgreSQL database name');
            }
        }

        if ($idEnd === $idStart) {
            throw new \Exception('Invalid PostgreSQL database name');
        }

        return \substr($dbName, $idStart, $idEnd - $idStart);
    }

    /**
     * Parse MySQL database ID from connection
     *
     * For MySQL, we typically get the database from subsequent COM_INIT_DB packet
     *
     * @throws \Exception
     */
    protected function parseMySQLDatabaseId(string $data): string
    {
        // MySQL COM_INIT_DB packet (0x02)
        $len = strlen($data);
        if ($len <= 5 || \ord($data[4]) !== 0x02) {
            throw new \Exception('Invalid MySQL database name');
        }

        // Extract database name, removing null terminator
        $dbName = \substr($data, 5);
        $nullPos = \strpos($dbName, "\x00");
        if ($nullPos !== false) {
            $dbName = \substr($dbName, 0, $nullPos);
        }

        // Must start with "db-"
        if (\strncmp($dbName, 'db-', 3) !== 0) {
            throw new \Exception('Invalid MySQL database name');
        }

        // Extract ID (alphanumeric after "db-", stop at dot or end)
        $idStart = 3;
        $nameLen = \strlen($dbName);
        $idEnd = $idStart;

        while ($idEnd < $nameLen) {
            $c = $dbName[$idEnd];
            if ($c === '.') {
                break;
            }
            // Allow a-z, A-Z, 0-9
            if (($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z') || ($c >= '0' && $c <= '9')) {
                $idEnd++;
            } else {
                throw new \Exception('Invalid MySQL database name');
            }
        }

        if ($idEnd === $idStart) {
            throw new \Exception('Invalid MySQL database name');
        }

        return \substr($dbName, $idStart, $idEnd - $idStart);
    }

    /**
     * Parse MongoDB database ID from OP_MSG
     *
     * MongoDB OP_MSG contains a BSON document with a "$db" field holding the database name.
     * We search for the "$db\0" marker and extract the following BSON string value.
     *
     * @throws \Exception
     */
    protected function parseMongoDatabaseId(string $data): string
    {
        // MongoDB OP_MSG: header (16 bytes) + flagBits (4 bytes) + section kind (1 byte) + BSON document
        // The BSON document contains a "$db" field with the database name
        // Look for the "$db\0" marker in the data
        $marker = "\$db\0";
        $pos = \strpos($data, $marker);

        if ($pos === false) {
            throw new \Exception('Invalid MongoDB database name');
        }

        // After "$db\0" comes the BSON type byte (0x02 = string), then:
        // 4 bytes little-endian string length, then the null-terminated string
        $offset = $pos + \strlen($marker);

        if ($offset + 4 >= \strlen($data)) {
            throw new \Exception('Invalid MongoDB database name');
        }

        $strLen = \unpack('V', \substr($data, $offset, 4))[1];
        $offset += 4;

        if ($offset + $strLen > \strlen($data)) {
            throw new \Exception('Invalid MongoDB database name');
        }

        $dbName = \substr($data, $offset, $strLen - 1); // -1 for null terminator

        if (\strncmp($dbName, 'db-', 3) !== 0) {
            throw new \Exception('Invalid MongoDB database name');
        }

        // Extract ID (alphanumeric after "db-", stop at dot or end)
        $idStart = 3;
        $nameLen = \strlen($dbName);
        $idEnd = $idStart;

        while ($idEnd < $nameLen) {
            $c = $dbName[$idEnd];
            if ($c === '.') {
                break;
            }
            // Allow a-z, A-Z, 0-9
            if (($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z') || ($c >= '0' && $c <= '9')) {
                $idEnd++;
            } else {
                throw new \Exception('Invalid MongoDB database name');
            }
        }

        if ($idEnd === $idStart) {
            throw new \Exception('Invalid MongoDB database name');
        }

        return \substr($dbName, $idStart, $idEnd - $idStart);
    }

    /**
     * Get or create backend connection
     *
     * Performance: Reuses connections for same database
     *
     * @throws \Exception
     */
    public function getBackendConnection(string $databaseId, int $clientFd): Client
    {
        // Check if we already have a connection for this database
        $cacheKey = "backend:connection:{$databaseId}:{$clientFd}";

        if (isset($this->backendConnections[$cacheKey])) {
            return $this->backendConnections[$cacheKey];
        }

        // Get backend endpoint via routing
        $result = $this->route($databaseId);

        // Create new TCP connection to backend
        [$host, $port] = \explode(':', $result->endpoint.':'.$this->port);
        $port = (int) $port;

        $client = new Client(SWOOLE_SOCK_TCP);

        // Optimize socket for low latency
        $client->set([
            'timeout' => $this->connectTimeout,
            'connect_timeout' => $this->connectTimeout,
            'open_tcp_nodelay' => true, // Disable Nagle's algorithm
            'socket_buffer_size' => 2 * 1024 * 1024, // 2MB buffer
        ]);

        if (!$client->connect($host, $port, $this->connectTimeout)) {
            throw new \Exception("Failed to connect to backend: {$host}:{$port}");
        }

        $this->backendConnections[$cacheKey] = $client;

        return $client;
    }

    /**
     * Close backend connection
     */
    public function closeBackendConnection(string $databaseId, int $clientFd): void
    {
        $cacheKey = "backend:connection:{$databaseId}:{$clientFd}";

        if (isset($this->backendConnections[$cacheKey])) {
            $this->backendConnections[$cacheKey]->close();
            unset($this->backendConnections[$cacheKey]);
        }
    }

    /**
     * Get or create the query parser instance (lazy initialization)
     */
    protected function getQueryParser(): QueryParser
    {
        if ($this->queryParser === null) {
            $this->queryParser = new QueryParser();
        }

        return $this->queryParser;
    }

    /**
     * Extract raw query text from a protocol packet
     *
     * @param  string  $data  Raw protocol message bytes
     * @return string SQL query text
     */
    protected function extractQueryText(string $data): string
    {
        if ($this->getProtocol() === QueryParser::PROTOCOL_POSTGRESQL) {
            if (\strlen($data) < 6 || $data[0] !== 'Q') {
                return '';
            }
            $query = \substr($data, 5);
            $nullPos = \strpos($query, "\x00");
            if ($nullPos !== false) {
                $query = \substr($query, 0, $nullPos);
            }

            return $query;
        }

        // MySQL
        if (\strlen($data) < 5 || \ord($data[4]) !== 0x03) {
            return '';
        }

        return \substr($data, 5);
    }

    /**
     * Route to a read replica backend
     *
     * @throws ResolverException
     */
    protected function routeRead(string $resourceId): ConnectionResult
    {
        /** @var ReadWriteResolver $resolver */
        $resolver = $this->resolver;

        try {
            $result = $resolver->resolveRead($resourceId);
            $endpoint = $result->endpoint;

            if (empty($endpoint)) {
                throw new ResolverException(
                    "Resolver returned empty read endpoint for: {$resourceId}",
                    ResolverException::NOT_FOUND
                );
            }

            if (!$this->skipValidation) {
                $this->validateEndpoint($endpoint);
            }

            return new ConnectionResult(
                endpoint: $endpoint,
                protocol: $this->getProtocol(),
                metadata: \array_merge(['cached' => false, 'route' => 'read'], $result->metadata)
            );
        } catch (\Exception $e) {
            $this->stats['routing_errors']++;
            throw $e;
        }
    }

    /**
     * Route to the primary/writer backend
     *
     * @throws ResolverException
     */
    protected function routeWrite(string $resourceId): ConnectionResult
    {
        /** @var ReadWriteResolver $resolver */
        $resolver = $this->resolver;

        try {
            $result = $resolver->resolveWrite($resourceId);
            $endpoint = $result->endpoint;

            if (empty($endpoint)) {
                throw new ResolverException(
                    "Resolver returned empty write endpoint for: {$resourceId}",
                    ResolverException::NOT_FOUND
                );
            }

            if (!$this->skipValidation) {
                $this->validateEndpoint($endpoint);
            }

            return new ConnectionResult(
                endpoint: $endpoint,
                protocol: $this->getProtocol(),
                metadata: \array_merge(['cached' => false, 'route' => 'write'], $result->metadata)
            );
        } catch (\Exception $e) {
            $this->stats['routing_errors']++;
            throw $e;
        }
    }
}
