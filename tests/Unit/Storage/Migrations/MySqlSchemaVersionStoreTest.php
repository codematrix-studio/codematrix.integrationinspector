<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Tests\Storage\Migrations;

use CodeMatrix\IntegrationInspector\Storage\Migrations\MySqlSchemaVersionStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MySqlSchemaVersionStoreTest extends TestCase
{
    public function testEnsureStorageCreatesVersionTable(): void
    {
        $connection = new FakeVersionConnection();
        $store = new MySqlSchemaVersionStore($connection);

        $store->ensureStorage();

        self::assertCount(1, $connection->executed);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS b_cmi_schema_version', $connection->executed[0]);
        self::assertStringContainsString('SCHEMA_VERSION INT UNSIGNED NOT NULL', $connection->executed[0]);
    }

    public function testMissingVersionRowMeansInitialVersionZero(): void
    {
        $connection = new FakeVersionConnection();
        $connection->fetchRow = false;
        $store = new MySqlSchemaVersionStore($connection);

        self::assertSame(0, $store->getVersion());
        self::assertStringContainsString("COMPONENT = 'integrationinspector'", $connection->selected[0]);
    }

    public function testReadsStoredVersion(): void
    {
        $connection = new FakeVersionConnection();
        $connection->fetchRow = ['SCHEMA_VERSION' => '4'];
        $store = new MySqlSchemaVersionStore($connection);

        self::assertSame(4, $store->getVersion());
    }

    public function testWritesVersionWithIdempotentUpsert(): void
    {
        $connection = new FakeVersionConnection();
        $store = new MySqlSchemaVersionStore($connection);

        $store->setVersion(3);

        self::assertCount(1, $connection->executed);
        self::assertStringContainsString("VALUES ('integrationinspector', 3, NOW())", $connection->executed[0]);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $connection->executed[0]);
    }

    public function testInvalidStoredVersionIsRejected(): void
    {
        $connection = new FakeVersionConnection();
        $connection->fetchRow = ['SCHEMA_VERSION' => 'not-a-number'];
        $store = new MySqlSchemaVersionStore($connection);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stored CodeMatrix schema version is invalid');

        $store->getVersion();
    }

    public function testFractionalStoredVersionIsRejected(): void
    {
        $connection = new FakeVersionConnection();
        $connection->fetchRow = ['SCHEMA_VERSION' => '1.5'];
        $store = new MySqlSchemaVersionStore($connection);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stored CodeMatrix schema version is invalid');

        $store->getVersion();
    }

    public function testNegativeVersionCannotBeWritten(): void
    {
        $store = new MySqlSchemaVersionStore(new FakeVersionConnection());

        $this->expectException(\InvalidArgumentException::class);
        $store->setVersion(-1);
    }
}

final class FakeVersionConnection
{
    public array $executed = [];
    public array $selected = [];
    public mixed $fetchRow = false;

    public function queryExecute(string $sql): void
    {
        $this->executed[] = $sql;
    }

    public function query(string $sql): FakeVersionResult
    {
        $this->selected[] = $sql;

        return new FakeVersionResult($this->fetchRow);
    }
}

final class FakeVersionResult
{
    public function __construct(private mixed $row)
    {
    }

    public function fetch(): mixed
    {
        return $this->row;
    }
}
