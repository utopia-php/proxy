<?php

namespace Utopia\Proxy;

/**
 * SQL Query Parser for Read/Write Split Routing
 *
 * Classifies database protocol messages as READ, WRITE, TRANSACTION, or UNKNOWN
 * to enable routing queries to appropriate primary/replica backends.
 *
 * Supports PostgreSQL wire protocol and MySQL client protocol.
 *
 * Performance: Uses byte-level checks and simple string operations (no regex).
 * Designed to run on every packet with sub-microsecond overhead.
 */
class QueryParser
{
    /**
     * Query classification constants
     */
    public const READ = 'read';

    public const WRITE = 'write';

    public const TRANSACTION = 'transaction';

    public const UNKNOWN = 'unknown';

    /**
     * Protocol constants
     */
    public const PROTOCOL_POSTGRESQL = 'postgresql';

    public const PROTOCOL_MYSQL = 'mysql';

    /**
     * MySQL command byte constants
     */
    private const MYSQL_COM_QUERY = 0x03;

    private const MYSQL_COM_STMT_PREPARE = 0x16;

    private const MYSQL_COM_STMT_EXECUTE = 0x17;

    private const MYSQL_COM_STMT_CLOSE = 0x19;

    private const MYSQL_COM_STMT_RESET = 0x1A;

    private const MYSQL_COM_STMT_SEND_LONG_DATA = 0x18;

    /**
     * Read keywords lookup (uppercase)
     *
     * @var array<string, true>
     */
    private const READ_KEYWORDS = [
        'SELECT' => true,
        'SHOW' => true,
        'DESCRIBE' => true,
        'DESC' => true,
        'EXPLAIN' => true,
        'TABLE' => true,
        'VALUES' => true,
    ];

    /**
     * Write keywords lookup (uppercase)
     *
     * @var array<string, true>
     */
    private const WRITE_KEYWORDS = [
        'INSERT' => true,
        'UPDATE' => true,
        'DELETE' => true,
        'CREATE' => true,
        'DROP' => true,
        'ALTER' => true,
        'TRUNCATE' => true,
        'GRANT' => true,
        'REVOKE' => true,
        'LOCK' => true,
        'CALL' => true,
        'DO' => true,
    ];

    /**
     * Transaction keywords lookup (uppercase)
     *
     * @var array<string, true>
     */
    private const TRANSACTION_KEYWORDS = [
        'BEGIN' => true,
        'START' => true,
        'COMMIT' => true,
        'ROLLBACK' => true,
        'SAVEPOINT' => true,
        'RELEASE' => true,
        'SET' => true,
    ];

    /**
     * Parse a protocol message and classify it
     *
     * @param  string  $data  Raw protocol message bytes
     * @param  string  $protocol  One of PROTOCOL_POSTGRESQL or PROTOCOL_MYSQL
     * @return string One of READ, WRITE, TRANSACTION, or UNKNOWN
     */
    public function parse(string $data, string $protocol): string
    {
        if ($protocol === self::PROTOCOL_POSTGRESQL) {
            return $this->parsePostgreSQL($data);
        }

        return $this->parseMySQL($data);
    }

    /**
     * Parse PostgreSQL wire protocol message
     *
     * Wire protocol message format:
     * - Byte 0: Message type character
     * - Bytes 1-4: Length (big-endian int32, includes self but not type byte)
     * - Bytes 5+: Message body
     *
     * Query message ('Q'): body is null-terminated SQL string
     * Parse message ('P'): prepared statement - route to primary
     * Bind message ('B'): parameter binding - route to primary
     * Execute message ('E'): execute prepared - route to primary
     */
    private function parsePostgreSQL(string $data): string
    {
        $len = \strlen($data);
        if ($len < 6) {
            return self::UNKNOWN;
        }

        $type = $data[0];

        // Simple Query protocol
        if ($type === 'Q') {
            // Bytes 1-4: message length (big-endian), bytes 5+: query string (null-terminated)
            $query = \substr($data, 5);

            // Strip null terminator if present
            $nullPos = \strpos($query, "\x00");
            if ($nullPos !== false) {
                $query = \substr($query, 0, $nullPos);
            }

            return $this->classifySQL($query);
        }

        // Extended Query protocol messages - always route to primary for safety
        // 'P' = Parse, 'B' = Bind, 'E' = Execute, 'D' = Describe (extended), 'H' = Flush, 'S' = Sync
        if ($type === 'P' || $type === 'B' || $type === 'E') {
            return self::WRITE;
        }

        return self::UNKNOWN;
    }

    /**
     * Parse MySQL client protocol message
     *
     * Packet format:
     * - Bytes 0-2: Payload length (little-endian 3-byte int)
     * - Byte 3: Sequence ID
     * - Byte 4: Command type
     * - Bytes 5+: Command payload
     *
     * COM_QUERY (0x03): followed by query string
     * COM_STMT_PREPARE (0x16): prepared statement - route to primary
     * COM_STMT_EXECUTE (0x17): execute prepared - route to primary
     */
    private function parseMySQL(string $data): string
    {
        $len = \strlen($data);
        if ($len < 5) {
            return self::UNKNOWN;
        }

        $command = \ord($data[4]);

        // COM_QUERY: classify the SQL text
        if ($command === self::MYSQL_COM_QUERY) {
            $query = \substr($data, 5);

            return $this->classifySQL($query);
        }

        // Prepared statement commands - always route to primary
        if (
            $command === self::MYSQL_COM_STMT_PREPARE
            || $command === self::MYSQL_COM_STMT_EXECUTE
            || $command === self::MYSQL_COM_STMT_SEND_LONG_DATA
        ) {
            return self::WRITE;
        }

        // COM_STMT_CLOSE and COM_STMT_RESET are maintenance - route to primary
        if ($command === self::MYSQL_COM_STMT_CLOSE || $command === self::MYSQL_COM_STMT_RESET) {
            return self::WRITE;
        }

        return self::UNKNOWN;
    }

    /**
     * Classify a SQL query string by its leading keyword
     *
     * Handles:
     * - Leading whitespace (spaces, tabs, newlines)
     * - SQL comments: line comments (--) and block comments
     * - Mixed case keywords
     * - COPY ... TO (read) vs COPY ... FROM (write)
     * - CTE: WITH ... SELECT (read) vs WITH ... INSERT/UPDATE/DELETE (write)
     */
    public function classifySQL(string $query): string
    {
        $keyword = $this->extractKeyword($query);

        if ($keyword === '') {
            return self::UNKNOWN;
        }

        // Fast hash-based lookup
        if (isset(self::READ_KEYWORDS[$keyword])) {
            return self::READ;
        }

        if (isset(self::WRITE_KEYWORDS[$keyword])) {
            return self::WRITE;
        }

        if (isset(self::TRANSACTION_KEYWORDS[$keyword])) {
            return self::TRANSACTION;
        }

        // COPY requires directional analysis: COPY ... TO = read, COPY ... FROM = write
        if ($keyword === 'COPY') {
            return $this->classifyCopy($query);
        }

        // WITH (CTE): look at the final statement keyword
        if ($keyword === 'WITH') {
            return $this->classifyCTE($query);
        }

        return self::UNKNOWN;
    }

    /**
     * Extract the first SQL keyword from a query string
     *
     * Skips leading whitespace and SQL comments efficiently.
     * Returns the keyword in uppercase for classification.
     */
    public function extractKeyword(string $query): string
    {
        $len = \strlen($query);
        $pos = 0;

        // Skip leading whitespace and comments
        while ($pos < $len) {
            $c = $query[$pos];

            // Skip whitespace
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" || $c === "\f") {
                $pos++;

                continue;
            }

            // Skip line comments: -- ...
            if ($c === '-' && ($pos + 1) < $len && $query[$pos + 1] === '-') {
                $pos += 2;
                while ($pos < $len && $query[$pos] !== "\n") {
                    $pos++;
                }

                continue;
            }

            // Skip block comments: /* ... */
            if ($c === '/' && ($pos + 1) < $len && $query[$pos + 1] === '*') {
                $pos += 2;
                while ($pos < ($len - 1)) {
                    if ($query[$pos] === '*' && $query[$pos + 1] === '/') {
                        $pos += 2;

                        break;
                    }
                    $pos++;
                }

                continue;
            }

            break;
        }

        if ($pos >= $len) {
            return '';
        }

        // Read keyword until whitespace, '(', ';', or end
        $start = $pos;
        while ($pos < $len) {
            $c = $query[$pos];
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" || $c === '(' || $c === ';') {
                break;
            }
            $pos++;
        }

        if ($pos === $start) {
            return '';
        }

        return \strtoupper(\substr($query, $start, $pos - $start));
    }

    /**
     * Classify COPY statement direction
     *
     * COPY ... TO stdout/file = READ (export)
     * COPY ... FROM stdin/file = WRITE (import)
     * Default to WRITE for safety
     */
    private function classifyCopy(string $query): string
    {
        // Case-insensitive search for ' TO ' and ' FROM ' without uppercasing the full query
        $toPos = \stripos($query, ' TO ');
        $fromPos = \stripos($query, ' FROM ');

        if ($toPos !== false && ($fromPos === false || $toPos < $fromPos)) {
            return self::READ;
        }

        return self::WRITE;
    }

    /**
     * Classify CTE (WITH ... AS (...) SELECT/INSERT/UPDATE/DELETE ...)
     *
     * After the CTE definitions (WITH name AS (...), ...), the first
     * read/write keyword at parenthesis depth 0 is the main statement.
     * WITH ... SELECT = READ, WITH ... INSERT/UPDATE/DELETE = WRITE
     * Default to READ since most CTEs are used with SELECT.
     */
    private function classifyCTE(string $query): string
    {
        $len = \strlen($query);
        $pos = 0;
        $depth = 0;
        $seenParen = false;

        // Scan through the query tracking parenthesis depth.
        // Once we've exited a parenthesized CTE definition back to depth 0,
        // the first read/write keyword is the main statement.
        while ($pos < $len) {
            $c = $query[$pos];

            if ($c === '(') {
                $depth++;
                $seenParen = true;
                $pos++;

                continue;
            }

            if ($c === ')') {
                $depth--;
                $pos++;

                continue;
            }

            // Only look for keywords at depth 0, after we've seen at least one CTE block
            if ($depth === 0 && $seenParen && ($c >= 'A' && $c <= 'Z' || $c >= 'a' && $c <= 'z')) {
                // Read a word
                $wordStart = $pos;
                while ($pos < $len) {
                    $ch = $query[$pos];
                    if (($ch >= 'A' && $ch <= 'Z') || ($ch >= 'a' && $ch <= 'z') || ($ch >= '0' && $ch <= '9') || $ch === '_') {
                        $pos++;
                    } else {
                        break;
                    }
                }
                $word = \strtoupper(\substr($query, $wordStart, $pos - $wordStart));

                // First read/write keyword at depth 0 after CTE block is the main statement
                if (isset(self::READ_KEYWORDS[$word])) {
                    return self::READ;
                }

                if (isset(self::WRITE_KEYWORDS[$word])) {
                    return self::WRITE;
                }

                continue;
            }

            $pos++;
        }

        // Default CTEs to READ (most common usage)
        return self::READ;
    }
}
