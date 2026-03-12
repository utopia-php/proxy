<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Adapter\TCP as TCPAdapter;
use Utopia\Proxy\Protocol;
use Utopia\Proxy\Resolver\Exception as ResolverException;
use Utopia\Query\Type as QueryType;

class TCPAdapterExtendedTest extends TestCase
{
    protected MockResolver $resolver;

    protected MockReadWriteResolver $rwResolver;

    protected function setUp(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to run adapter tests.');
        }

        $this->resolver = new MockResolver();
        $this->rwResolver = new MockReadWriteResolver();
    }

    public function testProtocolForPostgresPort(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);
        $this->assertSame(Protocol::PostgreSQL, $adapter->getProtocol());
    }

    public function testProtocolForMysqlPort(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 3306);
        $this->assertSame(Protocol::MySQL, $adapter->getProtocol());
    }

    public function testProtocolForMongoPort(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 27017);
        $this->assertSame(Protocol::MongoDB, $adapter->getProtocol());
    }

    public function testProtocolThrowsForUnsupportedPort(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 8080);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unsupported protocol on port: 8080');

        $adapter->getProtocol();
    }

    public function testPortProperty(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);
        $this->assertSame(5432, $adapter->port);
    }

    public function testNameIsAlwaysTCP(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);
        $this->assertSame('TCP', $adapter->getName());
    }

    public function testDescription(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);
        $this->assertStringContainsString('PostgreSQL', $adapter->getDescription());
        $this->assertStringContainsString('MySQL', $adapter->getDescription());
        $this->assertStringContainsString('MongoDB', $adapter->getDescription());
    }

    public function testSetConnectTimeoutReturnsSelf(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);
        $result = $adapter->setConnectTimeout(10.0);
        $this->assertSame($adapter, $result);
    }

    public function testSetReadWriteSplitReturnsSelf(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);
        $result = $adapter->setReadWriteSplit(true);
        $this->assertSame($adapter, $result);
    }

    public function testPostgresParseAlphanumericId(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);
        $data = "user\x00appwrite\x00database\x00db-ABCdef789\x00";

        $this->assertSame('ABCdef789', $adapter->parseDatabaseId($data, 1));
    }

    public function testPostgresParseIdWithDotSuffix(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);
        $data = "user\x00appwrite\x00database\x00db-abc123.us-east-1.example.com\x00";

        // Parsing stops at the dot
        $this->assertSame('abc123', $adapter->parseDatabaseId($data, 1));
    }

    public function testPostgresParseIdWithLeadingFields(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);
        // Extra key-value pairs before "database"
        $data = "user\x00admin\x00options\x00-c\x00database\x00db-xyz\x00\x00";

        $this->assertSame('xyz', $adapter->parseDatabaseId($data, 1));
    }

    public function testPostgresRejectsMissingDatabaseMarker(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid PostgreSQL database name');

        $adapter->parseDatabaseId("user\x00appwrite\x00", 1);
    }

    public function testPostgresRejectsMissingNullTerminator(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid PostgreSQL database name');

        // No null byte after the database name
        $adapter->parseDatabaseId("database\x00db-abc123", 1);
    }

    public function testPostgresRejectsNonDbPrefix(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid PostgreSQL database name');

        $adapter->parseDatabaseId("database\x00mydb\x00", 1);
    }

    public function testPostgresRejectsEmptyIdAfterDbPrefix(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid PostgreSQL database name');

        $adapter->parseDatabaseId("database\x00db-\x00", 1);
    }

    public function testPostgresRejectsSpecialCharactersInId(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid PostgreSQL database name');

        $adapter->parseDatabaseId("database\x00db-abc@123\x00", 1);
    }

    public function testPostgresRejectsHyphenInId(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid PostgreSQL database name');

        $adapter->parseDatabaseId("database\x00db-abc-123\x00", 1);
    }

    public function testPostgresRejectsUnderscoreInId(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid PostgreSQL database name');

        $adapter->parseDatabaseId("database\x00db-abc_123\x00", 1);
    }

    public function testPostgresParsesSingleCharId(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);
        $data = "database\x00db-x\x00";

        $this->assertSame('x', $adapter->parseDatabaseId($data, 1));
    }

    public function testPostgresParsesNumericOnlyId(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);
        $data = "database\x00db-123456\x00";

        $this->assertSame('123456', $adapter->parseDatabaseId($data, 1));
    }

    public function testMysqlParseAlphanumericId(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 3306);
        $data = "\x00\x00\x00\x00\x02db-ABCdef789";

        $this->assertSame('ABCdef789', $adapter->parseDatabaseId($data, 1));
    }

    public function testMysqlParseIdWithNullTerminator(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 3306);
        $data = "\x00\x00\x00\x00\x02db-abc123\x00extra";

        $this->assertSame('abc123', $adapter->parseDatabaseId($data, 1));
    }

    public function testMysqlParseIdWithDotSuffix(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 3306);
        $data = "\x00\x00\x00\x00\x02db-abc123.us-east-1";

        $this->assertSame('abc123', $adapter->parseDatabaseId($data, 1));
    }

    public function testMysqlRejectsTooShortPacket(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 3306);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid MySQL database name');

        $adapter->parseDatabaseId("\x00\x00\x00\x00\x02", 1);
    }

    public function testMysqlRejectsWrongCommandByte(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 3306);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid MySQL database name');

        // Command byte 0x03 instead of 0x02
        $adapter->parseDatabaseId("\x00\x00\x00\x00\x03db-abc123", 1);
    }

    public function testMysqlRejectsNonDbPrefix(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 3306);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid MySQL database name');

        $adapter->parseDatabaseId("\x00\x00\x00\x00\x02mydb", 1);
    }

    public function testMysqlRejectsEmptyIdAfterDbPrefix(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 3306);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid MySQL database name');

        $adapter->parseDatabaseId("\x00\x00\x00\x00\x02db-", 1);
    }

    public function testMysqlRejectsSpecialCharactersInId(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 3306);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid MySQL database name');

        $adapter->parseDatabaseId("\x00\x00\x00\x00\x02db-abc!123", 1);
    }

    public function testMysqlRejectsEmptyPacket(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 3306);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid MySQL database name');

        $adapter->parseDatabaseId('', 1);
    }

    public function testMongoParsesDatabaseId(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 27017);

        // Build a MongoDB OP_MSG-like packet with $db field
        // "$db\0" marker followed by BSON string length (little-endian) and the string
        $dbName = "db-abc123\x00"; // null-terminated
        $strLen = pack('V', strlen($dbName)); // 10 as 4 bytes LE
        $data = str_repeat("\x00", 21) . "\$db\x00" . $strLen . $dbName;

        $this->assertSame('abc123', $adapter->parseDatabaseId($data, 1));
    }

    public function testMongoParsesIdWithDotSuffix(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 27017);

        $dbName = "db-xyz789.collection\x00";
        $strLen = pack('V', strlen($dbName));
        $data = str_repeat("\x00", 21) . "\$db\x00" . $strLen . $dbName;

        $this->assertSame('xyz789', $adapter->parseDatabaseId($data, 1));
    }

    public function testMongoRejectsMissingDbMarker(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 27017);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid MongoDB database name');

        $adapter->parseDatabaseId(str_repeat("\x00", 50), 1);
    }

    public function testMongoRejectsNonDbPrefix(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 27017);

        $dbName = "mydb\x00";
        $strLen = pack('V', strlen($dbName));
        $data = "\$db\x00" . $strLen . $dbName;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid MongoDB database name');

        $adapter->parseDatabaseId($data, 1);
    }

    public function testMongoRejectsEmptyIdAfterDbPrefix(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 27017);

        $dbName = "db-\x00";
        $strLen = pack('V', strlen($dbName));
        $data = "\$db\x00" . $strLen . $dbName;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid MongoDB database name');

        $adapter->parseDatabaseId($data, 1);
    }

    public function testMongoRejectsTruncatedData(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 27017);

        // "$db\0" marker but not enough bytes for the string length
        $data = "\$db\x00\x0A";

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid MongoDB database name');

        $adapter->parseDatabaseId($data, 1);
    }

    public function testMongoRejectsSpecialCharactersInId(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 27017);

        $dbName = "db-abc@123\x00";
        $strLen = pack('V', strlen($dbName));
        $data = "\$db\x00" . $strLen . $dbName;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid MongoDB database name');

        $adapter->parseDatabaseId($data, 1);
    }

    public function testMongoParsesAlphanumericId(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 27017);

        $dbName = "db-ABCdef789\x00";
        $strLen = pack('V', strlen($dbName));
        $data = "\$db\x00" . $strLen . $dbName;

        $this->assertSame('ABCdef789', $adapter->parseDatabaseId($data, 1));
    }

    public function testClearConnectionStateForNonExistentFd(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);
        $adapter->setReadWriteSplit(true);

        // Should not throw
        $adapter->clearConnectionState(999);
        $this->assertFalse($adapter->isConnectionPinned(999));
    }

    public function testIsConnectionPinnedDefaultFalse(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);
        $this->assertFalse($adapter->isConnectionPinned(1));
    }

    public function testRouteQueryReadThrowsWhenNoReadEndpoint(): void
    {
        $this->rwResolver->setEndpoint('primary.db:5432');
        $this->rwResolver->setWriteEndpoint('primary.db:5432');
        // No read endpoint set

        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);
        $adapter->setSkipValidation(true);

        $this->expectException(ResolverException::class);

        $adapter->routeQuery('test-db', QueryType::Read);
    }

    public function testRouteQueryWriteThrowsWhenNoWriteEndpoint(): void
    {
        $this->rwResolver->setEndpoint('primary.db:5432');
        $this->rwResolver->setReadEndpoint('replica.db:5432');
        // No write endpoint set

        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);
        $adapter->setSkipValidation(true);

        $this->expectException(ResolverException::class);

        $adapter->routeQuery('test-db', QueryType::Write);
    }

    public function testRouteQueryReadEmptyEndpointThrows(): void
    {
        $this->rwResolver->setEndpoint('primary.db:5432');
        $this->rwResolver->setReadEndpoint('');
        $this->rwResolver->setWriteEndpoint('primary.db:5432');

        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);
        $adapter->setSkipValidation(true);

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('empty read endpoint');

        $adapter->routeQuery('test-db', QueryType::Read);
    }

    public function testRouteQueryWriteEmptyEndpointThrows(): void
    {
        $this->rwResolver->setEndpoint('primary.db:5432');
        $this->rwResolver->setReadEndpoint('replica.db:5432');
        $this->rwResolver->setWriteEndpoint('');

        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);
        $adapter->setSkipValidation(true);

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('empty write endpoint');

        $adapter->routeQuery('test-db', QueryType::Write);
    }

    public function testRouteQueryReadIncrementsErrorStatsOnFailure(): void
    {
        $this->rwResolver->setEndpoint('primary.db:5432');
        // No read endpoint — will throw

        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);
        $adapter->setSkipValidation(true);

        try {
            $adapter->routeQuery('test-db', QueryType::Read);
            $this->fail('Expected exception');
        } catch (ResolverException $e) {
            // expected
        }

        $stats = $adapter->getStats();
        $this->assertSame(1, $stats['routingErrors']);
    }

    public function testRouteQueryWriteIncrementsErrorStatsOnFailure(): void
    {
        $this->rwResolver->setEndpoint('primary.db:5432');
        // No write endpoint — will throw

        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);
        $adapter->setSkipValidation(true);

        try {
            $adapter->routeQuery('test-db', QueryType::Write);
            $this->fail('Expected exception');
        } catch (ResolverException $e) {
            // expected
        }

        $stats = $adapter->getStats();
        $this->assertSame(1, $stats['routingErrors']);
    }

    public function testRouteQueryReadMetadataIncludesRouteType(): void
    {
        $this->rwResolver->setReadEndpoint('replica.db:5432');
        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);
        $adapter->setSkipValidation(true);

        $result = $adapter->routeQuery('test-db', QueryType::Read);
        $this->assertSame('read', $result->metadata['route']);
        $this->assertFalse($result->metadata['cached']);
    }

    public function testRouteQueryWriteMetadataIncludesRouteType(): void
    {
        $this->rwResolver->setWriteEndpoint('primary.db:5432');
        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);
        $adapter->setSkipValidation(true);

        $result = $adapter->routeQuery('test-db', QueryType::Write);
        $this->assertSame('write', $result->metadata['route']);
        $this->assertFalse($result->metadata['cached']);
    }

    public function testRouteQueryReadPreservesResolverMetadata(): void
    {
        $this->rwResolver->setReadEndpoint('replica.db:5432');
        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);
        $adapter->setSkipValidation(true);

        $result = $adapter->routeQuery('test-db', QueryType::Read);
        $this->assertSame('test-db', $result->metadata['resourceId']);
    }

    public function testRouteQueryReadValidatesEndpoint(): void
    {
        $this->rwResolver->setReadEndpoint('10.0.0.1:5432');
        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);
        // Validation is ON (default)

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('private/reserved IP');

        $adapter->routeQuery('test-db', QueryType::Read);
    }

    public function testRouteQueryWriteValidatesEndpoint(): void
    {
        $this->rwResolver->setWriteEndpoint('192.168.1.1:5432');
        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('private/reserved IP');

        $adapter->routeQuery('test-db', QueryType::Write);
    }

    public function testRouteQuerySkipsValidationWhenDisabled(): void
    {
        $this->rwResolver->setReadEndpoint('10.0.0.1:5432');
        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);
        $adapter->setSkipValidation(true);

        $result = $adapter->routeQuery('test-db', QueryType::Read);
        $this->assertSame('10.0.0.1:5432', $result->endpoint);
    }
}
