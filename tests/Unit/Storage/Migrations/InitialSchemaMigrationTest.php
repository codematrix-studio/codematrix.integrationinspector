<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Tests\Storage\Migrations;

use CodeMatrix\IntegrationInspector\Storage\Migrations\InitialSchemaMigration;
use CodeMatrix\IntegrationInspector\Storage\Migrations\InitialSchemaVerifier;
use CodeMatrix\IntegrationInspector\Storage\Migrations\MigrationLock;
use CodeMatrix\IntegrationInspector\Storage\Migrations\MigrationRunner;
use CodeMatrix\IntegrationInspector\Storage\Migrations\SchemaVersionStore;
use CodeMatrix\IntegrationInspector\Storage\SchemaInstaller;
use PHPUnit\Framework\TestCase;

final class InitialSchemaMigrationTest extends TestCase
{
    public function testFreshDatabaseCreatesInitialTablesAndVerifiesSchema(): void
    {
        $connection = new FakeInitialSchemaConnection();
        $verifier = new FakeInitialSchemaVerifier(false, true);
        $migration = new InitialSchemaMigration(new SchemaInstaller($connection), $verifier);

        $migration->up();

        self::assertSame(3, count($connection->queries));
        self::assertTrue($migration->isApplied());
    }

    public function testExistingMatchingSchemaIsAcceptedAsBaselineWithoutDdl(): void
    {
        $connection = new FakeInitialSchemaConnection();
        $verifier = new FakeInitialSchemaVerifier(true, true);
        $migration = new InitialSchemaMigration(new SchemaInstaller($connection), $verifier);

        $migration->up();

        self::assertSame([], $connection->queries);
        self::assertTrue($migration->isApplied());
    }

    public function testPartialExistingSchemaIsNotAutomaticallyModified(): void
    {
        $connection = new FakeInitialSchemaConnection();
        $verifier = new FakeInitialSchemaVerifier(true, false);
        $migration = new InitialSchemaMigration(new SchemaInstaller($connection), $verifier);

        $migration->up();

        self::assertSame([], $connection->queries);
        self::assertFalse($migration->isApplied());
    }

    public function testRunnerRecordsMatchingExistingSchemaAsBaselineWithoutDdl(): void
    {
        $connection = new FakeInitialSchemaConnection();
        $verifier = new FakeInitialSchemaVerifier(true, true);
        $store = new InitialSchemaVersionStore();
        $migration = new InitialSchemaMigration(new SchemaInstaller($connection), $verifier);
        $runner = new MigrationRunner($store, [$migration], new InitialSchemaMigrationLock());

        self::assertSame(1, $runner->run());
        self::assertSame([], $connection->queries);
        self::assertSame(1, $store->version);
        self::assertSame([1], $store->writtenVersions);
    }

    public function testRunnerDoesNotRecordVersionForPartialExistingSchema(): void
    {
        $connection = new FakeInitialSchemaConnection();
        $verifier = new FakeInitialSchemaVerifier(true, false);
        $store = new InitialSchemaVersionStore();
        $migration = new InitialSchemaMigration(new SchemaInstaller($connection), $verifier);
        $runner = new MigrationRunner($store, [$migration], new InitialSchemaMigrationLock());

        try {
            $runner->run();
            self::fail('Expected partial schema to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'did not produce its expected schema',
                $exception->getPrevious()?->getMessage()
            );
        }

        self::assertSame([], $connection->queries);
        self::assertSame(0, $store->version);
        self::assertSame([], $store->writtenVersions);
    }
}

final class FakeInitialSchemaConnection
{
    public array $queries = [];

    public function queryExecute(string $sql): void
    {
        $this->queries[] = $sql;
    }
}

final class FakeInitialSchemaVerifier implements InitialSchemaVerifier
{
    public function __construct(
        private bool $hasTables,
        private bool $matches
    ) {
    }

    public function hasAnyModuleTables(): bool
    {
        return $this->hasTables;
    }

    public function matchesInitialSchema(): bool
    {
        return $this->matches;
    }
}

final class InitialSchemaVersionStore implements SchemaVersionStore
{
    public int $version = 0;
    public array $writtenVersions = [];

    public function ensureStorage(): void
    {
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): void
    {
        $this->version = $version;
        $this->writtenVersions[] = $version;
    }
}

final class InitialSchemaMigrationLock implements MigrationLock
{
    public function acquire(): bool
    {
        return true;
    }

    public function release(): void
    {
    }
}
